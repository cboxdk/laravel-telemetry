#!/usr/bin/env bash
#
# The package's cost per request, measured rather than reasoned.
#
#   loadtest/run.sh --target fpm                  # the rig that can see it
#   loadtest/run.sh --target fpm redis otlp
#   loadtest/run.sh --target fpm redis otlp spool
#   loadtest/run.sh --target builtin              # zero infrastructure, see below
#
# Every scenario runs twice — once with TELEMETRY_ENABLED=0, once with
# it on — and the delta is printed. The delta is the package. Absolute
# throughput is a property of the rig and should not be quoted as a
# capacity figure anywhere.
#
# TARGETS
#
#   fpm      The production PHP base image — php-fpm and nginx with
#            opcache, and the cbox_telemetry extension available —
#            plus a real Redis and a real OTLP collector. Needs
#            `docker compose -f loadtest/docker-compose.yml up -d
#            --build` first, and `NATIVE=on` on that build to measure
#            what the native profiler costs. This is the target whose
#            numbers mean something.
#
#   builtin  PHP's built-in server. No infrastructure, and no opcache
#            or worker reuse either, so it re-bootstraps the framework
#            every request at ~18ms — which buries a sub-millisecond
#            delta in noise. Useful to check the harness runs at all;
#            useless for a number. It says so when it finishes.
set -euo pipefail

cd "$(dirname "$0")/.."

TARGET=fpm

while [[ "${1:-}" == --* ]]; do
  case "$1" in
    --target) TARGET="$2"; shift 2 ;;
    *) echo "unknown option: $1"; exit 1 ;;
  esac
done

STORE="${1:-array}"
EXPORTERS="${2:-}"
SPOOL=0
[[ "${3:-}" == "spool" ]] && SPOOL=1

PORT="${LOADTEST_PORT:-8931}"
DURATION="${LOADTEST_DURATION:-20s}"
# Two, not thirty-two. Thirty-two saturates a laptop's cores against 32
# FPM workers and measures the queue: p50 was 19ms at -c 1 and 56ms at
# -c 16 on the same rig, with the package's own ~6ms somewhere inside
# that. Raise it to look at saturation behaviour, not at cost.
CONCURRENCY="${LOADTEST_CONCURRENCY:-2}"
ROUTES="flat work heavy"
COMPOSE="docker compose -f loadtest/docker-compose.yml"

command -v oha >/dev/null || { echo "oha is not installed: brew install oha"; exit 1; }

start_builtin() {
  TELEMETRY_ENABLED="$1" \
  TELEMETRY_STORE="$STORE" \
  TELEMETRY_EXPORTERS="$EXPORTERS" \
  TELEMETRY_OTLP_SPOOL="$SPOOL" \
  TELEMETRY_OTLP_ENDPOINT="${TELEMETRY_OTLP_ENDPOINT:-http://127.0.0.1:4318}" \
  TELEMETRY_TRACES_SAMPLE_RATE="${TELEMETRY_TRACES_SAMPLE_RATE:-1.0}" \
  php -S "127.0.0.1:$PORT" -t loadtest/public loadtest/public/index.php >/dev/null 2>&1 &
  BUILTIN_PID=$!
}

stop_builtin() {
  [[ -n "${BUILTIN_PID:-}" ]] || return 0
  kill "$BUILTIN_PID" 2>/dev/null || true
  wait "$BUILTIN_PID" 2>/dev/null || true
  BUILTIN_PID=
}

# Recreating only `app` keeps redis and the collector warm between the
# two halves, so the comparison differs in one variable.
start_fpm() {
  TELEMETRY_ENABLED="$1" \
  TELEMETRY_STORE="$STORE" \
  TELEMETRY_EXPORTERS="$EXPORTERS" \
  TELEMETRY_OTLP_SPOOL="$SPOOL" \
  TELEMETRY_TRACES_SAMPLE_RATE="${TELEMETRY_TRACES_SAMPLE_RATE:-1.0}" \
  $COMPOSE up -d --force-recreate --no-deps app >/dev/null 2>&1
}

wait_for_server() {
  for _ in $(seq 1 100); do
    curl -fsS "http://127.0.0.1:$PORT/flat" >/dev/null 2>&1 && { assert_target; return 0; }
    sleep 0.3
  done
  echo "nothing answering on $PORT."
  [[ "$TARGET" == "fpm" ]] && echo "  start the rig first: $COMPOSE up -d --build"
  exit 1
}

# Whatever answers on the port is not necessarily what we started.
#
# A `php -S` left over from a --target builtin run binds 127.0.0.1
# specifically, which beats the container's wildcard bind for loopback
# traffic — so every request went to a stale host process while this
# script dutifully recreated containers between arms. It produced a
# full results table, twenty thousand healthy requests, and an empty
# Redis, and nothing in the output suggested the container had not been
# touched. One header settles it.
assert_target() {
  local server
  server=$(curl -fsS -o /dev/null -D- "http://127.0.0.1:$PORT/flat" 2>/dev/null | tr -d '\r' | awk 'tolower($1)=="server:"{print tolower($2)}')

  case "$TARGET" in
    fpm)
      [[ "$server" == nginx* ]] && return 0
      echo "Something other than the container is answering on $PORT (Server: ${server:-none})."
      echo "  A leftover 'php -S' from --target builtin binds loopback and wins over the container."
      echo "  Find it with: lsof -nP -iTCP:$PORT -sTCP:LISTEN"
      exit 1
      ;;
    builtin)
      [[ -z "$server" ]] && return 0
      echo "Expected PHP's built-in server on $PORT but something else answered (Server: $server)."
      echo "  Stop the container rig first: $COMPOSE down"
      exit 1
      ;;
  esac
}

measure() {
  oha -z "$DURATION" -c "$CONCURRENCY" --no-tui --output-format json "http://127.0.0.1:$PORT/$1" \
    | python3 loadtest/summarise.py
}

# Results in files, not an associative array: macOS still ships bash
# 3.2, and a harness that only runs on a homebrew shell is one somebody
# will not run.
RESULTS="$(mktemp -d)"
trap 'rm -rf "$RESULTS"; stop_builtin' EXIT

# Interleaved, off/on/off/on, and this is the part that took two
# attempts to get right.
#
# Running both baselines first and the treatment last measured warm-up
# drift: the rig gets steadily faster over a session — page cache, the
# host settling — so the last round wins whatever it is testing. That
# design cheerfully reported that telemetry made requests 3.75ms
# FASTER, which is the same lie the old in-process benchmark told, in a
# new costume.
#
# Alternating puts the drift in both arms. The spread WITHIN an arm is
# then what the rig can resolve, and the difference BETWEEN the arms is
# the only thing left that varied on purpose.
ARMS="${LOADTEST_ARMS:-2}"
round=0

for pass in $(seq 1 "$ARMS"); do
  for state in 0 1; do
    if [[ "$TARGET" == "builtin" ]]; then start_builtin "$state"; else start_fpm "$state"; fi

    wait_for_server

    # Warm: opcache, the autoloader and the connection pool are not
    # what is being measured.
    for route in $ROUTES; do
      oha -z 3s -c 8 --no-tui --output-format quiet "http://127.0.0.1:$PORT/$route" >/dev/null 2>&1
    done

    for route in $ROUTES; do measure "$route" >> "$RESULTS/$route.$state"; done

    [[ "$TARGET" == "builtin" ]] && stop_builtin
    round=$((round + 1))
  done
done

printf '\ntarget=%s store=%s exporters=%s spool=%s   %s @ %s concurrent\n\n' \
  "$TARGET" "$STORE" "${EXPORTERS:-none}" "$SPOOL" "$DURATION" "$CONCURRENCY"
printf '%-8s %10s %10s %10s %10s\n' route 'off p50' 'on p50' 'delta' 'noise'

RESOLVED=0

for route in $ROUTES; do
  line=$(python3 loadtest/verdict.py "$RESULTS/$route.0" "$RESULTS/$route.1")
  read -r off on delta noise verdict <<<"$line"

  [[ "$verdict" == "resolved" ]] && RESOLVED=1

  printf '%-8s %10s %10s %10s %10s\n' "$route" "$off" "$on" "$delta" "±$noise"
done

if [[ "$RESOLVED" == "0" ]]; then
  cat <<'NOTE'

NOTHING HERE IS A RESULT. Every delta is inside this rig's own noise —
the two baseline columns disagree by as much as the change does — so a
negative number means variance, not that telemetry made requests
faster.

That is a property of the rig, not of the package. On Docker Desktop
the container filesystem alone costs tens of milliseconds a request,
which is two orders of magnitude above what is being looked for. Run
this on Linux with a local filesystem, raise LOADTEST_DURATION, or use
the per-operation figures in docs/production/performance.md, which
measure the package directly and do not have this problem.
NOTE
else
  cat <<'NOTE'

The delta is the package, where it exceeds the noise column. Absolute
throughput belongs to this rig — worker count, container CPU limits,
the host — and is not a capacity figure for anything.

Measure at LOW concurrency. The default 32 saturates a laptop's cores
against 32 FPM workers, and then the latency is queueing rather than
work — p50 went 19ms at -c 1 to 56ms at -c 16 with nothing else
changed. LOADTEST_CONCURRENCY=2 is where the package is visible.

Still not measured: the metric store under a whole fleet's writes
rather than one host's. For per-phase attribution — boot versus
request versus per-operation — the in-process harness is better than
this one, because it has no queueing or network in it at all.
NOTE
fi
