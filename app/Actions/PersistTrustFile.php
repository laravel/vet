<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\FailureException;
use App\Support\Json;
use App\ValueObjects\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

final readonly class PersistTrustFile
{
    private const array ORDER = [
        'require',
        'require-dev',
    ];

    /**
     * @param  array<string, mixed>  $contents
     */
    private function __construct(
        public string $path,
        private array $contents,
    ) {}

    public static function forProject(Project $project): self
    {
        return self::atPath($project->vetFilePath());
    }

    public static function atPath(string $path): self
    {
        if (! is_file($path)) {
            return new self($path, []);
        }

        $contents = Json::readFile($path, 'the vet file');

        unset($contents['schema']);

        return new self($path, $contents);
    }

    public static function clearGrants(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        $settings = Arr::except(Json::readFile($path, 'the vet file'), [...self::ORDER, 'schema']);

        if ($settings === []) {
            File::delete($path);

            return;
        }

        if (@file_put_contents($path, Json::encode($settings)) === false) {
            throw new FailureException(sprintf('Could not write the vet file to [%s].', $path));
        }
    }

    public function has(string $section): bool
    {
        return isset($this->contents[$section]);
    }

    public function value(string $key): mixed
    {
        return $this->contents[$key] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function section(string $section): array
    {
        return Json::array($this->contents, $section);
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    public function write(array $sections): void
    {
        $directory = dirname($this->path);

        File::ensureDirectoryExists($directory);

        if (! File::isDirectory($directory)) {
            throw new FailureException(sprintf('Could not create the directory [%s].', $directory));
        }

        $merged = [...self::atPath($this->path)->contents, ...$sections];

        $ordered = [];

        foreach (self::ORDER as $key) {
            if (array_key_exists($key, $merged)) {
                $ordered[$key] = $merged[$key];
            }
        }

        foreach ($merged as $key => $section) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $section;
            }
        }

        if (@file_put_contents($this->path, Json::encode($ordered)) === false) {
            throw new FailureException(sprintf('Could not write the vet file to [%s].', $this->path));
        }
    }
}
