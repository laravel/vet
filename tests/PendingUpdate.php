<?php

declare(strict_types=1);

namespace Tests;

use App\Actions\PersistTrustFile;
use App\Support\Json;
use App\ValueObjects\Manifest;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class PendingUpdate
{
    public const string PACKAGE = 'acme/widget';

    public const string TRUSTED_VERSION = '1.0.0';

    public const string TARGET_VERSION = '2.0.0';

    public const string PLAN = <<<'OUTPUT'
        Loading composer repositories with package information
        Updating dependencies
        Lock file operations: 1 install, 1 update, 1 removal
          - Removing acme/legacy (0.9.0)
          - Upgrading acme/widget (1.0.0 => 2.0.0)
          - Locking acme/gadget (3.0.0)
        Installing dependencies from lock file (including require-dev)
        Package operations: 1 install, 1 update, 1 removal
          - Removing acme/legacy (0.9.0)
          - Upgrading acme/widget (1.0.0 => 2.0.0)
          - Installing acme/gadget (3.0.0)
        OUTPUT;

    private const string TRUSTED_REFERENCE = 'aaaa1111';

    private const string TARGET_REFERENCE = 'bbbb2222';

    private function __construct(
        public string $rootPath,
        public string $cachePath,
    ) {}

    public static function create(string $plan = self::PLAN, int $exitCode = 0): self
    {
        $base = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));

        $project = new self($base.'/project', $base.'/cache');

        $project->seedReleases();
        $project->seedMetadata();
        $project->seedInstalledTree();
        $project->seedComposerFiles();
        $project->lockAt(self::TRUSTED_VERSION, self::TRUSTED_REFERENCE);
        $project->seedTrustFile();
        $project->seedComposer($plan, $exitCode);

        putenv('VET_CACHE_DIR='.$project->cachePath);

        return $project;
    }

    public function lockAt(string $version, ?string $reference = null): void
    {
        $entry = $this->metadataOf($version, $reference ?? self::TARGET_REFERENCE);

        $this->write($this->rootPath.'/composer.lock', Json::encode([
            'content-hash' => 'pending',
            'packages' => [$entry],
            'packages-dev' => [],
        ]));
    }

    public function lockWithoutDist(string $version): void
    {
        $entry = $this->metadataOf($version, self::TARGET_REFERENCE);

        unset($entry['dist']);

        $this->write($this->rootPath.'/composer.lock', Json::encode([
            'content-hash' => 'pending',
            'packages' => [$entry],
            'packages-dev' => [],
        ]));
    }

    public function planFile(string $change = 'upgrade', string $from = self::TRUSTED_VERSION, string $to = self::TARGET_VERSION): string
    {
        $path = $this->rootPath.'/composer-plan.json';

        $this->write($path, Json::encode([
            'operations' => [[
                'package' => self::PACKAGE,
                'change' => $change,
                'from' => $from,
                'to' => $to,
                'dist_url' => $this->distUrl($to),
                'dist_reference' => self::TARGET_REFERENCE,
            ]],
        ]));

        return $path;
    }

    public function targetHash(): string
    {
        return (string) Manifest::ofDirectory($this->releasePath(self::TARGET_VERSION, self::TARGET_REFERENCE))->hash();
    }

    public function rebuiltHash(): string
    {
        return (string) Manifest::ofDirectory($this->releasePath(self::TRUSTED_VERSION, self::TARGET_REFERENCE))->hash();
    }

    public function trustFile(): string
    {
        return (string) file_get_contents($this->rootPath.'/vet.json');
    }

    public function installedFile(): string
    {
        return $this->rootPath.'/vendor/acme/widget/src/Widget.php';
    }

    public function remove(): void
    {
        putenv('VET_CACHE_DIR');
        putenv('VET_COMPOSER_BINARY');

        $base = dirname($this->rootPath);

        if (! is_dir($base)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($base);
    }

    private function distUrl(string $version): string
    {
        return sprintf('https://example.test/acme-widget-%s.zip', $version);
    }

    private function widget(string $name): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Acme\\Widget;

            final class Widget
            {
                public function name(): string
                {
                    return '{$name}';
                }
            }

            PHP;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function packageManifest(array $extra = []): string
    {
        return Json::encode([
            'name' => self::PACKAGE,
            'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\Widget\\' => 'src/']],
            'bin' => ['bin/widget.phar'],
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataOf(string $version, string $reference): array
    {
        return [
            'name' => self::PACKAGE,
            'version' => $version,
            'type' => 'library',
            'dist' => [
                'type' => 'zip',
                'url' => $this->distUrl($version),
                'reference' => $reference,
                'shasum' => '',
            ],
            'autoload' => [
                'psr-4' => ['Acme\\Widget\\' => 'src/'],
            ],
            'bin' => ['bin/widget.phar'],
        ];
    }

    private function releasePath(string $version, string $reference): string
    {
        $key = mb_substr(hash('sha256', $this->distUrl($version).'|'.$reference), 0, 16);

        return sprintf('%s/archives/%s/%s-%s', $this->cachePath, self::PACKAGE, $version, $key);
    }

    private function seedReleases(): void
    {
        $trusted = $this->releasePath(self::TRUSTED_VERSION, self::TRUSTED_REFERENCE);

        $this->write($trusted.'/composer.json', $this->packageManifest());
        $this->write($trusted.'/src/Widget.php', $this->widget('widget'));
        $this->write($trusted.'/bin/widget.phar', "PHAR widget OPAQUE BYTES\n");
        $this->write($trusted.'/tests/WidgetTest.php', "<?php // the widget test\n");
        $this->write($trusted.'.complete', self::TRUSTED_VERSION."\n");

        $target = $this->releasePath(self::TARGET_VERSION, self::TARGET_REFERENCE);

        $this->write($target.'/composer.json', $this->packageManifest([
            'scripts' => ['post-install-cmd' => ['Acme\\Widget\\Installer::run']],
        ]));
        $this->write($target.'/src/Widget.php', $this->widget('gadget'));
        $this->write($target.'/bin/widget.phar', "PHAR gadget OPAQUE BYTES\n");
        $this->write($target.'/tests/WidgetTest.php', "<?php // the gadget test\n");
        $this->write($target.'.complete', self::TARGET_VERSION."\n");

        $rebuilt = $this->releasePath(self::TRUSTED_VERSION, self::TARGET_REFERENCE);

        $this->write($rebuilt.'/composer.json', $this->packageManifest());
        $this->write($rebuilt.'/src/Widget.php', $this->widget('rebuilt widget'));
        $this->write($rebuilt.'/bin/widget.phar', "PHAR widget OPAQUE BYTES\n");
        $this->write($rebuilt.'/tests/WidgetTest.php', "<?php // the widget test\n");
        $this->write($rebuilt.'.complete', self::TRUSTED_VERSION."\n");
    }

    private function seedMetadata(): void
    {
        $this->write($this->cachePath.'/metadata/'.self::PACKAGE.'/index.json', Json::encode([
            'packages' => [
                self::PACKAGE => [
                    $this->metadataOf(self::TARGET_VERSION, self::TARGET_REFERENCE),
                    $this->metadataOf(self::TRUSTED_VERSION, self::TRUSTED_REFERENCE),
                ],
            ],
        ]));
    }

    private function seedInstalledTree(): void
    {
        $directory = $this->rootPath.'/vendor/acme/widget';

        $this->write($directory.'/composer.json', $this->packageManifest());
        $this->write($directory.'/src/Widget.php', $this->widget('widget'));
        $this->write($directory.'/bin/widget.phar', "PHAR widget OPAQUE BYTES\n");
        $this->write($directory.'/tests/WidgetTest.php', "<?php // the widget test\n");
    }

    private function seedComposerFiles(): void
    {
        $entry = $this->metadataOf(self::TRUSTED_VERSION, self::TRUSTED_REFERENCE);

        $this->write($this->rootPath.'/composer.json', Json::encode([
            'name' => 'acme/app',
            'require' => [self::PACKAGE => '^1.0'],
        ]));

        $this->write($this->rootPath.'/vendor/composer/installed.json', Json::encode([
            'packages' => [[
                ...$entry,
                'installation-source' => 'dist',
                'install-path' => '../acme/widget',
            ]],
            'dev' => true,
            'dev-package-names' => [],
        ]));
    }

    private function seedTrustFile(): void
    {
        $hash = Manifest::ofDirectory($this->releasePath(self::TRUSTED_VERSION, self::TRUSTED_REFERENCE))->hash();

        $this->write($this->rootPath.'/vet.json', Json::encode([
            'schema' => PersistTrustFile::SCHEMA,
            'require' => [
                self::PACKAGE => [
                    'version' => self::TRUSTED_VERSION,
                    'hash' => (string) $hash,
                ],
            ],
            'require-dev' => (object) [],
        ]));
    }

    private function seedComposer(string $plan, int $exitCode): void
    {
        $path = $this->rootPath.'/composer-stub';

        $this->write($path, sprintf("#!/bin/sh\ncat <<'PLAN' >&2\n%s\nPLAN\nexit %d\n", $plan, $exitCode));

        chmod($path, 0o755);

        putenv('VET_COMPOSER_BINARY='.$path);
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);
    }
}
