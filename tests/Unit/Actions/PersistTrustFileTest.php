<?php

declare(strict_types=1);

use App\Actions\PersistTrustFile;
use App\Exceptions\FailureException;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\Warnings;

function trustFileDirectory(): string
{
    $directory = sys_get_temp_dir().'/vet-trust-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

it('keeps a key of the vet file that it does not know after the keys that it orders', function (): void {
    $directory = trustFileDirectory();

    file_put_contents($directory.'/vet.json', '{"comment":"kept","require":{},"schema":4}');

    try {
        PersistTrustFile::atPath($directory.'/vet.json')->write(['require-dev' => (object) []]);

        $written = json_decode((string) file_get_contents($directory.'/vet.json'), true);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($written)->toBeArray()
        ->and(array_keys((array) $written))->toBe(['schema', 'require', 'require-dev', 'comment']);
});

it('names the directory of the vet file that it cannot create', function (): void {
    $directory = trustFileDirectory();

    file_put_contents($directory.'/project', 'a file where a directory belongs');

    $document = PersistTrustFile::atPath($directory.'/project/vet.json');

    try {
        expect(static function () use ($document): void {
            Warnings::silenced(static function () use ($document): void {
                $document->write([]);
            });
        })->toThrow(FailureException::class, sprintf('Could not create the directory [%s/project].', $directory));
    } finally {
        File::deleteDirectory($directory);
    }
});

it('names the vet file that it cannot write', function (): void {
    $directory = trustFileDirectory();

    File::ensureDirectoryExists($directory.'/vet.json');

    $document = PersistTrustFile::atPath($directory.'/vet.json');

    try {
        expect(static function () use ($document): void {
            $document->write([]);
        })->toThrow(FailureException::class, sprintf('Could not write the vet file to [%s/vet.json].', $directory));
    } finally {
        File::deleteDirectory($directory);
    }
});

it('names no schema for a vet file that declares none', function (): void {
    $directory = trustFileDirectory();

    file_put_contents($directory.'/vet.json', '{"require":{}}');

    try {
        expect(static fn (): PersistTrustFile => PersistTrustFile::atPath($directory.'/vet.json'))
            ->toThrow(FailureException::class, sprintf('The vet file [%s/vet.json] declares schema [none]; this build of vet reads schema [4].', $directory));
    } finally {
        File::deleteDirectory($directory);
    }
});
