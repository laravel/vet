<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\FailureException;
use Stringable;

final readonly class TreeHash implements Stringable
{
    public const string ALGORITHM = 'tree-v2';

    public const int LENGTH = 64;

    private function __construct(
        public string $algorithm,
        public string $digest,
    ) {}

    public function __toString(): string
    {
        return $this->algorithm.':'.$this->digest;
    }

    public static function fromManifest(string $manifest): self
    {
        return new self(
            self::ALGORITHM,
            hash('sha256', $manifest),
        );
    }

    public static function parse(string $value): self
    {
        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            throw new FailureException(sprintf('Malformed tree hash [%s]: expected "<algorithm>:<digest>".', $value));
        }

        [$algorithm, $digest] = $parts;

        if ($algorithm !== self::ALGORITHM) {
            throw new FailureException(sprintf('Unknown tree hash algorithm [%s]; this build of vet understands [%s].', $algorithm, self::ALGORITHM));
        }

        if (preg_match('/^[0-9a-f]{'.self::LENGTH.'}$/', $digest) !== 1) {
            throw new FailureException(sprintf('Malformed tree hash digest [%s]: expected %d lowercase hex characters.', $digest, self::LENGTH));
        }

        return new self($algorithm, $digest);
    }

    public function equals(self $other): bool
    {
        return $this->algorithm === $other->algorithm
            && hash_equals($this->digest, $other->digest);
    }

    public function short(): string
    {
        return mb_substr($this->digest, 0, 12);
    }
}
