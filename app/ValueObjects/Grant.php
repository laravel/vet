<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\FailureException;
use App\Support\Json;

final readonly class Grant
{
    public function __construct(
        public string $package,
        public string $version,
        public TreeHash $hash,
        public bool $dev,
    ) {}

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry, string $package, bool $dev): self
    {
        $version = Json::string($entry, 'version');
        $hash = Json::string($entry, 'hash');

        if ($version === null || $hash === null) {
            throw new FailureException(sprintf('The entry for [%s] needs a [version] and a [hash].', $package));
        }

        return new self(
            package: $package,
            version: $version,
            hash: TreeHash::parse($hash),
            dev: $dev,
        );
    }

    public function covers(TreeHash $hash): bool
    {
        return $this->hash->equals($hash);
    }

    public function section(): string
    {
        return $this->dev ? 'require-dev' : 'require';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'hash' => (string) $this->hash,
        ];
    }
}
