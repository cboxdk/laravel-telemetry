#!/usr/bin/env python3
"""Reduce oha's JSON to the four numbers worth comparing between runs."""

import json
import sys

report = json.load(sys.stdin)
percentiles = report["latencyPercentiles"]

print("{:.0f} {:.2f} {:.2f} {:.2f}".format(
    report["summary"]["requestsPerSec"],
    percentiles["p50"] * 1000,
    percentiles["p95"] * 1000,
    percentiles["p99"] * 1000,
))
