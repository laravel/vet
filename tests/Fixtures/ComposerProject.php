<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Json;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final readonly class ComposerProject
{
    public const string PACKAGE = 'acme/widget';

    public const string OLD_VERSION = '1.0.0';

    public const string YOUNG_VERSION = '1.1.0';

    private function __construct(
        public string $rootPath,
        private string $basePath,
    ) {}

    public static function create(string $trustFile): self
    {
        $base = sys_get_temp_dir().'/vet-composer-'.bin2hex(random_bytes(6));
        $project = new self($base.'/project', $base);

        File::ensureDirectoryExists($base.'/widget');
        File::ensureDirectoryExists($project->rootPath);

        File::put($base.'/widget/composer.json', Json::encode(['name' => self::PACKAGE]));
        File::put($base.'/widget/Widget.php', "<?php\n");
        File::put($project->rootPath.'/composer.json', Json::encode($project->manifest()));
        File::put($project->rootPath.'/vet.json', $trustFile);

        return $project;
    }

    public function composer(string ...$arguments): Process
    {
        $process = new Process(
            [...$this->composerBinary(), ...$arguments, '--no-interaction', '--no-ansi'],
            $this->rootPath,
            [
                'COMPOSER' => false,
                'COMPOSER_HOME' => $this->basePath.'/home',
                'COMPOSER_CACHE_DIR' => $this->basePath.'/cache',
                'COMPOSER_VENDOR_DIR' => false,
                'COMPOSER_BIN_DIR' => false,
                'VET_INSIDE_COMPOSER' => false,
                'XDEBUG_MODE' => 'off',
            ],
        );

        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    public function lockedVersion(): string
    {
        return $this->versionIn('composer.lock', 'packages');
    }

    public function installedVersion(): string
    {
        return $this->versionIn('vendor/composer/installed.json', 'packages');
    }

    public function remove(): void
    {
        File::deleteDirectory($this->basePath);
    }

    /**
     * @return list<string>
     */
    private function composerBinary(): array
    {
        $binary = getenv('COMPOSER_BINARY');

        return is_string($binary) && $binary !== '' ? [PHP_BINARY, $binary] : ['composer'];
    }

    private function versionIn(string $relative, string $section): string
    {
        $document = Json::readFile($this->rootPath.'/'.$relative, $relative);
        $packages = $document[$section] ?? [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && ($package['name'] ?? null) === self::PACKAGE && is_string($package['version'] ?? null)) {
                return $package['version'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'name' => 'acme/app',
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => dirname(__DIR__, 2),
                    'options' => ['symlink' => true, 'versions' => ['laravel/vet' => '0.9.0']],
                ],
                [
                    'type' => 'package',
                    'package' => [
                        $this->release(self::OLD_VERSION, 30),
                        $this->release(self::YOUNG_VERSION, 1),
                    ],
                ],
                ['packagist.org' => false],
            ],
            'require' => ['laravel/vet' => '0.9.0', self::PACKAGE => '^1.0'],
            'config' => ['allow-plugins' => ['laravel/vet' => true]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $version, int $daysAgo): array
    {
        return [
            'name' => self::PACKAGE,
            'version' => $version,
            'time' => new DateTimeImmutable(sprintf('-%d days', $daysAgo))->format(DATE_ATOM),
            'dist' => ['type' => 'path', 'url' => $this->basePath.'/widget'],
        ];
    }
}
