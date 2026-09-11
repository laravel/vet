<?php

declare(strict_types=1);

use App\ValueObjects\ManifestChange;

it('renders the scalar values of a key that changed, and nothing for a key that did not change', function (): void {
    $change = ManifestChange::between(
        ['type' => 'library', 'extra' => ['plugin-modifies-downloads' => false]],
        ['type' => 'composer-plugin', 'extra' => ['plugin-modifies-downloads' => true]],
    );

    expect($change->render('type'))->toBe('library → composer-plugin')
        ->and($change->render('extra.plugin-modifies-downloads'))->toBe('false → true')
        ->and($change->render('scripts'))->toBe('');
});
