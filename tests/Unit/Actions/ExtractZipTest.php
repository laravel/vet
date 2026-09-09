<?php

declare(strict_types=1);

use App\Actions\ExtractZip;
use App\Exceptions\FailureException;
use Tests\Fixtures\CraftedArchive;

function craftedArchivePath(): string
{
    return sys_get_temp_dir().'/vet-archive-'.bin2hex(random_bytes(6)).'.zip';
}

function extractionPath(): string
{
    return sys_get_temp_dir().'/vet-extract-'.bin2hex(random_bytes(6));
}

function removeExtraction(string $archive, string $destination): void
{
    @unlink($archive);

    exec('rm -rf '.escapeshellarg($destination));
}

it('refuses an archive that holds two entries of one name', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php // the reviewed bytes\n"],
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php // the installed bytes\n"],
    ]);

    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, 'writes two entries to [src/Widget.php]');
    } finally {
        removeExtraction($archive, $destination);
    }
});

it('refuses an archive that writes two entries to one path', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php // the reviewed bytes\n"],
        ['name' => 'widget/src/./Widget.php', 'contents' => "<?php // the installed bytes\n"],
    ]);

    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, 'writes two entries to');
    } finally {
        removeExtraction($archive, $destination);
    }
});

it('reads the bytes of the entry at each index', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/composer.json', 'contents' => "{}\n"],
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php // the widget\n"],
    ]);

    $destination = extractionPath();

    try {
        $written = ExtractZip::handle($archive, $destination);

        expect($written)->toBe(2)
            ->and(file_get_contents($destination.'/composer.json'))->toBe("{}\n")
            ->and(file_get_contents($destination.'/src/Widget.php'))->toBe("<?php // the widget\n");
    } finally {
        removeExtraction($archive, $destination);
    }
});

it('refuses an archive that traverses out of the destination', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php\n"],
        ['name' => 'widget/../../escape.php', 'contents' => "<?php // outside\n"],
    ]);

    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, 'traversing path');
    } finally {
        removeExtraction($archive, $destination);
    }
});
