<?php

declare(strict_types=1);

use App\Exceptions\EmptyTreeException;
use App\Exceptions\FailureException;
use App\ValueObjects\Manifest;

it('names a directory of the tree that it cannot read', function (): void {
    $directory = manifestTree();

    mkdir($directory.'/locked');
    file_put_contents($directory.'/locked/Secret.php', "<?php\n");
    file_put_contents($directory.'/Widget.php', "<?php\n");
    chmod($directory.'/locked', 0o000);

    try {
        expect(fn (): Manifest => Manifest::ofDirectory($directory))
            ->toThrow(FailureException::class, sprintf('Could not read the directory [%s].', $directory.'/locked'));
    } finally {
        chmod($directory.'/locked', 0o755);
        removeManifestTree($directory);
    }
});

it('names a file of the tree that it cannot read', function (): void {
    $directory = manifestTree();

    file_put_contents($directory.'/Widget.php', "<?php\n");
    chmod($directory.'/Widget.php', 0o000);

    try {
        expect(fn (): Manifest => Manifest::ofDirectory($directory))
            ->toThrow(FailureException::class, sprintf('Could not read the file [%s].', $directory.'/Widget.php'));
    } finally {
        chmod($directory.'/Widget.php', 0o644);
        removeManifestTree($directory);
    }
});

function manifestTree(): string
{
    $path = sys_get_temp_dir().'/vet-manifest-'.bin2hex(random_bytes(6));

    mkdir($path, 0o777, true);

    return $path;
}

function removeManifestTree(string $path): void
{
    exec('rm -rf '.escapeshellarg($path));
}

it('records the target of a symlink rather than the bytes that it points to', function (): void {
    $directory = manifestTree();

    file_put_contents($directory.'/real.txt', "the bytes of the file\n");
    symlink('real.txt', $directory.'/link.txt');

    try {
        $entries = Manifest::ofDirectory($directory)->entries();
    } finally {
        removeManifestTree($directory);
    }

    expect($entries['link.txt'])->toBe(hash('sha256', 'real.txt'))
        ->and($entries['link.txt'])->not->toBe($entries['real.txt']);
});

it('records the target of a symlink that points outside the tree', function (): void {
    $directory = manifestTree();

    file_put_contents($directory.'/keep.txt', "a file\n");
    symlink('../../../../etc/passwd', $directory.'/escape.txt');

    try {
        $manifest = Manifest::ofDirectory($directory);
    } finally {
        removeManifestTree($directory);
    }

    expect($manifest->count())->toBe(2)
        ->and($manifest->entries()['escape.txt'])->toBe(hash('sha256', '../../../../etc/passwd'));
});

it('walks into no directory that a symlink points at', function (): void {
    $directory = manifestTree();

    mkdir($directory.'/src');
    file_put_contents($directory.'/src/Widget.php', "<?php\n");
    symlink('.', $directory.'/loop');

    try {
        $manifest = Manifest::ofDirectory($directory);
    } finally {
        removeManifestTree($directory);
    }

    expect($manifest->count())->toBe(2)
        ->and($manifest->entries())->toHaveKey('loop')
        ->and($manifest->entries()['loop'])->toBe(hash('sha256', '.'));
});

it('refuses to hash a directory that holds no file', function (): void {
    $directory = manifestTree();

    mkdir($directory.'/src');

    try {
        expect(fn (): Manifest => Manifest::ofDirectory($directory))
            ->toThrow(EmptyTreeException::class, 'refusing to hash an empty tree');
    } finally {
        removeManifestTree($directory);
    }
});

it('refuses to hash a directory that does not exist', function (): void {
    $directory = sys_get_temp_dir().'/vet-manifest-'.bin2hex(random_bytes(6));

    expect(fn (): Manifest => Manifest::ofDirectory($directory))
        ->toThrow(EmptyTreeException::class, 'does not exist');
});

it('sorts each entry by the bytes of its path', function (): void {
    $directory = manifestTree();

    mkdir($directory.'/src');
    file_put_contents($directory.'/src/Widget.php', "<?php\n");
    file_put_contents($directory.'/src-extra.php', "<?php\n");

    try {
        $paths = array_keys(Manifest::ofDirectory($directory)->entries());
    } finally {
        removeManifestTree($directory);
    }

    expect($paths)->toBe(['src-extra.php', 'src/Widget.php']);
});

it('counts the bytes of each file and none of a symlink', function (): void {
    $directory = manifestTree();

    file_put_contents($directory.'/real.txt', str_repeat('a', 100));
    symlink('real.txt', $directory.'/link.txt');

    try {
        $manifest = Manifest::ofDirectory($directory);
    } finally {
        removeManifestTree($directory);
    }

    expect($manifest->count())->toBe(2)
        ->and($manifest->bytes())->toBe(100);
});

it('gives two trees that a file name splits two hashes', function (): void {
    $forged = manifestTree();
    $real = manifestTree();

    $bytes = "<?php system('id');\n";

    file_put_contents($forged.'/README.md', "hello\n");
    file_put_contents($forged."/a.txt\n".hash('sha256', $bytes).'  evil.php', '');

    file_put_contents($real.'/README.md', "hello\n");
    file_put_contents($real.'/a.txt', '');
    file_put_contents($real.'/evil.php', $bytes);

    try {
        $forgedHash = (string) Manifest::ofDirectory($forged)->hash();
        $realHash = (string) Manifest::ofDirectory($real)->hash();
    } finally {
        removeManifestTree($forged);
        removeManifestTree($real);
    }

    expect($forgedHash)->not->toBe($realHash);
});

it('keeps the hash of a tree whose paths hold no escape', function (): void {
    $directory = manifestTree();

    mkdir($directory.'/src');
    file_put_contents($directory.'/src/Widget.php', "<?php\n");

    try {
        $hash = (string) Manifest::ofDirectory($directory)->hash();
    } finally {
        removeManifestTree($directory);
    }

    expect($hash)->toBe('tree-v2:'.hash('sha256', hash('sha256', "<?php\n").'  src/Widget.php'."\n"));
});
