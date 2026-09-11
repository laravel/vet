<?php

declare(strict_types=1);

use App\Enums\BucketType;
use App\Enums\ChangeStatus;
use App\Enums\InstallSourceType;
use App\ValueObjects\AgentBatch;
use App\ValueObjects\Change;
use App\ValueObjects\Delta;
use App\ValueObjects\TreeHash;

/**
 * @param  array<int, Change>  $changes
 */
function batchDelta(string $package, array $changes): Delta
{
    return new Delta(
        package: $package,
        from: '1.0.0',
        to: '2.0.0',
        fromHash: TreeHash::fromManifest('one'),
        toHash: TreeHash::fromManifest('two'),
        source: InstallSourceType::Dist,
        changes: $changes,
        manifestChange: null,
        firstInstall: false,
        toIsLocalInstall: false,
        notes: [],
    );
}

it('sums the bytes of each prompt', function (): void {
    $batch = new AgentBatch([
        'acme/a' => agentPrompt('abc', [], []),
        'acme/b' => agentPrompt('défg', [], []),
    ]);

    expect($batch->bytes())->toBe(8);
});

it('is empty without a prompt', function (): void {
    $batch = AgentBatch::of(['acme/a' => null]);

    expect($batch->isEmpty())->toBeTrue()
        ->and($batch->count())->toBe(0);
});

it('holds one prompt for each delta that is not empty', function (): void {
    $inert = new Change(
        path: 'README.md',
        status: ChangeStatus::Modified,
        bucket: BucketType::Inert,
        oldHash: 'one',
        newHash: 'two',
        oldFile: null,
        newFile: null,
    );

    $batch = AgentBatch::of([
        'acme/none' => null,
        'acme/empty' => batchDelta('acme/empty', []),
        'acme/changed' => batchDelta('acme/changed', [$inert]),
    ]);

    expect(array_keys($batch->prompts))->toBe(['acme/changed']);
});
