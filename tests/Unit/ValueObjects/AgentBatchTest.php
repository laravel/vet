<?php

declare(strict_types=1);

use App\Enums\BucketType;
use App\Enums\ChangeStatus;
use App\Enums\InstallSourceType;
use App\ValueObjects\AgentBatch;
use App\ValueObjects\AgentPrompt;
use App\ValueObjects\Change;
use App\ValueObjects\Delta;
use App\ValueObjects\TreeHash;

/**
 * @return array<string, AgentPrompt>
 */
function prompts(int $count, int $bytes): array
{
    $prompts = [];

    for ($index = 1; $index <= $count; $index++) {
        $prompts['acme/package-'.$index] = agentPrompt(str_repeat('a', $bytes), [], ['src/File.php']);
    }

    return $prompts;
}

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

it('fits one run with 20 short prompts', function (): void {
    $batch = new AgentBatch(prompts(AgentBatch::MAX_PROMPTS, 100));

    expect($batch->count())->toBe(20)
        ->and($batch->fitsOneRun())->toBeTrue();
});

it('does not fit one run with 21 prompts', function (): void {
    $batch = new AgentBatch(prompts(AgentBatch::MAX_PROMPTS + 1, 100));

    expect($batch->fitsOneRun())->toBeFalse();
});

it('does not fit one run when the prompts hold more than the byte budget', function (): void {
    $batch = new AgentBatch(prompts(5, 500_000));

    expect($batch->bytes())->toBe(2_500_000)
        ->and($batch->fitsOneRun())->toBeFalse();
});

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
        ->and($batch->count())->toBe(0)
        ->and($batch->fitsOneRun())->toBeTrue();
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
