#!/usr/bin/env python3
"""
Decide whether a measured difference is a result or noise.

Takes two files, one per arm, each holding one `rps p50 p95 p99` line per
interleaved pass, oldest first. The arms alternate off/on/off/on, so pass
i of one arm was measured next to pass i of the other.

That pairing is the whole point, and the first version of this threw it
away. It compared the SPREAD WITHIN an arm against the difference BETWEEN
the arms — so a rig that drifts over a session (page cache, thermals, the
host picking up other work) reported a real and perfectly consistent
effect as noise. Measured three times, resource capture cost +0.36ms,
+0.57ms and +0.57ms; the arms' own spread was ±1.29ms because the third
pass was slow for both of them, and the old statistic called that
unresolved.

Paired differences cancel the drift, which is what pairing them was for.
The spread of the DIFFERENCES is the resolution.
"""

import sys


def p50s(path):
    with open(path) as handle:
        return [float(line.split()[1]) for line in handle if line.strip()]


off, on = (p50s(path) for path in sys.argv[1:3])

pairs = min(len(off), len(on))
deltas = [on[i] - off[i] for i in range(pairs)]


def median(values):
    ordered = sorted(values)
    middle = len(ordered) // 2

    if len(ordered) % 2:
        return ordered[middle]

    return (ordered[middle - 1] + ordered[middle]) / 2


delta = median(deltas)

# The resolution is how much the paired difference itself moves. One pass
# cannot tell us: with a single pair there is nothing to compare it to.
noise = (max(deltas) - min(deltas)) if pairs > 1 else float("inf")

# And a difference that changes sign between passes is not a measurement
# whatever its magnitude, so it never resolves.
consistent = all(d > 0 for d in deltas) or all(d < 0 for d in deltas)

print("{:.2f} {:.2f} {:+.2f}ms {:.2f}ms {}".format(
    median(off),
    median(on),
    delta,
    noise,
    "resolved" if consistent and abs(delta) > noise else "noise",
))
