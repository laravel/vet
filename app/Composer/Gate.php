<?php

declare(strict_types=1);

namespace App\Composer;

final readonly class Gate
{
    public const string ENVIRONMENT = 'VET_INSIDE_COMPOSER';

    public function __construct(
        public string $rootPath,
        private string $binDir,
        private string $vendorDir,
    ) {}

    public function binary(): ?string
    {
        foreach ([$this->binDir.'/vet', $this->rootPath.'/vet'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function hasTrustFile(): bool
    {
        return is_file($this->rootPath.'/vet.json');
    }

    public function hasInstalledTree(): bool
    {
        return is_file($this->vendorDir.'/composer/installed.json');
    }

    /**
     * @return array<int, string>
     */
    public function command(bool $verbose, bool $decorated): array
    {
        return $this->arguments($verbose, $decorated, []);
    }

    /**
     * @return array<int, string>
     */
    public function commandWithPlan(bool $verbose, bool $decorated, string $planPath): array
    {
        return $this->arguments($verbose, $decorated, ['--plan='.$planPath]);
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [self::ENVIRONMENT => '1'];
    }

    public function nested(): bool
    {
        return getenv(self::ENVIRONMENT) === '1';
    }

    /**
     * @param  array<int, array<string, string|null>>  $operations
     */
    public function writePlan(array $operations): ?string
    {
        $encoded = json_encode(['operations' => $operations]);

        if ($encoded === false) {
            return null;
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'vet-plan-');

        return file_put_contents($path, $encoded) === false ? null : $path;
    }

    public function deletePlan(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function baselineNotice(): ?string
    {
        if ($this->binary() === null || $this->hasTrustFile()) {
            return null;
        }

        return 'Vet has no trust file in this project yet. Run [vet --init] to record what you trust today.';
    }

    public function firstInstallNotice(): ?string
    {
        if ($this->binary() === null || ! $this->hasTrustFile() || $this->hasInstalledTree()) {
            return null;
        }

        return 'Vet audits an update against the installed tree. This project installs no package yet, so the audit runs after this install.';
    }

    /**
     * @param  array<int, string>  $extra
     * @return array<int, string>
     */
    private function arguments(bool $verbose, bool $decorated, array $extra): array
    {
        $binary = $this->binary();

        if ($binary === null || ! $this->hasTrustFile()) {
            return [];
        }

        return [PHP_BINARY, $binary, $decorated ? '--ansi' : '--no-ansi', ...$extra, ...($verbose ? ['-v'] : [])];
    }
}
