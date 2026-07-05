# Contributing

Thanks for considering a contribution!

## Process

1. Fork and create a feature branch from `main`.
2. Make your change, with tests.
3. Run the full check locally:

   ```bash
   composer check   # pint --test, phpstan level 9, pest
   ```

4. Open a PR describing **why** as well as what.

## Ground rules

- New behaviour needs tests; bug fixes need a regression test.
- Public API changes need docs (`docs/`) in the same PR — and an update to
  the AI surface (`.ai/guidelines/telemetry.blade.php`, `llms.txt`) when
  usage guidance changes.
- Architectural changes should reference (or add) an ADR in `docs/adr/` —
  the existing ADRs record decisions that are deliberate, including what we
  chose *not* to do (no OTel SDK dependency, no summary instrument, no
  required collector).
- Telemetry must never throw into the host application: recording and
  export paths are `FailSafe::guard`ed; only instrument *registration* may
  throw.
- No `KEYS`/`SCAN` (Redis) or full-keyspace iteration (APCu) on any scrape
  path.

## Running the integration tests

```bash
# Redis group needs a local server:
redis-server --daemonize yes
vendor/bin/pest --group=redis

# APCu group needs ext-apcu with apc.enable_cli=1:
php -d apc.enable_cli=1 vendor/bin/pest --group=apcu
```
