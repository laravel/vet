<?php

declare(strict_types=1);

use App\Exceptions\InvalidJsonException;
use App\ValueObjects\InstalledRepository;
use App\ValueObjects\Project;
use Illuminate\Support\Facades\File;

it('skips an installed entry that is not an object or that carries no name', function (): void {
    $root = sys_get_temp_dir().'/vet-installed-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($root.'/vendor/composer');
    file_put_contents($root.'/composer.json', '{"name":"acme/app"}');
    file_put_contents($root.'/vendor/composer/installed.json', '{"packages":["junk",{"version":"1.0.0"},{"name":"acme/widget","version":"1.0.0"}]}');

    try {
        $installed = InstalledRepository::fromProject(Project::at($root));
    } finally {
        File::deleteDirectory($root);
    }

    expect(array_keys($installed->all()))->toBe(['acme/widget']);
});

it('names an installed package list whose entries carry no name', function (): void {
    $root = sys_get_temp_dir().'/vet-installed-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($root.'/vendor/composer');
    file_put_contents($root.'/composer.json', '{"name":"acme/app"}');
    file_put_contents($root.'/vendor/composer/installed.json', '{"packages":["junk",{"version":"1.0.0"}]}');

    try {
        expect(fn (): InstalledRepository => InstalledRepository::fromProject(Project::at($root)))
            ->toThrow(InvalidJsonException::class, 'no package entries carried a name.');
    } finally {
        File::deleteDirectory($root);
    }
});
