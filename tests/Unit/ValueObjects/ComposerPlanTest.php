<?php

declare(strict_types=1);

use App\ValueObjects\ComposerPlan;

it('skips an operation of a plan file that is not an object', function (): void {
    $path = sys_get_temp_dir().'/vet-plan-shape-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($path, '{"operations":["junk",{"package":"acme/widget","change":"install","from":null,"to":"1.0.0"}]}');

    try {
        $plan = ComposerPlan::fromFile($path);
    } finally {
        unlink($path);
    }

    expect($plan->operations)->toHaveCount(1)
        ->and($plan->of('acme/widget')?->to)->toBe('1.0.0');
});
