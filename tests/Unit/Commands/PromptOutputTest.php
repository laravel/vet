<?php

declare(strict_types=1);

use App\Support\ControlSafeFormatter;
use Laravel\Prompts\Output\ConsoleOutput as PromptOutput;
use Laravel\Prompts\Prompt;
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
