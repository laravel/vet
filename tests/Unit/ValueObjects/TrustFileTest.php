<?php

declare(strict_types=1);

use App\Actions\PersistTrustFile;
use App\ValueObjects\Grant;
use App\ValueObjects\TreeHash;
use App\ValueObjects\TrustFile;
use Illuminate\Support\Facades\File;

it('skips an entry of the trust file that is not a package object', function (): void {
    $root = sys_get_temp_dir().'/vet-trust-'.bin2hex(random_bytes(6));
    $hash = (string) TreeHash::fromManifest('widget');

    File::ensureDirectoryExists($root);
    file_put_contents($root.'/vet.json', (string) json_encode([
        'schema' => PersistTrustFile::SCHEMA,
        'require' => ['acme/widget' => '1.0.0'],
        'require-dev' => [['version' => '1.0.0', 'hash' => $hash]],
    ]));

    try {
        $trustFile = TrustFile::fromDocument(PersistTrustFile::atPath($root.'/vet.json'));
    } finally {
        File::deleteDirectory($root);
    }

    expect($trustFile->exists())->toBeTrue()
        ->and($trustFile->grantFor('acme/widget'))->toBeNull()
        ->and($trustFile->grantFor('0'))->toBeNull();
});

it('writes each grant that it records in its section, and an empty section as an object', function (): void {
    $root = sys_get_temp_dir().'/vet-trust-'.bin2hex(random_bytes(6));
    $hash = TreeHash::fromManifest('widget');

    File::ensureDirectoryExists($root);

    try {
        $trustFile = TrustFile::fromDocument(PersistTrustFile::atPath($root.'/vet.json'));

        $trustFile
            ->withGrant(new Grant('acme/widget', '1.0.0', $hash, false))
            ->withGrant(new Grant('acme/gadget', '2.0.0', $hash, false))
            ->save();

        $written = json_decode((string) file_get_contents($root.'/vet.json'), true);
    } finally {
        File::deleteDirectory($root);
    }

    expect($written)->toBe([
        'schema' => PersistTrustFile::SCHEMA,
        'require' => [
            'acme/gadget' => ['version' => '2.0.0', 'hash' => (string) $hash],
            'acme/widget' => ['version' => '1.0.0', 'hash' => (string) $hash],
        ],
        'require-dev' => [],
    ]);
});
