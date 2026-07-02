---
title: "Cookbook: External services"
description: Instrument outbound APIs — latency, failures, distributed traces
weight: 3
---

# Cookbook: External services

Goal: know when a third-party API is slow or failing *before* your users
tell you, and see internal calls as one distributed trace.

## Wrap the client once

```php
final readonly class PaymentGateway
{
    public function charge(Order $order): ChargeResult
    {
        return Telemetry::span('payment.charge', function ($span) use ($order) {
            $span->setAttributes([
                'peer.service' => 'stripe',
                'order.id' => $order->id,
            ]);

            $response = Telemetry::histogram('http.client.duration', unit: 'ms')
                ->time(fn () => Http::timeout(5)->post($this->url, $order->payload()),
                    ['peer' => 'stripe', 'operation' => 'charge']);

            Telemetry::counter('http.client.requests')
                ->inc(1, [
                    'peer' => 'stripe',
                    'status' => (string) $response->status(),
                ]);

            $response->throw();

            return ChargeResult::from($response->json());
        }, kind: SpanKind::Client);
    }
}
```

One wrapper gives you: a client span in the trace, a latency histogram and
a status-coded counter. Exceptions land on the span automatically.

## Internal services: continue the trace

For services you own, add the traceparent so the callee's telemetry joins
the same trace:

```php
Http::withTraceparent()
    ->timeout(3)
    ->post('https://billing.internal/api/invoices', $payload);
```

If the callee also runs this package (and `traces.continue_incoming` is
on), its request span becomes a child of your client span — one trace
across services.

## Alerts

```promql
# error ratio per peer
sum by (peer) (rate(http_client_requests_total{status=~"5.."}[5m]))
  / sum by (peer) (rate(http_client_requests_total[5m])) > 0.1

# p95 latency per peer
histogram_quantile(0.95, sum by (le, peer)
  (rate(http_client_duration_bucket[10m]))) > 2000
```

## Rule of thumb

- `peer`/`operation` labels: bounded, one per integration — fine.
- URL or request id as a label: never. That's what span attributes are for.
