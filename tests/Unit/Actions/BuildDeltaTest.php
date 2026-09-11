<?php

declare(strict_types=1);

use App\Actions\BuildDelta;
use App\Enums\InstallSourceType;
use App\ValueObjects\Package;
use Illuminate\Support\Facades\File;

it('reads no manifest change when one of the two trees holds no composer.json', function (): void {
    $directory = sys_get_temp_dir().'/vet-build-delta-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory.'/old/src');
    File::ensureDirectoryExists($directory.'/new/src');
    file_put_contents($directory.'/old/src/Widget.php', "<?php // one\n");
    file_put_contents($directory.'/new/src/Widget.php', "<?php // two\n");
    file_put_contents($directory.'/new/composer.json', '{"name":"acme/widget","scripts":{"post-install-cmd":"run"}}');

    $package = Package::fromLockEntry([
        'name' => 'acme/widget',
        'version' => '1.0.0',
        'autoload' => ['psr-4' => ['Acme\\Widget\\' => 'src/']],
    ], false);

    try {
        $delta = (new BuildDelta)->handle(
            package: 'acme/widget',
            fromVersion: '1.0.0',
            fromDirectory: $directory.'/old',
            fromMetadata: $package,
            toVersion: '2.0.0',
            toDirectory: $directory.'/new',
            toMetadata: $package,
            source: InstallSourceType::Dist,
        );
    } finally {
        File::deleteDirectory($directory);
    }

    expect($delta->manifestChange)->toBeNull()
        ->and($delta->changes())->toHaveCount(2);
});
