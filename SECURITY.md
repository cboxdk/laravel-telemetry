# Security Policy

## Supported versions

The latest major version receives security fixes.

## Reporting a vulnerability

Please **do not** open a public issue. Email [sn@cbox.dk](mailto:sn@cbox.dk)
with a description and, if possible, a proof of concept. You will get a
response within a few business days.

Areas of particular interest for this package:

- The Prometheus scrape endpoints (exposure, filtering, IP allowlisting).
- Data leakage through metric labels, span attributes or exported events.
- The OTLP transport (header handling, endpoint validation).
