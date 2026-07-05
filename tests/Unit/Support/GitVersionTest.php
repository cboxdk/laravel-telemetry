<?php

declare(strict_types=1);

use Cbox\Telemetry\Support\GitVersion;

function gitFixture(array $files): string
{
    $dir = sys_get_temp_dir().'/telemetry-git-'.bin2hex(random_bytes(4));
    mkdir($dir.'/.git/refs/heads', 0777, true);

    foreach ($files as $path => $contents) {
        file_put_contents($dir.'/.git/'.$path, $contents);
    }

    return $dir;
}

it('resolves the sha behind a symbolic ref', function () {
    $sha = str_repeat('ab12', 10);
    $dir = gitFixture(['HEAD' => "ref: refs/heads/main\n", 'refs/heads/main' => $sha."\n"]);

    expect(GitVersion::detect($dir))->toBe(substr($sha, 0, 12));
});

it('resolves a detached head and packed refs', function () {
    $sha = str_repeat('cd34', 10);

    expect(GitVersion::detect(gitFixture(['HEAD' => $sha])))->toBe(substr($sha, 0, 12));

    $packed = gitFixture([
        'HEAD' => "ref: refs/heads/main\n",
        'packed-refs' => "# pack-refs\n{$sha} refs/heads/main\n",
    ]);

    expect(GitVersion::detect($packed))->toBe(substr($sha, 0, 12));
});

it('returns null without a git checkout', function () {
    expect(GitVersion::detect(sys_get_temp_dir().'/definitely-not-a-repo'))->toBeNull();
});
