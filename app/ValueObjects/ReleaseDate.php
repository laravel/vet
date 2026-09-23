<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Support\Json;
use DateTimeImmutable;

final readonly class ReleaseDate
{
    private function __construct(
        private DateTimeImmutable $moment,
        private bool $known,
    ) {}

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromEntry(array $entry): self
    {
        $time = Json::string($entry, 'time');
        $moment = $time === null || trim($time) === '' ? false : date_create_immutable($time);

        return $moment === false
            ? new self(new DateTimeImmutable('@0'), false)
            : new self($moment, true);
    }

    public function plusDays(int $days): ?DateTimeImmutable
    {
        return $this->known ? $this->moment->modify(sprintf('+%d days', $days)) : null;
    }
}
