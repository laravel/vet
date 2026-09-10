<?php

declare(strict_types=1);

use App\Enums\ComposerChangeType;
use App\ValueObjects\ComposerPlan;
use App\ValueObjects\InstalledRepository;
use App\ValueObjects\LockFile;
use App\ValueObjects\Project;
use Tests\Fixture;
use Tests\PendingUpdate;

it('reads the operations of a real dry run', function (): void {
    $plan = ComposerPlan::parse((string) file_get_contents(__DIR__.'/../Fixtures/composer-update-dry-run.txt'));

    $operations = [];

    foreach ($plan->operations as $operation) {
        $operations[$operation->package] = [$operation->change, $operation->from, $operation->to];
    }

    expect($operations)->toBe([
        'psr/simple-cache' => [ComposerChangeType::Remove, '3.0.0', null],
        'carbonphp/carbon-doctrine-types' => [ComposerChangeType::Upgrade, '3.1.0', '3.2.0'],
        'psr/log' => [ComposerChangeType::Downgrade, '3.0.0', '2.0.0'],
        'psr/container' => [ComposerChangeType::Install, null, '2.0.2'],
    ]);
});

it('reports no operation when composer would change nothing', function (): void {
    $plan = ComposerPlan::parse(<<<'OUTPUT'
        Loading composer repositories with package information
        Updating dependencies
        Nothing to modify in lock file
        Installing dependencies from lock file (including require-dev)
        Nothing to install, update or remove
        OUTPUT);

    expect($plan->isEmpty())->toBeTrue()
        ->and($plan->operations)->toBeEmpty();
});

it('ignores the lines that name no operation', function (): void {
    $plan = ComposerPlan::parse(<<<'OUTPUT'
          - Locking acme/widget (2.0.0)
          - Downloading acme/widget (2.0.0)
          - Installing acme/widget (2.0.0): Extracting archive
        OUTPUT);

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->operations[0]->change)->toBe(ComposerChangeType::Install)
        ->and($plan->operations[0]->to)->toBe('2.0.0');
});

it('drops the reference of a branch version', function (): void {
    $plan = ComposerPlan::parse('  - Upgrading acme/widget (dev-main 1234567 => dev-main 89abcde)');

    expect($plan->operations[0]->from)->toBe('dev-main')
        ->and($plan->operations[0]->to)->toBe('dev-main');
});

it('reads the operations that composer wrote to a plan file', function (): void {
    $path = sys_get_temp_dir().'/vet-plan-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($path, json_encode(['operations' => [
        [
            'package' => 'acme/widget',
            'change' => 'upgrade',
            'from' => '1.0.0',
            'to' => '2.0.0',
            'dist_url' => 'https://example.test/widget-2.0.0.zip',
            'dist_reference' => 'bbbb2222',
        ],
        ['package' => 'acme/legacy', 'change' => 'remove', 'from' => '0.9.0', 'to' => null],
        ['change' => 'install', 'to' => '1.0.0'],
    ]]));

    try {
        $plan = ComposerPlan::fromFile($path);
    } finally {
        unlink($path);
    }

    $widget = $plan->of('acme/widget');

    expect($plan->operations)->toHaveCount(2)
        ->and($widget?->change)->toBe(ComposerChangeType::Upgrade)
        ->and($widget?->from)->toBe('1.0.0')
        ->and($widget?->to)->toBe('2.0.0')
        ->and($widget?->distUrl)->toBe('https://example.test/widget-2.0.0.zip')
        ->and($widget?->distReference)->toBe('bbbb2222')
        ->and($plan->touches('acme/legacy'))->toBeTrue()
        ->and($plan->incoming())->toHaveCount(1);
});

it('names an upgrade between composer.lock and the installed tree', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $located = Project::at($project->rootPath);
        $plan = ComposerPlan::between(LockFile::fromProject($located), InstalledRepository::fromProject($located));
    } finally {
        $project->remove();
    }

    $widget = $plan->of(PendingUpdate::PACKAGE);

    expect($plan->operations)->toHaveCount(1)
        ->and($widget?->change)->toBe(ComposerChangeType::Upgrade)
        ->and($widget?->from)->toBe('1.0.0')
        ->and($widget?->to)->toBe('2.0.0')
        ->and($widget?->distUrl)->toBe('https://example.test/acme-widget-2.0.0.zip');
});

it('names a rebuild of the same version between composer.lock and the installed tree', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TRUSTED_VERSION);

    try {
        $located = Project::at($project->rootPath);
        $plan = ComposerPlan::between(LockFile::fromProject($located), InstalledRepository::fromProject($located));
    } finally {
        $project->remove();
    }

    $widget = $plan->of(PendingUpdate::PACKAGE);

    expect($plan->operations)->toHaveCount(1)
        ->and($widget?->change)->toBe(ComposerChangeType::Upgrade)
        ->and($widget?->from)->toBe('1.0.0')
        ->and($widget?->to)->toBe('1.0.0')
        ->and($widget?->distReference)->toBe('bbbb2222');
});

it('names a downgrade between composer.lock and the installed tree', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt('0.9.0');

    try {
        $located = Project::at($project->rootPath);
        $plan = ComposerPlan::between(LockFile::fromProject($located), InstalledRepository::fromProject($located));
    } finally {
        $project->remove();
    }

    expect($plan->of(PendingUpdate::PACKAGE)?->change)->toBe(ComposerChangeType::Downgrade);
});

it('names a package that composer.lock holds and the tree does not', function (): void {
    $fixture = Fixture::open('lock-drift');

    try {
        $located = Project::at($fixture->rootPath);
        $plan = ComposerPlan::between(LockFile::fromProject($located), InstalledRepository::fromProject($located));
    } finally {
        $fixture->remove();
    }

    $ghost = $plan->of('acme/ghost');
    $extra = $plan->of('acme/extra');

    expect($ghost?->change)->toBe(ComposerChangeType::Install)
        ->and($ghost?->from)->toBeNull()
        ->and($ghost?->to)->toBe('1.0.0')
        ->and($extra?->change)->toBe(ComposerChangeType::Remove)
        ->and($extra?->from)->toBe('1.0.0')
        ->and($extra?->to)->toBeNull()
        ->and($plan->incoming())->toHaveCount(1);
});

it('holds no operation when the tree matches composer.lock', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        $located = Project::at($fixture->rootPath);
        $plan = ComposerPlan::between(LockFile::fromProject($located), InstalledRepository::fromProject($located));
    } finally {
        $fixture->remove();
    }

    expect($plan->isEmpty())->toBeTrue();
});
