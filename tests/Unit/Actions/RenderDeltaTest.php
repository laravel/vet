<?php

declare(strict_types=1);

use App\Actions\RenderDelta;
use App\Enums\BucketType;
use App\Enums\ChangeStatus;
use App\Enums\Gutter;
use App\Enums\InstallSourceType;
use App\Enums\PatchExtent;
use App\Support\Invitation;
use App\ValueObjects\Change;
use App\ValueObjects\Delta;
use App\ValueObjects\TreeHash;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Fixtures\Access;

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
        toIsLocalInstall: false,
        notes: $notes,
    );

    new RenderDelta(new OutputStyle(new ArrayInput([]), $buffer), Invitation::toReadTheInstalledTree(), Gutter::None, PatchExtent::Full)->report($delta);

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

it('says that it cannot read a file whose bytes it cannot open', function (): void {
    $file = sys_get_temp_dir().'/vet-render-'.bin2hex(random_bytes(6));

    file_put_contents($file, "<?php\n");
    Access::denyRead($file);

    try {
        $output = renderedDeltaReport([new Change(
            path: 'src/Locked.php',
            status: ChangeStatus::Modified,
            bucket: BucketType::RuntimeSource,
            oldHash: 'old',
            newHash: 'new',
            oldFile: $file,
            newFile: null,
        )], []);
    } finally {
        Access::restore($file);
        unlink($file);
    }

    expect($output)->toContain('vet cannot read this file, so its bytes are not shown');
});

/**
 * @return array<int, Change>
 */
function rewrittenFile(): array
{
    $directory = sys_get_temp_dir().'/vet-render-'.bin2hex(random_bytes(6));

    mkdir($directory);
    file_put_contents($directory.'/old.php', implode("\n", array_map(static fn (int $line): string => 'line '.$line, range(1, 60)))."\n");
    file_put_contents($directory.'/new.php', implode("\n", array_map(static fn (int $line): string => 'new '.$line, range(1, 60)))."\n");

    register_shutdown_function(static function () use ($directory): void {
        File::deleteDirectory($directory);
    });

    return [new Change(
        path: 'src/File.php',
        status: ChangeStatus::Modified,
        bucket: BucketType::RuntimeSource,
        oldHash: 'old',
        newHash: 'new',
        oldFile: $directory.'/old.php',
        newFile: $directory.'/new.php',
    )];
}

function renderedBuckets(PatchExtent $extent, bool $verbose): string
{
    $buffer = new BufferedOutput;
    $buffer->setVerbosity($verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL);

    $delta = new Delta(
        package: 'acme/widget',
        from: '1.0.0',
        to: '2.0.0',
        fromHash: TreeHash::fromManifest('the trusted tree'),
        toHash: TreeHash::fromManifest('the installed tree'),
        source: InstallSourceType::Dist,
        changes: rewrittenFile(),
        manifestChange: null,
        firstInstall: false,
        toIsLocalInstall: false,
        notes: [],
    );

    new RenderDelta(new OutputStyle(new ArrayInput([]), $buffer), Invitation::toReadTheInstalledTree(), Gutter::Package, $extent)->buckets($delta);

    return $buffer->fetch();
}

it('stops an abridged patch after forty lines, and names the command that shows the rest', function (): void {
    $output = renderedBuckets(PatchExtent::Abridged, false);

    expect($output)
        ->toContain('│     -line 39')
        ->toContain('│   … and 81 more lines, with [vet acme/widget]')
        ->and(str_contains($output, '+new 1'))->toBeFalse();
});

it('shows every line of an abridged patch with -v', function (): void {
    $output = renderedBuckets(PatchExtent::Abridged, true);

    expect($output)
        ->toContain('│     +new 60')
        ->and(str_contains($output, 'more lines'))->toBeFalse();
});
