<?php

declare(strict_types=1);

use App\Actions\AuditProject;
use App\Enums\AuditStatus;
use App\ValueObjects\Change;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\Project;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\FakeHttp;
use Tests\PendingUpdate;
use Tests\StaleProject;

it('renders the buckets and the changed paths of a stale package', function (): void {
    $project = StaleProject::create();

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('1 files (delta from [1.0.0])')
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain("+        return 'gadget';")
        ->toContain('Read every change with [vet -v]');
});

it('renders the source of each change with -v', function (): void {
    $project = StaleProject::create();

    try {
        $status = vet(['--path' => $project->rootPath, '-v' => true]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('runtime source (1)')
        ->toContain('~ src/Widget.php')
        ->toContain('│ runtime source (1)')
        ->toContain("│     +        return 'gadget';")
        ->toContain("-        return 'widget';")
        ->toContain("+        return 'gadget';")
        ->toContain('[1] package(s) are not covered. Run [vet] in a terminal to record the ones that you trust.');
});

it('reads no whole tree of a package whose bytes it cannot read, or that the project does not install', function (): void {
    $project = StaleProject::create();

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));

        $unknown = $auditor->wholeTree(new PackageAudit('acme/widget', '2.0.0', null, false, AuditStatus::Unknown, 0, 0));
        $missing = $auditor->wholeTree(new PackageAudit('acme/missing', '1.0.0', null, false, AuditStatus::Ungranted, 0, 0));
    } finally {
        $project->remove();
    }

    expect($unknown)->toBeNull()
        ->and($missing)->toBeNull();
});

it('reads no whole tree of an installed package whose bytes it cannot fetch', function (): void {
    $project = StaleProject::create();

    File::deleteDirectory($project->rootPath.'/vendor/acme/widget');
    app()->instance(ClientInterface::class, (new FakeHttp([new Response(404, [], 'missing')]))->client);

    try {
        $delta = AuditProject::forProject(Project::at($project->rootPath))
            ->wholeTree(new PackageAudit('acme/widget', '2.0.0', null, false, AuditStatus::Changed, 0, 0));
    } finally {
        $project->remove();
    }

    expect($delta)->toBeNull();
});

it('reads the whole tree that composer would install', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);

    try {
        $auditor = AuditProject::forProject(Project::at($project->rootPath));
        $delta = $auditor->wholeTree($auditor->auditOfName(PendingUpdate::PACKAGE));
    } finally {
        $project->remove();
    }

    expect($delta?->firstInstall)->toBeTrue()
        ->and($delta?->to)->toBe(PendingUpdate::TARGET_VERSION)
        ->and(array_map(static fn (Change $change): string => $change->path, $delta?->changes() ?? []))->toContain('src/Widget.php');
});
