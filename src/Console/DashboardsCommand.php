<?php

declare(strict_types=1);

namespace Cbox\Telemetry\Console;

use Illuminate\Console\Command;

/**
 * Installs the bundled Grafana dashboards (Overview, Requests,
 * Queue & Schedule, Drill-down) — either straight into Grafana via its
 * HTTP API, or exported as JSON files for file-based provisioning.
 *
 * Every dashboard is service-scoped via a $service variable, so one
 * import serves all apps shipping telemetry to the same stack.
 */
final class DashboardsCommand extends Command
{
    protected $signature = 'telemetry:dashboards
                            {--grafana=http://localhost:3000 : Grafana base URL}
                            {--token= : Grafana service-account token (omit for anonymous/local)}
                            {--export= : Write the dashboard JSON files to this directory instead of importing}';

    protected $description = 'Install the bundled Grafana dashboards (import via API or export for provisioning)';

    public function handle(): int
    {
        $files = glob(__DIR__.'/../../resources/grafana/dashboards/*.json') ?: [];

        if ($files === []) {
            $this->components->error('No bundled dashboards found.');

            return self::FAILURE;
        }

        $export = $this->option('export');

        if (is_string($export) && $export !== '') {
            return $this->export($files, $export);
        }

        return $this->import($files);
    }

    /**
     * @param  list<string>  $files
     */
    private function export(array $files, string $directory): int
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            $this->components->error("Cannot create [{$directory}].");

            return self::FAILURE;
        }

        foreach ($files as $file) {
            copy($file, rtrim($directory, '/').'/'.basename($file));
            $this->components->twoColumnDetail(basename($file), '<fg=green>exported</>');
        }

        $this->components->info("Point a Grafana file provisioning provider at {$directory} to load them.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $files
     */
    private function import(array $files): int
    {
        $base = $this->option('grafana');
        $grafana = rtrim(is_string($base) ? $base : 'http://localhost:3000', '/');
        $failures = 0;

        foreach ($files as $file) {
            $dashboard = json_decode((string) file_get_contents($file), true);

            if (! is_array($dashboard)) {
                $failures++;

                continue;
            }

            [$status, $body] = $this->post("{$grafana}/api/dashboards/db", [
                'dashboard' => $dashboard,
                'overwrite' => true,
                'message' => 'Imported by telemetry:dashboards',
            ]);

            $title = is_string($dashboard['title'] ?? null) ? $dashboard['title'] : basename($file);

            if ($status >= 200 && $status < 300) {
                /** @var array{url?: string}|null $decoded */
                $decoded = json_decode($body, true);

                $this->components->twoColumnDetail($title, '<fg=green>OK</> '.$grafana.($decoded['url'] ?? ''));
            } else {
                $failures++;
                $this->components->twoColumnDetail($title, '<fg=red>HTTP '.$status.'</> '.substr($body, 0, 120));
            }
        }

        if ($failures === 0) {
            $this->components->info('Dashboards imported. They are linked via the "Telemetry" dropdown in Grafana.');
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Plain curl so the command works without guzzle installed.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: string}
     */
    private function post(string $url, array $payload): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return [0, 'curl_init failed'];
        }

        $headers = ['Content-Type: application/json'];

        if (is_string($token = $this->option('token')) && $token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return [$status, is_string($body) ? $body : (string) curl_error($handle)];
    }
}
