<?php

declare(strict_types=1);

use App\Actions\ExpandMinifiedMetadata;

it('carries each key of the version before into the next version', function (): void {
    expect(ExpandMinifiedMetadata::handle([
        ['name' => 'acme/widget', 'version' => '2.0.0', 'type' => 'library', 'dist' => ['url' => 'https://packages.test/2.0.0.zip']],
        ['version' => '1.0.0', 'dist' => ['url' => 'https://packages.test/1.0.0.zip']],
    ]))->toBe([
        ['name' => 'acme/widget', 'version' => '2.0.0', 'type' => 'library', 'dist' => ['url' => 'https://packages.test/2.0.0.zip']],
        ['name' => 'acme/widget', 'version' => '1.0.0', 'type' => 'library', 'dist' => ['url' => 'https://packages.test/1.0.0.zip']],
    ]);
});

it('drops a key that a version marks as unset', function (): void {
    expect(ExpandMinifiedMetadata::handle([
        ['name' => 'acme/widget', 'version' => '3.0.0', 'bin' => '__unset'],
        ['version' => '2.0.0', 'bin' => ['bin/widget']],
        ['version' => '1.0.0', 'bin' => '__unset'],
    ]))->toBe([
        ['name' => 'acme/widget', 'version' => '3.0.0'],
        ['name' => 'acme/widget', 'version' => '2.0.0', 'bin' => ['bin/widget']],
        ['name' => 'acme/widget', 'version' => '1.0.0'],
    ]);
});

it('skips an entry that is not an object', function (): void {
    expect(ExpandMinifiedMetadata::handle([
        'not a version',
        ['name' => 'acme/widget', 'version' => '1.0.0'],
    ]))->toBe([
        ['name' => 'acme/widget', 'version' => '1.0.0'],
    ]);
});

it('reads a document as minified only when composer 2 minifies it', function (array $document, bool $minified): void {
    expect(ExpandMinifiedMetadata::isMinified($document))->toBe($minified);
})->with([
    'composer 2' => [['minified' => 'composer/2.0'], true],
    'a later format' => [['minified' => 'composer/3.0'], false],
    'no marker' => [['packages' => []], false],
]);
