---
title: Custom exporters
description: Ship telemetry to your own backend
weight: 2
---

# Custom exporters

Implement the contract:

```php
use Cbox\Telemetry\Contracts\Exporter;
use Cbox\Telemetry\Support\{ExportResult, Signal, SignalSet, TelemetryBatch};

final class ClickhouseExporter implements Exporter
{
    public function name(): string
    {
        return 'clickhouse';
    }

    public function supports(): SignalSet
    {
        return SignalSet::of(Signal::Traces, Signal::Events);
    }

    public function export(TelemetryBatch $batch): ExportResult
    {
        try {
            $this->client->insert('spans', $this->rows($batch->spans));

            return ExportResult::ok();
        } catch (ConnectionException $e) {
            return ExportResult::retryable($e->getMessage());
        } catch (Throwable $e) {
            return ExportResult::failed($e->getMessage());
        }
    }
}
```

Register it by class name in config — it resolves through the container:

```php
'exporters' => [ClickhouseExporter::class, 'otlp'],
```

## Rules of the pipeline

- You only receive signals you declared in `supports()`; the batch is
  pre-filtered.
- **Never throw** — classify failures in the `ExportResult`:
  - `ok()` — accepted.
  - `partial($rejected, $reason)` — accepted, some items rejected.
  - `retryable($reason, $retryAfterSeconds)` — transient (429/503,
    timeouts). The pipeline/scheduler decides on retries.
  - `failed($reason)` — permanent; retrying won't help.
- Exports run at terminate (after the response) — but they still block the
  worker. Keep timeouts tight.
- `$batch->resource` carries the service attributes; include them in your
  output so multi-service backends can distinguish sources.
