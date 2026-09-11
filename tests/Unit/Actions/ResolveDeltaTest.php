<?php

declare(strict_types=1);

use App\Actions\ResolveDelta;
use App\Exceptions\FailureException;
use App\ValueObjects\Delta;
use App\ValueObjects\Package;
use App\ValueObjects\Project;
use Tests\PendingUpdate;

it('compares the newest release to the release before it when the project holds no installed package list', function (): void {
    $project = PendingUpdate::create();

    unlink($project->rootPath.'/vendor/composer/installed.json');

    try {
        $delta = ResolveDelta::forProject(Project::at($project->rootPath))->resolve(PendingUpdate::PACKAGE);
    } finally {
        $project->remove();
    }

    expect($delta->from)->toBe(PendingUpdate::TRUSTED_VERSION)
        ->and($delta->to)->toBe(PendingUpdate::TARGET_VERSION)
        ->and($delta->toIsLocalInstall)->toBeFalse();
});

it('refuses a delta of the oldest release when the user names no earlier version', function (): void {
    $project = PendingUpdate::create();

    try {
        expect(fn (): Delta => ResolveDelta::forProject(Project::at($project->rootPath))->resolve(PendingUpdate::PACKAGE, null, PendingUpdate::TRUSTED_VERSION))
            ->toThrow(FailureException::class, '[acme/widget@1.0.0] has no earlier release to compare against. Pass an explicit version: [vet acme/widget --from=<version>].');
    } finally {
        $project->remove();
    }
});

it('builds no incoming delta without an installed tree to compare to', function (): void {
    $target = Package::fromLockEntry(['name' => 'acme/widget', 'version' => '2.0.0'], false);
    $resolver = ResolveDelta::forProject(Project::at(sys_get_temp_dir()));

    expect($resolver->incoming($target, null))->toBeNull()
        ->and($resolver->incoming($target, Package::fromLockEntry(['name' => 'acme/widget', 'version' => '1.0.0'], false)))->toBeNull();
});
