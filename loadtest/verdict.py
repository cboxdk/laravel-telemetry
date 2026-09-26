#!/usr/bin/env python3
"""
Decide whether a measured difference is a result or noise.

Takes two files, one per arm, each holding one `rps p50 p95 p99` line
per interleaved pass. The spread within an arm is the smallest
difference this rig can see; a delta below it is not a small effect,
it is no measurement. Printing it as though it were is how a benchmark
ends up claiming that telemetry made requests faster.
"""

import sys


def p50s(path):
    with open(path) as handle:
        return [float(line.split()[1]) for line in handle if line.strip()]


off, on = (p50s(path) for path in sys.argv[1:3])

mean_off = sum(off) / len(off)
mean_on = sum(on) / len(on)

# The worse of the two arms' spreads: one arm being stable says nothing
# about the other, and the rig is only as good as its noisier half.
noise = max(max(off) - min(off), max(on) - min(on)) if len(off) > 1 else float("inf")
delta = mean_on - mean_off

print("{:.2f} {:.2f} {:+.2f}ms {:.2f}ms {}".format(
    mean_off,
    mean_on,
    delta,
    noise,
    "resolved" if abs(delta) > noise else "noise",
))
