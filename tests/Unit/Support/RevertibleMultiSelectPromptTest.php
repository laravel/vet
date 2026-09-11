<?php

declare(strict_types=1);

use App\Support\PickedCountRenderer;
use App\Support\RevertibleMultiSelectPrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\form;

function revertiblePrompt(): RevertibleMultiSelectPrompt
{
    return new RevertibleMultiSelectPrompt(
        label: 'Which packages do you trust?',
        options: ['acme/widget' => 'acme/widget', 'acme/gadget' => 'acme/gadget'],
        default: [],
        scroll: 10,
        hint: '',
    );
}

it('goes back to the previous step of a form when you press escape', function (): void {
    $output = new BufferedConsoleOutput;

    Prompt::setOutput($output);
    Prompt::addTheme('vet', [RevertibleMultiSelectPrompt::class => PickedCountRenderer::class]);
    Prompt::theme('vet');

    $asked = 0;

    form()
        ->add(function () use (&$asked): int {
            return ++$asked;
        })
        ->add(function () use (&$asked): void {
            if ($asked === 1) {
                revertiblePrompt()->emit('key', Key::ESCAPE);
            }
        })
        ->submit();

    expect($asked)->toBe(2)
        ->and($output->content())->toContain('Reverted.');
});

it('stays open when you press escape outside a form', function (): void {
    $prompt = revertiblePrompt();

    $prompt->emit('key', Key::ESCAPE);

    expect($prompt->state)->toBe('initial');
});
