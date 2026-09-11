<?php

declare(strict_types=1);

use App\Actions\ResolveDelta;
use App\Enums\InstallSourceType;
use App\Exceptions\FailureException;
use App\Support\Json;
use App\ValueObjects\Delta;
use App\ValueObjects\Package;
use App\ValueObjects\Project;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\PendingUpdate;

it('compares the trusted release to the newest release when the project does not install the package', function (): void {
    $project = PendingUpdate::create();

    file_put_contents($project->rootPath.'/vendor/composer/installed.json', Json::encode(['packages' => [], 'dev' => false]));

    try {
        $delta = ResolveDelta::forProject(Project::at($project->rootPath))->resolveInstalled(PendingUpdate::PACKAGE, PendingUpdate::TRUSTED_VERSION);
    } finally {
        $project->remove();
    }

    expect($delta->from)->toBe(PendingUpdate::TRUSTED_VERSION)
        ->and($delta->to)->toBe(PendingUpdate::TARGET_VERSION)
        ->and($delta->toIsLocalInstall)->toBeFalse();
});

it('compares the dist archives when vendor/ holds no tree of the installed package', function (): void {
    $project = PendingUpdate::create();

    File::deleteDirectory($project->rootPath.'/vendor/acme/widget');

    try {
        expect(fn (): Delta => ResolveDelta::forProject(Project::at($project->rootPath))->resolveInstalled(PendingUpdate::PACKAGE, PendingUpdate::TRUSTED_VERSION))
            ->toThrow(FailureException::class, '[acme/widget] [1.0.0] and [1.0.0] are the same version.');
    } finally {
        $project->remove();
    }
});

it('refuses a delta between one version and itself', function (): void {
    $project = PendingUpdate::create();

    try {
        expect(fn (): Delta => ResolveDelta::forProject(Project::at($project->rootPath))->resolve(PendingUpdate::PACKAGE, PendingUpdate::TRUSTED_VERSION, PendingUpdate::TRUSTED_VERSION))
            ->toThrow(FailureException::class, '[acme/widget] [1.0.0] and [1.0.0] are the same version.');
    } finally {
        $project->remove();
    }
});

it('builds no incoming delta without an installed tree to compare to', function (): void {
    $project = PendingUpdate::create();
    $target = Package::fromLockEntry(['name' => 'acme/widget', 'version' => '2.0.0'], false);

    try {
        $resolver = ResolveDelta::forProject(Project::at($project->rootPath));

        expect(fn (): Delta => $resolver->incoming($target, Package::fromLockEntry(['name' => 'acme/widget', 'version' => '1.0.0'], false)))
            ->toThrow(FailureException::class, 'The package [acme/widget] has no recorded install path.')
            ->and(fn (): Delta => $resolver->incoming($target, Package::fromInstalledEntry(['name' => 'acme/widget', 'version' => '1.0.0', 'install-path' => '../acme/missing'], false, $project->rootPath.'/vendor/composer')))
            ->toThrow(FailureException::class, sprintf('The install path [%s/vendor/acme/missing] of [acme/widget] is not a directory.', $project->rootPath));
    } finally {
        $project->remove();
    }
});

it('compares the dist archives of a package that is installed from source, and says so', function (): void {
    $project = PendingUpdate::create();

    file_put_contents($project->rootPath.'/vendor/composer/installed.json', Json::encode([
        'packages' => [[
            'name' => PendingUpdate::PACKAGE,
            'version' => PendingUpdate::TARGET_VERSION,
            'type' => 'library',
            'dist' => ['type' => 'zip', 'url' => 'https://example.test/acme-widget-2.0.0.zip', 'reference' => 'bbbb2222', 'shasum' => ''],
            'installation-source' => 'source',
            'install-path' => '../acme/widget',
        ]],
        'dev' => true,
        'dev-package-names' => [],
    ]));

    try {
        $delta = ResolveDelta::forProject(Project::at($project->rootPath))->resolveInstalled(PendingUpdate::PACKAGE, PendingUpdate::TRUSTED_VERSION);
    } finally {
        $project->remove();
    }

    expect($delta->from)->toBe(PendingUpdate::TRUSTED_VERSION)
        ->and($delta->to)->toBe(PendingUpdate::TARGET_VERSION)
        ->and($delta->source)->toBe(InstallSourceType::Source)
        ->and($delta->toIsLocalInstall)->toBeFalse()
        ->and($delta->notes)->toBe(['[acme/widget] is installed from source; comparing dist archives instead. An audit of this delta does not cover your source install.']);
});
