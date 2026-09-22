<?php

declare(strict_types=1);

use App\Enums\AgentType;
use App\ValueObjects\AgentAnswer;
use App\ValueObjects\AgentModel;

it('offers the models of each agent', function (): void {
    expect(AgentType::Claude->models())->toContain('opus')
        ->and(AgentType::Codex->models())->toContain('gpt-5.6-sol')
        ->and(AgentType::Gemini->models())->toContain('gemini-2.5-pro')
        ->and(AgentType::Opencode->models())->toContain('anthropic/claude-opus-5');
});

it('gives each agent no tool that can write', function (): void {
    expect(AgentType::Claude->arguments('/tmp/schema.json', AgentModel::default()))->toBe([
        '--print',
        '--tools', '',
        '--no-session-persistence',
        '--output-format', 'json',
        '--json-schema', AgentAnswer::schema(),
    ])->and(AgentType::Codex->arguments('/tmp/schema.json', AgentModel::default()))->toBe([
        'exec', '-',
        '--sandbox', 'read-only',
        '--skip-git-repo-check',
        '--output-schema', '/tmp/schema.json',
    ])->and(AgentType::Gemini->arguments('/tmp/schema.json', AgentModel::default()))->toBe([
        '--output-format', 'json',
        '--approval-mode', 'plan',
    ])->and(AgentType::Opencode->arguments('/tmp/schema.json', AgentModel::default()))->toBe([
        'run',
        '--agent', 'plan',
    ]);
});

it('gives the agent the model that the user names after its own flags', function (): void {
    $arguments = AgentType::Codex->arguments('/tmp/schema.json', AgentModel::of(' gpt-5.6-sol '));

    expect(array_slice($arguments, -2))->toBe(['--model', 'gpt-5.6-sol']);
});

it('reads the answer inside the envelope of each agent', function (AgentType $type, string $output, string $answer): void {
    expect($type->answerOf($output))->toBe($answer);
})->with([
    'claude structured output' => [AgentType::Claude, '{"result":"ignored","structured_output":{"verdict":"clear","summary":"nothing","findings":[]}}', '{"verdict":"clear","summary":"nothing","findings":[]}'],
    'claude result' => [AgentType::Claude, '{"type":"result","result":"{\"verdict\":\"risk\"}"}', '{"verdict":"risk"}'],
    'claude empty result' => [AgentType::Claude, '{"result":""}', '{"result":""}'],
    'claude prose' => [AgentType::Claude, 'I think it is fine.', 'I think it is fine.'],
    'claude event list' => [AgentType::Claude, '[{"type":"system","subtype":"init"},{"type":"assistant","message":{"content":[]}},{"type":"result","result":"ignored","structured_output":{"verdict":"clear","summary":"nothing","findings":[]}}]', '{"verdict":"clear","summary":"nothing","findings":[]}'],
    'claude event list result' => [AgentType::Claude, '[{"type":"system","subtype":"init"},{"type":"result","result":"{\"verdict\":\"risk\"}"}]', '{"verdict":"risk"}'],
    'claude event after the result' => [AgentType::Claude, '[{"type":"result","structured_output":{"verdict":"risk"}},{"type":"rate_limit_event","rate_limit_info":{}}]', '{"verdict":"risk"}'],
    'claude event list without a result' => [AgentType::Claude, '[{"type":"system","subtype":"init"},{"type":"assistant","message":{"content":[]}}]', '[{"type":"system","subtype":"init"},{"type":"assistant","message":{"content":[]}}]'],
    'claude event list of scalars' => [AgentType::Claude, '["result", 1]', '["result", 1]'],
    'gemini response' => [AgentType::Gemini, '{"response":"{\"verdict\":\"clear\"}","stats":{}}', '{"verdict":"clear"}'],
    'codex' => [AgentType::Codex, '{"response":"{\"verdict\":\"risk\"}"}', '{"response":"{\"verdict\":\"risk\"}"}'],
    'opencode' => [AgentType::Opencode, '{"verdict":"clear","summary":"nothing","findings":[]}', '{"verdict":"clear","summary":"nothing","findings":[]}'],
]);
