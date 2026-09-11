<?php

declare(strict_types=1);

use App\Support\PickedCountRenderer;
use Laravel\Prompts\MultiSelectPrompt;

/**
 * @param  array<int, string>  $picked
 */
function pickedCountPrompt(array $picked, string $state): string
{
    $prompt = new MultiSelectPrompt(
        label: 'Which packages do you trust?',
        options: ['acme/widget' => 'acme/widget', 'acme/gadget' => 'acme/gadget'],
        default: $picked,
    );

    $prompt->state = $state;

    return (new PickedCountRenderer($prompt))($prompt);
}

it('writes the count of the packages that you picked once you submit', function (): void {
    $output = pickedCountPrompt(['acme/widget', 'acme/gadget'], 'submit');

    expect($output)
        ->toContain('Which packages do you trust?')
        ->toContain('2 picked')
        ->and(str_contains($output, 'acme/gadget'))->toBeFalse();
});

it('writes none once you submit no package', function (): void {
    expect(pickedCountPrompt([], 'submit'))->toContain('None');
});

it('writes each option while you pick', function (): void {
    expect(pickedCountPrompt(['acme/widget'], 'active'))
        ->toContain('acme/widget')
        ->toContain('acme/gadget');
});
