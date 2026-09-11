<?php

declare(strict_types=1);

use App\ValueObjects\Project;
use Illuminate\Support\Facades\File;

it('reads an absolute vendor directory that composer.json configures', function (): void {
    $root = sys_get_temp_dir().'/vet-project-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($root);
    file_put_contents($root.'/composer.json', '{"config":{"vendor-dir":"/opt/acme/../vendor"}}');

    try {
        $vendor = Project::at($root)->vendorPath();
    } finally {
        File::deleteDirectory($root);
    }

    expect($vendor)->toBe('/opt/vendor');
});
