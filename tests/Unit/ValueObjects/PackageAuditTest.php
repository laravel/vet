<?php

declare(strict_types=1);

use App\Enums\AuditStatus;
use App\Enums\InstallSourceType;
use App\Enums\PackageStatus;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\TreeHash;

it('says that the trust file never trusted a package', function (): void {
    $hash = TreeHash::fromManifest('incoming');

    $audit = new PackageAudit(
        package: 'acme/widget',
        version: '2.0.0',
        hash: $hash,
        dev: false,
        status: AuditStatus::Ungranted,
        files: 1,
        bytes: 1,
        grant: null,
        source: InstallSourceType::Dist,
        state: PackageStatus::Pending,
        from: null,
        cause: null,
        path: null,
    );

    expect($audit->note())->toBe('never trusted');
});
