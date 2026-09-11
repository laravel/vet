<?php

declare(strict_types=1);

use App\Exceptions\InvalidJsonException;
use App\ValueObjects\LockFile;
use App\ValueObjects\Project;
use Illuminate\Support\Facades\File;

it('skips a locked entry that is not an object or that carries no name', function (): void {
    $root = sys_get_temp_dir().'/vet-lock-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($root);
    file_put_contents($root.'/composer.lock', '{"packages":["junk",{"version":"1.0.0"},{"name":"acme/widget","version":"1.0.0"}],"packages-dev":[]}');

    try {
        $lock = LockFile::fromProject(Project::at($root));
    } finally {
        File::deleteDirectory($root);
    }

    expect(array_keys($lock->packages()))->toBe(['acme/widget']);
});

it('names a lock file whose entries carry no name', function (): void {
    $root = sys_get_temp_dir().'/vet-lock-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($root);
    file_put_contents($root.'/composer.lock', '{"packages":["junk"],"packages-dev":[{"version":"1.0.0"}]}');

    try {
        expect(fn (): LockFile => LockFile::fromProject(Project::at($root)))
            ->toThrow(InvalidJsonException::class, 'the lock file lists no packages.');
    } finally {
        File::deleteDirectory($root);
    }
});
