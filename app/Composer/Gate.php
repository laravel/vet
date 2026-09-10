<?php

declare(strict_types=1);

namespace App\Composer;

final readonly class Gate
{
    public const string ENVIRONMENT = 'VET_INSIDE_COMPOSER';

    public function __construct(
        public string $rootPath,
        private string $binDir,
        private string $vendorDir = '',
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
        $vendorDir = $this->vendorDir === '' ? $this->rootPath.'/vendor' : $this->vendorDir;

        return is_file($vendorDir.'/composer/installed.json');
    }

    /**
     * @return array<int, string>|null
     */
    public function command(bool $verbose, bool $decorated, ?string $planPath): ?array
    {
        $binary = $this->binary();

        if ($binary === null || ! $this->hasTrustFile()) {
            return null;
        }

        $command = [PHP_BINARY, $binary, 'audit', $decorated ? '--ansi' : '--no-ansi'];

        if ($planPath !== null) {
            $command[] = '--plan='.$planPath;
        }

        if ($verbose) {
            $command[] = '-v';
        }

        return $command;
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

        $path = tempnam(sys_get_temp_dir(), 'vet-plan-');

        if ($path === false) {
            return null;
        }

        return file_put_contents($path, $encoded) === false ? null : $path;
    }

    public function deletePlan(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function baselineNotice(): ?string
    {
        if ($this->binary() === null || $this->hasTrustFile()) {
            return null;
        }

        return 'Vet has no trust file in this project yet. Run [vet trust] to record what you trust today.';
    }

    public function firstInstallNotice(): ?string
    {
        if ($this->binary() === null || ! $this->hasTrustFile() || $this->hasInstalledTree()) {
            return null;
        }

        return 'Vet audits an update against the installed tree. This project installs no package yet, so the audit runs after this install.';
    }
}
