<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Marks a deployment — run it from your deploy pipeline (Forge/Envoyer
 * deploy script, GitHub Actions) right after the release goes live:
 *
 *     php artisan telemetry:deploy
 *
 * The emitted `app.deployment` event lands in your logs backend (Loki),
 * where the bundled dashboards render it as an annotation line on every
 * panel — regressions map to deploys at a glance.
 */
final class DeployCommand extends Command
{
    protected $signature = 'telemetry:deploy
                            {--id= : Deployment id (defaults to service.deployment / the detected git sha)}
                            {--notes= : Free-form note shown on the annotation}';

    protected $description = 'Emit a deployment marker event (rendered as annotations in the bundled dashboards)';

    public function handle(TelemetryManager $telemetry): int
    {
        if (! $telemetry->enabled()) {
            $this->components->warn('Telemetry is disabled; no deployment marker emitted.');

            return self::SUCCESS;
        }

        $id = $this->option('id');
        $id = is_string($id) && $id !== '' ? $id : ($telemetry->resource()['deployment.id'] ?? null);
        $id = is_string($id) && $id !== '' ? $id : 'unknown';

        $telemetry->event('app.deployment', array_filter([
            'deployment.id' => $id,
            'deployment.notes' => is_string($notes = $this->option('notes')) && $notes !== '' ? $notes : null,
        ]));

        $telemetry->counter('deployments', 'Deploy markers')->inc();

        // The marker is only worth anything if it lands. A pipeline that
        // sees a green telemetry:deploy has been told the annotation is
        // in the backend — so a rejected batch fails the step.
        $report = $telemetry->flush()->merge($telemetry->flushMetrics());

        if (! $report->successful()) {
            $this->components->error("Deployment marker was rejected: {$report->summary()}.");

            foreach ($report->problems() as $problem) {
                $this->components->twoColumnDetail($problem->exporter, '<fg=red>'.$problem->describe().'</>');
            }

            Log::error("telemetry:deploy — deployment marker was rejected: {$report->summary()}", [
                'deployment.id' => $id,
            ]);

            return self::FAILURE;
        }

        $this->components->info("Deployment marker emitted (deployment.id: {$id}).");

        return self::SUCCESS;
    }
}
