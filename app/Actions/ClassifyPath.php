<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BucketType;
use App\ValueObjects\Package;

final readonly class ClassifyPath
{
    private const array OPAQUE_EXTENSIONS = [
        'phar', 'so', 'dll', 'exe', 'dylib', 'a', 'o', 'obj', 'lib',
        'wasm', 'node', 'jar', 'class', 'pyc', 'bin', 'msi', 'deb', 'rpm',
    ];

    private const array EXECUTABLE_EXTENSIONS = [
        'sh', 'bash', 'zsh', 'fish', 'bat', 'cmd', 'ps1',
    ];

    private const array EXECUTABLE_NAMES = [
        'dockerfile', 'containerfile', 'makefile', 'gnumakefile',
        'docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml',
    ];

    private const array MEDIA_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'svg', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'mp3', 'mp4', 'wav', 'ogg', 'webm', 'pdf',
    ];

    /**
     * @param  array<int, string>  $runtimeRoots
     * @param  bool  $wholePackage  an autoload prefix maps to the package root
     */
    public function __construct(
        private array $runtimeRoots,
        private bool $wholePackage = false,
    ) {}

    public static function forPackages(Package ...$packages): self
    {
        $roots = [];
        $wholePackage = false;

        foreach ($packages as $package) {
            foreach ($package->runtimeRoots() as $root) {
                $roots[] = $root;
            }

            $wholePackage = $wholePackage || $package->autoloadsPackageRoot();
        }

        return new self(array_values(array_unique($roots)), $wholePackage);
    }

    /**
     * @param  string  $path  relative to the package root
     * @param  string|null  $file  absolute path to the file, when it exists on disk
     */
    public function handle(string $path, ?string $file = null): BucketType
    {
        if ($this->isOpaque($path, $file)) {
            return BucketType::Opaque;
        }

        if ($path === 'composer.json') {
            return BucketType::InstallManifest;
        }

        if ($this->isRuntime($path) || $this->isExecutable($path, $file)) {
            return BucketType::RuntimeSource;
        }

        return BucketType::Inert;
    }

    private function isExecutable(string $path, ?string $file): bool
    {
        $name = mb_strtolower(basename($path));

        if (in_array($name, self::EXECUTABLE_NAMES, true)) {
            return true;
        }

        if (str_starts_with($name, 'dockerfile.') || str_ends_with($name, '.dockerfile')) {
            return true;
        }

        if (in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXECUTABLE_EXTENSIONS, true)) {
            return true;
        }

        return $this->startsWithShebang($file);
    }

    private function startsWithShebang(?string $file): bool
    {
        if ($file === null || ! is_file($file)) {
            return false;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 2);
        fclose($handle);

        return $head === '#!';
    }

    private function isOpaque(string $path, ?string $file): bool
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::OPAQUE_EXTENSIONS, true)) {
            return true;
        }

        if (in_array($extension, self::MEDIA_EXTENSIONS, true)) {
            return false;
        }

        if ($file === null || ! is_file($file)) {
            return false;
        }

        return $this->looksMinified($extension, $file) || $this->looksBinary($file);
    }

    private function looksMinified(string $extension, string $file): bool
    {
        if (! in_array($extension, ['js', 'css', 'mjs', 'cjs'], true)) {
            return false;
        }

        $size = (int) @filesize($file);

        if ($size < 20_000) {
            return false;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        $lines = 0;
        $longest = 0;

        while (($line = fgets($handle)) !== false && $lines < 200) {
            $lines++;
            $longest = max($longest, mb_strlen($line));
        }

        fclose($handle);

        return $longest > 1000 || ($lines < 5 && $size > 20_000);
    }

    private function looksBinary(string $file): bool
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 8000);
        fclose($handle);

        return $head !== '' && str_contains($head, "\0");
    }

    private function isRuntime(string $path): bool
    {
        if ($this->wholePackage && str_ends_with(mb_strtolower($path), '.php')) {
            return true;
        }

        return array_any($this->runtimeRoots, fn (string $root): bool => $path === $root || str_starts_with($path, $root.'/'));
    }
}
