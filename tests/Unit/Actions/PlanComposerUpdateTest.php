<?php

declare(strict_types=1);

use App\Actions\PlanComposerUpdate;
use App\Exceptions\ComposerFailedException;

it('fails when composer is not on the path', function (): void {
    (new PlanComposerUpdate('composer-that-is-not-installed'))->handle(__DIR__);
})->throws(ComposerFailedException::class, 'Could not find [composer-that-is-not-installed] on your PATH.');

it('reads the plan of the composer binary', function (): void {
    $planner = new PlanComposerUpdate(stubBinary(
        'cat '.escapeshellarg(__DIR__.'/../../Fixtures/composer-update-dry-run.txt').' >&2',
    ));

    expect($planner->handle(__DIR__)->operations)->toHaveCount(4);
});

it('names the words of composer when the plan fails', function (): void {
    $planner = new PlanComposerUpdate(stubBinary("echo 'Your requirements could not be resolved.' >&2\nexit 2"));

    $failure = null;

    try {
        $planner->handle(__DIR__);
    } catch (ComposerFailedException $composerFailedException) {
        $failure = $composerFailedException;
    }

    expect($failure)->toBeInstanceOf(ComposerFailedException::class)
        ->and($failure?->getMessage())->toContain('exit code 2')
        ->and($failure?->output)->toBe(['Your requirements could not be resolved.']);
});
