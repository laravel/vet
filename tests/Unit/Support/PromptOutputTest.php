<?php

declare(strict_types=1);

use App\Support\ControlSafeFormatter;
use App\Support\PromptOutput;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('shares the count of the blank lines with the output of the command', function (): void {
    $buffer = new BufferedOutput;
    $style = new OutputStyle(new ArrayInput([]), $buffer);
    $style->setFormatter(new ControlSafeFormatter(new OutputFormatter));

    $output = new PromptOutput($style, new OutputFormatter);

    $style->writeln('audited');
    $style->newLine();

    expect($output->newLinesWritten())->toBe(2);

    $output->write("prompt\n\n");
    $output->writeDirectly("\e[?25h");

    expect($style->newLinesWritten())->toBe(2)
        ->and($buffer->fetch())->toBe("audited\n\nprompt\n\n\e[?25h");
});
