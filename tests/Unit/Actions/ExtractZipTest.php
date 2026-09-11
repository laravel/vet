<?php

declare(strict_types=1);

use App\Actions\ExtractZip;
use App\Exceptions\FailureException;
use Tests\Fixtures\Access;
use Tests\Fixtures\CraftedArchive;
use Tests\Fixtures\Warnings;

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

it('refuses an archive that holds an absolute path', function (string $name): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php\n"],
        ['name' => $name, 'contents' => "<?php // outside\n"],
    ]);

    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, sprintf('contains an absolute path [%s]', $name));
    } finally {
        removeExtraction($archive, $destination);
    }
})->with([
    '/tmp/escape.php',
    'C:/Windows/escape.php',
]);

it('keeps each path of an archive that holds no common root directory', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'composer.json', 'contents' => "{}\n"],
        ['name' => 'src/Widget.php', 'contents' => "<?php // the widget\n"],
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

it('refuses an archive that holds no entry', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), []);
    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, sprintf('The archive [%s] is empty.', $archive));
    } finally {
        removeExtraction($archive, $destination);
    }
});

it('refuses an archive that holds directories and no file', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/', 'contents' => ''],
        ['name' => 'widget/src/', 'contents' => ''],
    ]);

    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, sprintf('The archive [%s] contained no files.', $archive));
    } finally {
        removeExtraction($archive, $destination);
    }
});

it('refuses a file that is not an archive', function (): void {
    $archive = craftedArchivePath();
    $destination = extractionPath();

    file_put_contents($archive, 'not a zip');

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, sprintf('Could not open the archive [%s]', $archive));
    } finally {
        removeExtraction($archive, $destination);
    }
});

it('names the file that it cannot write', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/Widget.php', 'contents' => "<?php\n"],
    ]);

    $destination = extractionPath();

    mkdir($destination);
    Access::denyWrite($destination);

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, sprintf('Could not write to [%s/Widget.php].', $destination));
    } finally {
        Access::restore($destination);
        removeExtraction($archive, $destination);
    }
});

it('names the directory that it cannot create', function (): void {
    $archive = CraftedArchive::write(craftedArchivePath(), [
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php\n"],
    ]);

    $destination = extractionPath();

    mkdir($destination);
    Access::denyWrite($destination);

    try {
        expect(static function () use ($archive, $destination): void {
            Warnings::silenced(static fn (): int => ExtractZip::handle($archive, $destination));
        })->toThrow(FailureException::class, sprintf('Could not create the directory [%s/src].', $destination));
    } finally {
        Access::restore($destination);
        removeExtraction($archive, $destination);
    }
});

it('names the entry whose compression it cannot read', function (): void {
    $archive = CraftedArchive::writeWithMethod(craftedArchivePath(), [
        ['name' => 'widget/src/Widget.php', 'contents' => "<?php\n"],
    ], CraftedArchive::IMPLODED);

    $destination = extractionPath();

    try {
        expect(fn (): int => ExtractZip::handle($archive, $destination))
            ->toThrow(FailureException::class, sprintf('Could not read [widget/src/Widget.php] from the archive [%s].', $archive));
    } finally {
        removeExtraction($archive, $destination);
    }
});
