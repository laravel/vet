<?php

declare(strict_types=1);

use App\Actions\RenderDelta;
use App\Enums\BucketType;
use App\Enums\ChangeStatus;
use App\Enums\Gutter;
use App\Enums\InstallSourceType;
use App\Support\Invitation;
use App\ValueObjects\Change;
use App\ValueObjects\Delta;
use App\ValueObjects\TreeHash;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  array<int, Change>  $changes
 * @param  array<int, string>  $notes
 */
function renderedDeltaReport(array $changes, array $notes): string
{
    $buffer = new BufferedOutput;

    $delta = new Delta(
        package: 'acme/widget',
        from: '1.0.0',
        to: '2.0.0',
        fromHash: TreeHash::fromManifest('the trusted tree'),
        toHash: TreeHash::fromManifest('the installed tree'),
        source: InstallSourceType::Dist,
        changes: $changes,
        manifestChange: null,
        firstInstall: false,
    )->withResolution(false, $notes);

    new RenderDelta(new OutputStyle(new ArrayInput([]), $buffer), Invitation::toReadTheInstalledTree(), Gutter::None)->report($delta);

    return $buffer->fetch();
}

it('writes each note of a delta, and says that no file differs', function (): void {
    expect(renderedDeltaReport([], ['[acme/widget] is installed from source.']))
        ->toContain('[acme/widget] is installed from source.')
        ->toContain('No files differ between [1.0.0] and [2.0.0].');
});

it('counts the changes that it does not show, and writes no patch of a change that holds no line', function (): void {
    $changes = array_map(static fn (int $index): Change => new Change(
        path: sprintf('src/File%d.php', $index),
        status: ChangeStatus::Modified,
        bucket: BucketType::RuntimeSource,
        oldHash: 'old',
        newHash: 'new',
        oldFile: null,
        newFile: null,
    ), range(1, 6));

    $output = renderedDeltaReport($changes, []);

    expect($output)
        ->toContain('src/File5.php')
        ->toContain('… and 1 more, with [vet -v]')
        ->toContain('[1] change(s) are not shown. Read them with [vet -v].')
        ->and(str_contains($output, '@@'))->toBeFalse();
});
