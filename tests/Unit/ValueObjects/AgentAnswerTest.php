<?php

declare(strict_types=1);

use App\ValueObjects\AgentAnswer;
use App\ValueObjects\AgentFinding;

it('reads no answer that holds no verdict', function (string $output): void {
    expect(AgentAnswer::read($output))->toBeNull();
})->with([
    'a list' => '[1, 2]',
    'a verdict that is a number' => '{"verdict": 1}',
    'no verdict' => '{"summary": "nothing"}',
    'prose' => 'I think it is fine.',
]);

it('keeps each finding that names a path, and drops the rest', function (): void {
    $answer = AgentAnswer::read('{"verdict":"risk","summary":7,"findings":[{"path":"src/Ship.php","reason":3},{"reason":"no path"},"junk"]}');

    expect($answer?->verdict)->toBe('risk')
        ->and($answer?->summary)->toBe('')
        ->and($answer?->findings)->toEqual([new AgentFinding('src/Ship.php', '')]);
});

it('reads no finding when the findings are not a list', function (): void {
    expect(AgentAnswer::read('{"verdict":"clear","summary":"nothing","findings":"none"}')?->findings)->toBe([]);
});
