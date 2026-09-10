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

final readonly class StaleProject
{
    public const string PACKAGE = 'acme/widget';

    public const string GRANTED_VERSION = '1.0.0';

    public const string INSTALLED_VERSION = '2.0.0';

    private const string GRANTED_REFERENCE = 'aaaa1111';

    private const string INSTALLED_REFERENCE = 'bbbb2222';

    private function __construct(
        public string $rootPath,
        public string $cachePath,
        private int $ungranted,
    ) {}

    public static function create(): self
    {
        return self::seed(0);
    }

    public static function amongUngranted(int $count): self
    {
        return self::seed($count);
    }

    public function remove(): void
    {
        putenv('VET_CACHE_DIR');

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

    private static function seed(int $ungranted): self
    {
        $base = sys_get_temp_dir().'/vet-'.bin2hex(random_bytes(6));

        $project = new self($base.'/project', $base.'/cache', $ungranted);

        $project->seedGrantedTree();
        $project->seedMetadata();
        $project->seedInstalledTree();
        $project->seedUngrantedTrees();
        $project->seedComposerFiles();
        $project->seedTrustFile();

        putenv('VET_CACHE_DIR='.$project->cachePath);

        return $project;
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

    private function packageManifest(): string
    {
        return Json::encode([
            'name' => self::PACKAGE,
            'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\Widget\\' => 'src/']],
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
        ];
    }

    private function grantedTreePath(): string
    {
        $key = mb_substr(
            hash('sha256', $this->distUrl(self::GRANTED_VERSION).'|'.self::GRANTED_REFERENCE),
            0,
            16,
        );

        return sprintf('%s/archives/%s/%s-%s', $this->cachePath, self::PACKAGE, self::GRANTED_VERSION, $key);
    }

    private function seedGrantedTree(): void
    {
        $directory = $this->grantedTreePath();

        $this->write($directory.'/composer.json', $this->packageManifest());
        $this->write($directory.'/README.md', "# Widget\n");
        $this->write($directory.'/src/Widget.php', $this->widget('widget'));
        $this->write($directory.'.complete', self::GRANTED_VERSION."\n");
    }

    private function seedMetadata(): void
    {
        $this->write($this->cachePath.'/metadata/'.self::PACKAGE.'/index.json', Json::encode([
            'packages' => [
                self::PACKAGE => [
                    $this->metadataOf(self::INSTALLED_VERSION, self::INSTALLED_REFERENCE),
                    $this->metadataOf(self::GRANTED_VERSION, self::GRANTED_REFERENCE),
                ],
            ],
        ]));
    }

    private function seedInstalledTree(): void
    {
        $directory = $this->rootPath.'/vendor/acme/widget';

        $this->write($directory.'/composer.json', $this->packageManifest());
        $this->write($directory.'/README.md', "# Widget\n");
        $this->write($directory.'/src/Widget.php', $this->widget('gadget'));
    }

    private function fillerName(int $index): string
    {
        return sprintf('acme/filler-%d', $index);
    }

    private function fillerManifest(int $index): string
    {
        return Json::encode([
            'name' => $this->fillerName($index),
            'type' => 'library',
            'autoload' => ['psr-4' => [sprintf('Acme\\Filler%d\\', $index) => 'src/']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fillerMetadata(int $index): array
    {
        return [
            'name' => $this->fillerName($index),
            'version' => '1.0.0',
            'type' => 'library',
            'dist' => [
                'type' => 'zip',
                'url' => sprintf('https://example.test/acme-filler-%d-1.0.0.zip', $index),
                'reference' => sprintf('cccc%04d', $index),
                'shasum' => '',
            ],
            'autoload' => [
                'psr-4' => [sprintf('Acme\\Filler%d\\', $index) => 'src/'],
            ],
        ];
    }

    private function seedUngrantedTrees(): void
    {
        for ($index = 1; $index <= $this->ungranted; $index++) {
            $directory = $this->rootPath.'/vendor/'.$this->fillerName($index);

            $this->write($directory.'/composer.json', $this->fillerManifest($index));
            $this->write($directory.'/src/Filler.php', sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Filler%d;\n\nfinal class Filler\n{\n}\n",
                $index,
            ));
        }
    }

    private function seedComposerFiles(): void
    {
        $entries = [$this->metadataOf(self::INSTALLED_VERSION, self::INSTALLED_REFERENCE)];
        $installed = [$this->installedEntry($entries[0], self::PACKAGE)];
        $require = [self::PACKAGE => '^2.0'];

        for ($index = 1; $index <= $this->ungranted; $index++) {
            $entries[] = $this->fillerMetadata($index);
            $installed[] = $this->installedEntry($this->fillerMetadata($index), $this->fillerName($index));
            $require[$this->fillerName($index)] = '^1.0';
        }

        $this->write($this->rootPath.'/composer.json', Json::encode([
            'name' => 'acme/app',
            'require' => $require,
        ]));

        $this->write($this->rootPath.'/composer.lock', Json::encode([
            'content-hash' => 'fixture',
            'packages' => $entries,
            'packages-dev' => [],
        ]));

        $this->write($this->rootPath.'/vendor/composer/installed.json', Json::encode([
            'packages' => $installed,
            'dev' => true,
            'dev-package-names' => [],
        ]));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function installedEntry(array $entry, string $name): array
    {
        return [
            ...$entry,
            'installation-source' => 'dist',
            'install-path' => '../'.$name,
        ];
    }

    private function seedTrustFile(): void
    {
        $hash = Manifest::ofDirectory($this->grantedTreePath())->hash();

        $this->write($this->rootPath.'/vet.json', Json::encode([
            'schema' => PersistTrustFile::SCHEMA,
            'require' => [
                self::PACKAGE => [
                    'version' => self::GRANTED_VERSION,
                    'hash' => (string) $hash,
                ],
            ],
            'require-dev' => (object) [],
        ]));
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
