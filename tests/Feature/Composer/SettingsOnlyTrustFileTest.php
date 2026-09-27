<?php

declare(strict_types=1);

use Tests\Fixtures\ComposerProject;

const SETTINGS_ONLY = '{"minimum-release-age":7,"minimum-release-age-exclude":["laravel/vet","roave/security-advisories"]}';

const SETTINGS_ONLY_NOTICE = 'Vet has no trust entry in [vet.json] yet. Run [./vendor/bin/vet --init] to record what you trust today.';

it('installs every package of a fresh project whose trust file holds only settings', function (): void {
    $project = ComposerProject::create(SETTINGS_ONLY);

    try {
        $install = $project->composer('install');
        $installed = $project->installedVersion();
    } finally {
        $project->remove();
    }

    expect($install->getExitCode())->toBe(0)
        ->and($install->getErrorOutput())->toContain(SETTINGS_ONLY_NOTICE)
        ->and($installed)->toBe(ComposerProject::YOUNG_VERSION);
});

it('installs nothing and passes when the trust file holds only settings', function (): void {
    $project = ComposerProject::create(SETTINGS_ONLY);

    try {
        $project->composer('install');
        $install = $project->composer('install');
    } finally {
        $project->remove();
    }

    expect($install->getExitCode())->toBe(0)
        ->and($install->getErrorOutput())
        ->toContain('Nothing to install, update or remove')
        ->toContain(SETTINGS_ONLY_NOTICE);
});

it('holds back a young release and writes vendor/ when the trust file holds only settings', function (): void {
    $project = ComposerProject::create(SETTINGS_ONLY);

    try {
        $project->composer('install');
        $update = $project->composer('update');
        $locked = $project->lockedVersion();
        $installed = $project->installedVersion();
    } finally {
        $project->remove();
    }

    expect($update->getExitCode())->toBe(0)
        ->and($update->getErrorOutput())
        ->toContain('Vet skips the releases of [acme/widget] that are younger than [7] days')
        ->toContain(SETTINGS_ONLY_NOTICE)
        ->and($locked)->toBe(ComposerProject::OLD_VERSION)
        ->and($installed)->toBe(ComposerProject::OLD_VERSION);
});

it('fails the install on an untrusted or a too recent package when the trust file holds one entry', function (): void {
    $project = ComposerProject::create(sprintf(
        '{"require":{"acme/other":{"version":"1.0.0","hash":"tree-v2:%s"}},"minimum-release-age":7}',
        str_repeat('a', 64),
    ));

    try {
        $install = $project->composer('install');
    } finally {
        $project->remove();
    }

    expect($install->getExitCode())->not->toBe(0)
        ->and($install->getOutput())
        ->toContain('[1] package is not trusted.')
        ->toContain('[1] package is too recent for [minimum-release-age].')
        ->and($install->getErrorOutput())->not->toContain(SETTINGS_ONLY_NOTICE);
});
