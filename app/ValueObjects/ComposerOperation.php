<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\ComposerChangeType;
use App\Support\Json;

final readonly class ComposerOperation
{
    private const string LINE = '/^\s*-\s+(Installing|Upgrading|Downgrading|Removing)\s+([^\s\/]+\/[^\s\/]+)\s+\(([^)]+)\)/';

    public function __construct(
        public string $package,
        public ComposerChangeType $change,
        public ?string $from,
        public ?string $to,
        public ?string $distUrl,
        public ?string $distReference,
        public ?string $distShasum,
    ) {}

    public static function parse(string $line): ?self
    {
        if (preg_match(self::LINE, $line, $matches) !== 1) {
            return null;
        }

        $change = ComposerChangeType::fromVerb($matches[1]);
        $versions = array_map(self::version(...), explode('=>', $matches[3]));

        if (count($versions) > 1) {
            return new self($matches[2], $change, $versions[0], $versions[1], null, null, null);
        }

        return $change === ComposerChangeType::Remove
            ? new self($matches[2], $change, $versions[0], null, null, null, null)
            : new self($matches[2], $change, null, $versions[0], null, null, null);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $package = Json::string($entry, 'package');
        $change = ComposerChangeType::tryFrom(Json::string($entry, 'change') ?? '');

        if ($package === null || $package === '' || ! $change instanceof ComposerChangeType) {
            return null;
        }

        return new self(
            package: $package,
            change: $change,
            from: Json::string($entry, 'from'),
            to: Json::string($entry, 'to'),
            distUrl: Json::string($entry, 'dist_url'),
            distReference: Json::string($entry, 'dist_reference'),
            distShasum: Json::string($entry, 'dist_shasum'),
        );
    }

    private static function version(string $version): string
    {
        $trimmed = trim($version);
        $space = mb_strpos($trimmed, ' ');

        return $space === false ? $trimmed : mb_substr($trimmed, 0, $space);
    }
}
