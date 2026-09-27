# Load test

What this package costs a request, measured on the image production
runs, against a real Redis and a real OTLP collector.

```sh
docker compose -f loadtest/docker-compose.yml up -d --build
loadtest/run.sh --target fpm                    # in-process only
loadtest/run.sh --target fpm redis otlp         # real writes, real collector
loadtest/run.sh --target fpm redis otlp spool   # and the spool
```

To measure the native extension, which the base image ships but does
not load:

```sh
NATIVE=on   docker compose -f loadtest/docker-compose.yml up -d --build
NATIVE=auto docker compose -f loadtest/docker-compose.yml up -d --build
```

## How it decides whether it measured anything

Each scenario runs **interleaved**, off/on/off/on. That matters more
than it sounds: the first version ran both baselines first and the
treatment last, and the rig gets steadily faster over a session — page
cache, the host settling — so the last round won whatever it was
testing. It reported that telemetry made requests 3.75ms *faster*.

Alternating puts the drift in both arms. The spread *within* an arm is
then the smallest difference the rig can resolve, and it is printed as
the noise column. A delta below it is not a small effect; it is no
measurement, and the run says so in as many words rather than leaving
a table to be misread.

Raise `LOADTEST_ARMS` for more passes and a tighter noise floor.

It also checks that the thing answering on the port is the thing it
started. A `php -S` left over from a `--target builtin` run binds
127.0.0.1 specifically, which beats the container's wildcard bind for
loopback traffic — so every request went to a stale host process while
the script dutifully recreated containers between arms. That produced a
full results table, twenty thousand healthy requests and an empty Redis,
and nothing in the output suggested the container had never been touched.
One `Server:` header settles it, and the run now stops rather than
reporting.

## The stalling collector

A collector that refuses is the easy case — it answers immediately and
the breaker takes it from there. One that accepts the socket and says
nothing holds an FPM worker for the whole HTTP timeout, which is the
failure mode worth fearing, so the rig ships one:

```sh
TELEMETRY_OTLP_ENDPOINT=http://tarpit:4318 \
  docker compose -f loadtest/docker-compose.yml up -d --no-deps app
```

`tarpit` accepts and sleeps. Results from both kinds of broken collector
are in `docs/production/performance.md`.

To drain the spool afterwards — the daemon side, which the rig could not
reach at all before:

```sh
docker compose -f loadtest/docker-compose.yml exec app php loadtest/flush.php
docker compose -f loadtest/docker-compose.yml exec app php loadtest/flush.php --daemon --interval=1
```

## What it cannot tell you

**On Docker Desktop, nothing.** The container filesystem alone costs
tens of milliseconds a request there — two orders of magnitude above
what is being looked for — so every delta lands inside the noise. Run
it on Linux against a local filesystem. The per-operation figures in
`docs/production/performance.md` measure the package directly and do
not have this problem; they are the right source for a number, and
this rig is the right source for "does the whole thing hold up".

**The metric store under a fleet.** One app writing is not a thousand
apps writing to the same Redis.

**The metric store under a fleet.** One app writing is not a thousand
apps writing to the same Redis.

**Absolute throughput.** It belongs to this rig — worker count,
container CPU limits, the host — and is not a capacity figure for
anything.

## Targets

| | |
|---|---|
| `--target fpm` | The production base image: php-fpm and nginx with opcache, `cbox_telemetry` available. The one whose numbers mean something. |
| `--target builtin` | PHP's built-in server, no infrastructure. It re-bootstraps the framework every request at ~18ms and is single-process, so it cannot resolve the delta either — useful only to check the harness runs. |

## The application under test

`public/index.php` boots a real Laravel application through testbench
rather than a skeleton app: the kernel, the middleware stack and the
terminate phase are the real ones, and most of this package's cost is
in terminate. A hundred megabytes of skeleton would be a hundred
megabytes of other people's decisions to keep in sync.

Three routes, because the cost is not uniform:

| | |
|---|---|
| `/flat` | the floor — middleware and terminate, nothing else |
| `/work` | a realistic request: queries, cache, an outgoing call |
| `/heavy` | an N+1, where per-query cost stops being small |

Everything is configured by environment variable, exactly as a
deployment configures it, so a run changes one variable and nothing
else.
