<?php

declare(strict_types=1);

use App\Enums\AuditStatus;
use App\Enums\PackageStatus;
use App\ValueObjects\PackageAudit;
use App\ValueObjects\TreeHash;

it('says that composer would install bytes that the trust file holds no entry for', function (): void {
    $hash = TreeHash::fromManifest('incoming');

    $audit = new PackageAudit(
        package: 'acme/widget',
        version: '2.0.0',
        hash: $hash,
        dev: false,
        status: AuditStatus::Ungranted,
        files: 1,
        bytes: 1,
        state: PackageStatus::Pending,
    );

    expect($audit->reason())->toBe(sprintf('composer would install these bytes; no entry in the trust file [%s]', $hash->short()));
});
