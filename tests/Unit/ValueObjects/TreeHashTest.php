<?php

declare(strict_types=1);

use App\Exceptions\FailureException;
use App\ValueObjects\TreeHash;

it('names the scheme and holds the full sha-256 digest', function (): void {
    $hash = TreeHash::fromManifest("abc  src/Widget.php\n");

    expect($hash->algorithm)->toBe('tree-v2')
        ->and($hash->digest)->toBe(hash('sha256', "abc  src/Widget.php\n"))
        ->and(mb_strlen($hash->digest))->toBe(64)
        ->and((string) $hash)->toStartWith('tree-v2:');
});

it('shortens the digest to twelve characters for display', function (): void {
    $hash = TreeHash::fromManifest("abc  src/Widget.php\n");

    expect($hash->short())->toBe(mb_substr($hash->digest, 0, 12));
});

it('refuses a truncated digest and the scheme that truncated it', function (): void {
    $truncated = mb_substr(hash('sha256', 'x'), 0, 32);

    expect(fn (): TreeHash => TreeHash::parse('tree-v1:'.$truncated))
        ->toThrow(FailureException::class, 'Unknown tree hash algorithm [tree-v1]')
        ->and(fn (): TreeHash => TreeHash::parse('tree-v2:'.$truncated))
        ->toThrow(FailureException::class, 'expected 64 lowercase hex characters');
});

it('refuses a tree hash that names no algorithm', function (): void {
    expect(fn (): TreeHash => TreeHash::parse('abcdef'))
        ->toThrow(FailureException::class, 'Malformed tree hash [abcdef]: expected "<algorithm>:<digest>".');
});

it('reads a full digest back', function (): void {
    $hash = TreeHash::fromManifest("abc  src/Widget.php\n");

    expect(TreeHash::parse((string) $hash)->equals($hash))->toBeTrue();
});
