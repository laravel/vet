<?php

declare(strict_types=1);

use App\Support\PickedCountRenderer;
use App\Support\RevertibleMultiSelectPrompt;
use App\Support\RevertibleSuggestPrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Themes\Default\SuggestPromptRenderer;

use function Laravel\Prompts\form;

function revertibleSuggestPrompt(): RevertibleSuggestPrompt
{
    return new RevertibleSuggestPrompt(
        label: 'Which model do you want the agent to use?',
        options: ['opus', 'sonnet'],
        placeholder: '',
        hint: '',
    );
}

it('goes back to the previous step of a form when you press escape', function (): void {
    $output = new BufferedConsoleOutput;

    Prompt::setOutput($output);
    Prompt::addTheme('vet', [
        RevertibleMultiSelectPrompt::class => PickedCountRenderer::class,
        RevertibleSuggestPrompt::class => SuggestPromptRenderer::class,
    ]);
    Prompt::theme('vet');

    $asked = 0;

    form()
        ->add(function () use (&$asked): int {
            return ++$asked;
        })
        ->add(function () use (&$asked): void {
            if ($asked === 1) {
                revertibleSuggestPrompt()->emit('key', Key::ESCAPE);
            }
        })
        ->submit();

    expect($asked)->toBe(2)
        ->and($output->content())->toContain('Reverted.');
});

it('stays open when you press escape outside a form', function (): void {
    $prompt = revertibleSuggestPrompt();

    $prompt->emit('key', Key::ESCAPE);

    expect($prompt->state)->toBe('initial');
});
