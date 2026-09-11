<?php

declare(strict_types=1);

use App\ValueObjects\AuditReport;

it('reads a project that installs no package as fully covered', function (): void {
    $report = new AuditReport([]);

    expect($report->total())->toBe(0)
        ->and($report->failing())->toBe([]);
});
