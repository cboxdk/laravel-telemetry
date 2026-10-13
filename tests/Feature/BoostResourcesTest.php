<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Testing\RecordedMetrics;
use Cbox\Telemetry\Testing\TelemetryFake;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/*
 * Laravel Boost reads a package's AI resources from resources/boost/:
 * guidelines/*.md|.blade.php and skills/{name}/SKILL.md. A skill whose
 * frontmatter does not parse, or that names no description, is skipped
 * without a word — so it is pinned here, together with every facade method,
 * fake method and artisan command the files tell an agent to use.
 */

function boostRoot(): string
{
    return dirname(__DIR__, 2).'/resources/boost';
}

/**
 * @return array<string, string> skill directory name => SKILL.md path
 */
function boostSkills(): array
{
    $skills = [];

    foreach (glob(boostRoot().'/skills/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $skills[basename($dir)] = $dir.'/SKILL.md';
    }

    return $skills;
}

/**
 * The same split Boost's SkillComposer does.
 *
 * @return array{0: array<mixed>, 1: string}
 */
function boostFrontmatter(string $content): array
{
    if (preg_match('/^\s*---[^\S\r\n]*\R(.*?)\R---[^\S\r\n]*(?:\R|$)(.*)$/s', $content, $m) !== 1) {
        return [[], $content];
    }

    $parsed = Yaml::parse($m[1]);

    return [is_array($parsed) ? $parsed : [], $m[2]];
}

/**
 * @return list<string> every file Boost would hand an agent
 */
function boostFiles(): array
{
    return [...array_values(boostSkills()), ...(glob(boostRoot().'/guidelines/*') ?: [])];
}

it('ships the package guideline where Boost looks for it', function () {
    expect(boostRoot().'/guidelines/core.md')->toBeFile();
});

it('ships skills Boost can parse', function () {
    expect(boostSkills())->not->toBeEmpty();

    foreach (boostSkills() as $dir => $file) {
        expect($file)->toBeFile();

        [$meta, $body] = boostFrontmatter((string) file_get_contents($file));

        expect($meta['name'] ?? null)->toBe($dir, "{$dir}: name must match its directory")
            ->and($dir)->toMatch('/^telemetry-[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and(strlen($dir))->toBeLessThanOrEqual(64)
            ->and($meta['description'] ?? null)->toBeString()->not->toBeEmpty()
            ->and(strlen((string) ($meta['description'] ?? '')))->toBeLessThanOrEqual(1024)
            ->and(trim($body))->not->toBeEmpty();
    }
});

it('keeps resources/boost in the dist archive', function () {
    $patterns = collect(file(dirname(__DIR__, 2).'/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [])
        ->filter(fn (string $line) => str_contains($line, 'export-ignore'))
        ->map(fn (string $line) => ltrim((string) strtok(trim($line), " \t"), '/'));

    foreach (boostFiles() as $file) {
        $path = ltrim(str_replace(dirname(__DIR__, 2), '', $file), '/');

        foreach ($patterns as $pattern) {
            expect($path === $pattern || str_starts_with($path, $pattern.'/') || fnmatch($pattern, $path))
                ->toBeFalse("{$path} is export-ignored by [{$pattern}]");
        }
    }
});

it('names only facade methods, fake methods and commands that exist', function () {
    $commands = array_keys(Artisan::all());

    foreach (boostFiles() as $file) {
        $content = (string) file_get_contents($file);
        $name = basename(dirname($file)).'/'.basename($file);

        preg_match_all('/Telemetry::([a-zA-Z]+)\(/', $content, $calls);
        foreach (array_unique($calls[1]) as $method) {
            // After Telemetry::fake() the facade resolves to the fake, so its
            // assertions are facade calls too.
            expect(method_exists(TelemetryManager::class, $method) || method_exists(Telemetry::class, $method) || method_exists(TelemetryFake::class, $method))
                ->toBeTrue("{$name}: Telemetry::{$method}() does not exist");
        }

        preg_match_all('/\$fake->([a-zA-Z]+)\(/', $content, $calls);
        foreach (array_unique($calls[1]) as $method) {
            expect(method_exists(TelemetryFake::class, $method))
                ->toBeTrue("{$name}: \$fake->{$method}() does not exist");
        }

        preg_match_all('/->(assert(?:LabelValues|SeriesCount|CardinalityBelow|LabelCardinalityBelow))\(/', $content, $calls);
        foreach (array_unique($calls[1]) as $method) {
            expect(method_exists(RecordedMetrics::class, $method))->toBeTrue("{$name}: {$method}() does not exist");
        }

        preg_match_all('/\b(telemetry:[a-z-]+)/', $content, $calls);
        foreach (array_unique($calls[1]) as $command) {
            expect($commands)->toContain($command);
        }
    }
});
