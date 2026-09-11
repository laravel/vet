<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\OutputStyle;

final readonly class ProgressDots
{
    public function __construct(
        private OutputStyle $output,
    ) {}

    public function mark(): void
    {
        if ($this->output->newLinesWritten() > 0) {
            $this->output->write('  ');
        }

        $this->output->write('<fg=gray>.</>');
    }

    public function end(): void
    {
        if ($this->output->newLinesWritten() === 0) {
            $this->output->newLine();
        }
    }
}
