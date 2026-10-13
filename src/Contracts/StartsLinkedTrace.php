<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Contracts;

/**
 * Marker for queued work that starts a trace of its own.
 *
 * By default a queued job's span is a CHILD of the span that dispatched
 * it, in the same trace. That is the right shape for most jobs and the
 * wrong one for a few: a job delayed by a day stretches its request's
 * trace across that day, and a request that fans out ten thousand jobs
 * becomes one trace of ten thousand spans that no backend renders well.
 *
 * Implement this on the job — or on the queued listener, mailable or
 * notification, whichever class you wrote — and its span starts a NEW
 * trace instead, as that trace's root, with an OTel span link to the
 * dispatching span (trace id, span id and its sampled flag). The link is
 * always there whenever the dispatch carried a trace, so the job is one
 * click from the request that queued it; it is never silently cut loose.
 *
 * The new trace is sampled by this process's own root decision, not the
 * dispatcher's. Sync jobs ignore the marker: they run inline, inside the
 * dispatcher's unit of work, and stay its children.
 *
 * Classes you cannot edit go in `telemetry.queue.linked_traces` instead.
 */
interface StartsLinkedTrace {}
