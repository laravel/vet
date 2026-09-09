<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Actions\RenderDelta;
use App\Enums\ComposerChangeType;

final readonly class PlannedReview
{
    public function __construct(
        public ComposerOperation $operation,
        public ?string $trusted,
        public ?Delta $delta,
    ) {}

    public function files(): ?int
    {
        return $this->delta instanceof Delta ? count($this->delta->changes()) : null;
    }

    public function versions(): string
    {
        $from = $this->operation->from;
        $to = $this->operation->to;

        if ($from === null) {
            return (string) $to;
        }

        return $to === null ? $from : sprintf('%s → %s', $from, $to);
    }

    public function cost(): string
    {
        $delta = $this->delta;

        if ($delta instanceof Delta) {
            return $delta->from === $this->operation->from
                ? sprintf('%d files to review', count($delta->changes()))
                : sprintf('%d files to review (delta from [%s])', count($delta->changes()), $delta->from);
        }

        return $this->operation->change === ComposerChangeType::Install
            ? 'whole package to review (new)'
            : 'nothing to review (removed)';
    }

    public function reason(): string
    {
        return match ($this->operation->change) {
            ComposerChangeType::Install => 'composer would add this package to the tree',
            ComposerChangeType::Remove => 'composer would take this package out of the tree',
            default => $this->trusted === null
                ? sprintf('no entry in the trust file; compared from the installed [%s]', $this->operation->from)
                : sprintf('you trust [%s]', $this->trusted),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(RenderDelta $renderer): array
    {
        return [
            'package' => $this->operation->package,
            'change' => $this->operation->change->value,
            'from' => $this->operation->from,
            'to' => $this->operation->to,
            'trusted' => $this->trusted,
            'files_to_review' => $this->files(),
            'delta' => $this->delta instanceof Delta ? $renderer->toArray($this->delta) : null,
        ];
    }

    public function weight(): int
    {
        return $this->operation->change->weight();
    }
}
