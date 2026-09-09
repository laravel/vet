<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\ComposerChangeType;
use App\Support\Json;

final readonly class ComposerPlan
{
    /**
     * @param  array<int, ComposerOperation>  $operations
     */
    private function __construct(
        public array $operations,
        private bool $explains = false,
    ) {}

    public static function parse(string $output): self
    {
        $lines = preg_split('/\R/', $output);
        $operations = [];

        foreach ($lines === false ? [] : $lines as $line) {
            $operation = ComposerOperation::parse($line);

            if ($operation instanceof ComposerOperation) {
                $operations[$operation->package] = $operation;
            }
        }

        return new self(array_values($operations));
    }

    public static function fromFile(string $path): self
    {
        $operations = [];

        foreach (Json::array(Json::readFile($path, 'the composer plan'), 'operations') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $operation = ComposerOperation::fromArray($entry);

            if ($operation instanceof ComposerOperation) {
                $operations[$operation->package] = $operation;
            }
        }

        return new self(array_values($operations), true);
    }

    public static function between(LockFile $lock, ?InstalledRepository $installed): self
    {
        $current = $installed instanceof InstalledRepository ? $installed->all() : [];
        $operations = [];

        foreach ($lock->packages() as $name => $locked) {
            $operation = self::operationFor($locked, $current[$name] ?? null);

            if ($operation instanceof ComposerOperation) {
                $operations[] = $operation;
            }
        }

        return new self($operations);
    }

    public function explains(): bool
    {
        return $this->explains;
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function touches(string $package): bool
    {
        return $this->of($package) instanceof ComposerOperation;
    }

    public function of(string $package): ?ComposerOperation
    {
        foreach ($this->operations as $operation) {
            if ($operation->package === $package) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @return array<int, ComposerOperation>
     */
    public function incoming(): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (ComposerOperation $operation): bool => $operation->change !== ComposerChangeType::Remove
                && $operation->to !== null,
        ));
    }

    private static function operationFor(Package $locked, ?Package $installed): ?ComposerOperation
    {
        if (! $installed instanceof Package) {
            return new ComposerOperation(
                package: $locked->name,
                change: ComposerChangeType::Install,
                from: null,
                to: $locked->version,
                distUrl: $locked->distUrl,
                distReference: $locked->distReference,
            );
        }

        if ($installed->version === $locked->version && $installed->distReference === $locked->distReference) {
            return null;
        }

        return new ComposerOperation(
            package: $locked->name,
            change: version_compare($locked->version, $installed->version, '<')
                ? ComposerChangeType::Downgrade
                : ComposerChangeType::Upgrade,
            from: $installed->version,
            to: $locked->version,
            distUrl: $locked->distUrl,
            distReference: $locked->distReference,
        );
    }
}
