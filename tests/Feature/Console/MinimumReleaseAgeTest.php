<?php

declare(strict_types=1);

use App\ValueObjects\MinimumReleaseAge;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\PendingUpdate;

function daysAgo(int $days): string
{
    return new DateTimeImmutable(sprintf('-%d days', $days))->format(DATE_ATOM);
}

it('holds back a release that is younger than the minimum release age', function (): void {
    $project = PendingUpdate::create();
    $project->lockReleasedAt(PendingUpdate::TARGET_VERSION, daysAgo(2));
    $project->configure([MinimumReleaseAge::DAYS => 7]);

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('too recent (1)')
        ->toContain('acme/widget 1.0.0 → 2.0.0')
        ->toContain('wait until ['.new DateTimeImmutable('+5 days', new DateTimeZone('UTC'))->format('Y-m-d'))
        ->toContain('1 too recent')
        ->toContain('[1] package is too recent for [minimum-release-age]. Wait until the date that vet names, or add the package to [minimum-release-age-exclude] in [vet.json].')
        ->and($output)->not->toContain('to review (')
        ->and($output)->not->toContain('not trusted');
});

it('reviews a release that is older than the minimum release age', function (): void {
    $project = PendingUpdate::create();
    $project->lockReleasedAt(PendingUpdate::TARGET_VERSION, daysAgo(8));
    $project->configure([MinimumReleaseAge::DAYS => 7]);

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to review (1)')
        ->and($output)->not->toContain('too recent');
});

it('reviews a recent release of a package that the exclude list names', function (string $pattern): void {
    $project = PendingUpdate::create();
    $project->lockReleasedAt(PendingUpdate::TARGET_VERSION, daysAgo(1));
    $project->configure([MinimumReleaseAge::DAYS => 7, MinimumReleaseAge::EXCLUDE => [$pattern]]);

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to review (1)')
        ->and($output)->not->toContain('too recent');
})->with(['acme/widget', 'acme/*']);

it('reviews a release that names no date', function (): void {
    $project = PendingUpdate::create();
    $project->lockAt(PendingUpdate::TARGET_VERSION);
    $project->configure([MinimumReleaseAge::DAYS => 7]);

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('to review (1)')
        ->and($output)->not->toContain('too recent');
});

it('fails a trusted package whose release is younger than the minimum release age', function (): void {
    $project = PendingUpdate::create();
    $project->installedReleasedAt(daysAgo(1));

    try {
        $before = vet(['--path' => $project->rootPath]);

        $project->configure([MinimumReleaseAge::DAYS => 7]);

        $after = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($before)->toBe(0)
        ->and($after)->toBe(1)
        ->and($output)
        ->toContain('too recent (1)')
        ->toContain('acme/widget 1.0.0')
        ->toContain('0 to review, 1 too recent, 0 trusted');
});

it('asks nothing when each package that fails is too recent', function (): void {
    $project = PendingUpdate::create();
    $project->installedReleasedAt(daysAgo(1));
    $project->configure([MinimumReleaseAge::DAYS => 7]);

    try {
        command('vet', ['--path' => $project->rootPath])
            ->expectsOutputToContain('too recent (1)')
            ->expectsOutputToContain('[1] package is too recent for [minimum-release-age].')
            ->assertExitCode(1)
            ->run();
    } finally {
        $project->remove();
    }
});

it('records no recent release when it records the baseline', function (): void {
    $project = PendingUpdate::create();
    $project->installedReleasedAt(daysAgo(1));
    $project->configure([MinimumReleaseAge::DAYS => 7]);

    try {
        $status = vet(['--fresh' => true, '--path' => $project->rootPath]);
        $output = Artisan::output();
        $trustFile = $project->trustFile();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('too recent (1)')
        ->toContain('[1] package is too recent for [minimum-release-age].')
        ->and($output)->not->toContain('Trusted [')
        ->and($trustFile)->toContain('"minimum-release-age": 7')
        ->and($trustFile)->not->toContain('"require"');
});

it('names the date to wait for when it audits one recent package', function (): void {
    $project = PendingUpdate::create();
    $project->installedReleasedAt(daysAgo(1));
    $project->configure([MinimumReleaseAge::DAYS => 7]);

    try {
        $status = vet(['packages' => [PendingUpdate::PACKAGE], '--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('[acme/widget] [1.0.0] is too recent for [minimum-release-age]: wait until [')
        ->toContain('Add the package to [minimum-release-age-exclude] in [vet.json] to audit it today.');
});

it('refuses a minimum release age that is not a number of days', function (): void {
    $project = PendingUpdate::create();
    $project->configure([MinimumReleaseAge::DAYS => '7 days']);

    try {
        $status = vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [minimum-release-age] entry needs a whole number of days, such as [7].');
});

it('writes the minimum release age when it records the baseline', function (): void {
    $project = PendingUpdate::create();

    unlink($project->rootPath.'/vet.json');

    try {
        $status = vet(['--init' => true, '--minimum-release-age' => '7', '--path' => $project->rootPath]);
        $output = Artisan::output();
        $trustFile = $project->trustFile();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(0)
        ->and($output)
        ->toContain('Trusted [1] package, and wrote [vet.json].')
        ->toContain('Vet now holds back each release younger than [7] days. Run [composer update] again to move each package to a release that is old enough.')
        ->and($trustFile)
        ->toContain('"minimum-release-age": 7')
        ->toContain('"acme/widget"');
});

it('holds back a recent release that vendor/ holds when it records the baseline with a minimum release age', function (): void {
    $project = PendingUpdate::create();
    $project->installedReleasedAt(daysAgo(1));

    try {
        $status = vet(['--fresh' => true, '--minimum-release-age' => '2', '--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('too recent (1)')
        ->toContain('Vet now holds back each release younger than [2] days.');
});

it('refuses a minimum release age option without --init', function (): void {
    $project = PendingUpdate::create();

    try {
        $status = vet(['--minimum-release-age' => '7', '--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--minimum-release-age] option needs [--init]. Run [./vendor/bin/vet --init --minimum-release-age=7].');
});

it('refuses a minimum release age option that is not a whole number of days', function (string $days): void {
    $project = PendingUpdate::create();

    try {
        $status = vet(['--init' => true, '--minimum-release-age' => $days, '--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('The [--minimum-release-age] option needs a whole number of days, such as [7].');
})->with(['seven', '0', '-1', '1.5']);
