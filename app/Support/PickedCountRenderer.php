<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Themes\Default\MultiSelectPromptRenderer;

final class PickedCountRenderer extends MultiSelectPromptRenderer
{
    public function __invoke(MultiSelectPrompt $prompt): string
    {
        if ($prompt->state !== 'submit') {
            return parent::__invoke($prompt);
        }

        $picked = count($prompt->value());

        return $this->box(
            $this->dim($this->truncate($prompt->label, $prompt->terminal()->cols() - 6)),
            $this->dim($picked === 0 ? 'None' : sprintf('%d picked', $picked)),
        )->__toString();
    }
}
