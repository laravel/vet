<?php

declare(strict_types=1);

use App\Support\ControlSafeFormatter;
use App\Support\PromptOutput;
use Illuminate\Console\OutputStyle;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixture;

it('writes each prompt through an output that keeps the escape sequences of laravel prompts', function (): void {
    $fixture = Fixture::open('audited-project');

    try {
        vet(['--path' => $fixture->rootPath]);
    } finally {
        $fixture->remove();
    }

    $output = (new ReflectionProperty(Prompt::class, 'output'))->getValue();
    $formatter = $output instanceof PromptOutput ? $output->getFormatter() : null;
    $frame = "\e[?25l\e[1G\e[13A\e[J\e[90m\e[2mpicker\e[22m\e[39m";

    expect($output)->toBeInstanceOf(PromptOutput::class)
        ->and($formatter)->not->toBeInstanceOf(ControlSafeFormatter::class)
        ->and($formatter?->format($frame))->toBe($frame);
});

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
