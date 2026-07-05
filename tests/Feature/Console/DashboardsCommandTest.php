<?php

declare(strict_types=1);

it('exports the bundled dashboards for file provisioning', function () {
    $dir = sys_get_temp_dir().'/telemetry-dashboards-'.bin2hex(random_bytes(4));

    $this->artisan('telemetry:dashboards', ['--export' => $dir])
        ->expectsOutputToContain('telemetry-overview.json')
        ->expectsOutputToContain('exported')
        ->assertSuccessful();

    $files = glob("{$dir}/*.json");

    expect($files)->toHaveCount(13);

    // Every dashboard is valid JSON, service-scoped and tagged.
    foreach ($files as $file) {
        $dashboard = json_decode((string) file_get_contents($file), true);

        expect($dashboard)->toBeArray()
            ->and($dashboard['tags'])->toContain('telemetry')
            ->and(collect($dashboard['templating']['list'])->pluck('name'))->toContain('service');
    }

    array_map(unlink(...), $files);
    rmdir($dir);
});

it('fails cleanly when grafana is unreachable', function () {
    $this->artisan('telemetry:dashboards', ['--grafana' => 'http://127.0.0.1:59999'])
        ->assertFailed();
});
