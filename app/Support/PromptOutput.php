<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

final class PromptOutput extends Output
{
    public function __construct(
        private readonly OutputStyle $style,
        OutputFormatterInterface $formatter,
    ) {
        parent::__construct($style->getVerbosity(), $style->isDecorated(), $formatter);
    }

    public function newLinesWritten(): int
    {
        return $this->style->newLinesWritten();
    }

    public function writeDirectly(string $message): void
    {
        $this->style->getOutput()->write($message, false, OutputInterface::OUTPUT_RAW);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->style->write($message, $newline, OutputInterface::OUTPUT_RAW);
    }
}
