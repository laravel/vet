<?php

declare(strict_types=1);

use App\Actions\ReviewWithAgent;
use App\Enums\AgentVerdict;
use App\Enums\UnreadReason;
use App\Exceptions\AgentFailedException;
use App\ValueObjects\AgentModel;
use App\ValueObjects\UnreadFile;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\StubAgent;

it('reads the verdict, the summary and the findings that the agent writes', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        '{"verdict":"risk","summary":"[src/Ship.php] sends the contents of .env to an unknown host","findings":[{"path":"src/Ship.php","reason":"it posts .env to a host"}]}',
    )), AgentModel::default(), 300, silentDots());

    $reviews = $agent->handle(['acme/widget' => agentPrompt('the delta of acme/widget', [], ['src/Ship.php'])]);

    expect($reviews['acme/widget']->verdict)->toBe(AgentVerdict::Risk)
        ->and($reviews['acme/widget']->summary)->toBe('[src/Ship.php] sends the contents of .env to an unknown host')
        ->and($reviews['acme/widget']->findings)->toHaveCount(1)
        ->and($reviews['acme/widget']->findings[0]->path)->toBe('src/Ship.php');
});

it('gives the prompt to the agent on its standard input', function (): void {
    $executable = stubAgent(StubAgent::answering('{"verdict":"clear","summary":"nothing","findings":[]}'));
    $agent = new ReviewWithAgent($executable, AgentModel::default(), 300, silentDots());

    $agent->handle(['acme/widget' => agentPrompt('the prompt of acme/widget', [], [])]);

    expect(StubAgent::promptGivenTo($executable))->toBe('the prompt of acme/widget');
});

it('reads one verdict for each package of the prompts', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        '{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}',
    )), AgentModel::default(), 300, silentDots());

    $reviews = $agent->handle([
        'acme/widget' => agentPrompt('one', [], []),
        'acme/gadget' => agentPrompt('two', [], []),
        'acme/media' => agentPrompt('three', [], []),
        'acme/lint' => agentPrompt('four', [], []),
        'acme/moved' => agentPrompt('five', [], []),
    ]);

    expect($reviews)->toHaveCount(5)
        ->and($reviews['acme/moved']->verdict)->toBe(AgentVerdict::Clear);
});

it('reads the answer that stands inside a fence', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        "Here is my answer:\n```json\n{\"verdict\":\"clear\",\"summary\":\"two return types changed\",\"findings\":[]}\n```\n",
    )), AgentModel::default(), 300, silentDots());

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Clear)
        ->and($review->summary)->toBe('two return types changed');
});

it('writes a partial verdict when the prompt holds no byte of a file', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        '{"verdict":"clear","summary":"the delta changes two return types","findings":[]}',
    )), AgentModel::default(), 300, silentDots());

    $review = $agent->handle([
        'acme/widget' => agentPrompt('the delta', [new UnreadFile('src/vendor.phar', UnreadReason::NotText, 10)], ['src/vendor.phar']),
    ])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Partial)
        ->and($review->summary)->toBe('the delta changes two return types')
        ->and($review->unreadNote())->toBe('1 file not text');
});

it('keeps a risk verdict when the prompt holds no byte of a file', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        '{"verdict":"risk","summary":"it runs a shell command","findings":[]}',
    )), AgentModel::default(), 300, silentDots());

    $review = $agent->handle([
        'acme/widget' => agentPrompt('the delta', [new UnreadFile('src/vendor.phar', UnreadReason::NotText, 10)], ['src/vendor.phar']),
    ])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Risk);
});

it('writes no verdict when the agent names a file that the delta does not hold', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        '{"verdict":"risk","summary":"it reads a secret","findings":[{"path":"src/Invented.php","reason":"it reads .env"}]}',
    )), AgentModel::default(), 300, silentDots());

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], ['src/Ship.php'])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::NoVerdict)
        ->and($review->summary)->toBe('The agent named [src/Invented.php], and this delta holds no such file.');
});

it('writes no verdict when the agent stops with a failure', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::silent()->failing(3, "the model is not reachable\n")), AgentModel::default(), 300, silentDots());

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::NoVerdict)
        ->and($review->summary)->toContain('exit code [3]')
        ->and($review->summary)->toContain('the model is not reachable');
});

it('writes no verdict when the agent answers with prose', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering("I think it is fine.\n")), AgentModel::default(), 300, silentDots());

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::NoVerdict)
        ->and($review->summary)->toBe('I think it is fine.');
});

it('names the binary that it cannot run', function (): void {
    new ReviewWithAgent('agent-that-is-not-installed', AgentModel::default(), 300, silentDots())->handle(['acme/widget' => agentPrompt('the delta', [], [])]);
})->throws(AgentFailedException::class, 'Could not run [agent-that-is-not-installed].');

it('finds the agent that the environment names', function (): void {
    putenv('VET_AGENT_BINARY=/usr/local/bin/my-agent');

    try {
        expect(ReviewWithAgent::default()->name())->toBe('my-agent');
    } finally {
        putenv('VET_AGENT_BINARY');
    }
});

it('reads the name of a windows shim without its suffix', function (): void {
    putenv('VET_AGENT_BINARY=C:\\Users\\x\\AppData\\Roaming\\npm\\claude.cmd');

    try {
        $agent = ReviewWithAgent::default();

        expect($agent->name())->toBe('claude')
            ->and($agent->withModel(AgentModel::of('opus'))->name())->toBe('claude');
    } finally {
        putenv('VET_AGENT_BINARY');
    }
});

it('refuses a model for an agent whose flags vet does not know', function (): void {
    expect(fn (): ReviewWithAgent => new ReviewWithAgent('/usr/local/bin/my-agent', AgentModel::default(), 300, silentDots())->withModel(AgentModel::of('opus')))
        ->toThrow(AgentFailedException::class, 'Could not pass a model to [/usr/local/bin/my-agent].');
});

it('gives a claude agent the flags of claude and the model, and reads its envelope', function (): void {
    $directory = sys_get_temp_dir().'/vet-claude-'.bin2hex(random_bytes(6));

    $executable = StubAgent::answering('{"structured_output":{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}}')
        ->install($directory, 'claude');

    try {
        $review = new ReviewWithAgent($executable, AgentModel::default(), 300, silentDots())
            ->withModel(AgentModel::of('opus'))
            ->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

        $written = StubAgent::argumentsGivenTo($executable);
    } finally {
        File::deleteDirectory($directory);
    }

    expect($review->verdict)->toBe(AgentVerdict::Clear)
        ->and($review->summary)->toBe('nothing reaches outside the package')
        ->and($written)->toContain('--print', '--tools', '--no-session-persistence')
        ->and(array_slice($written, -2))->toBe(['--model', 'opus']);
});

it('writes no verdict when the agent writes nothing', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::silent()), AgentModel::default(), 300, silentDots());

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::NoVerdict)
        ->and($review->summary)->toBe('The agent wrote nothing.');
});

it('cuts a long summary of the agent to two hundred characters', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::answering(
        '{"verdict":"clear","summary":"'.str_repeat('a', 300).'","findings":[]}',
    )), AgentModel::default(), 300, silentDots());

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->summary)->toBe(str_repeat('a', 199).'…');
});

it('writes no verdict when the agent gives no answer in its time', function (): void {
    $agent = new ReviewWithAgent(stubAgent(StubAgent::silent()->sleeping(5)), AgentModel::default(), 1, silentDots());
    $started = microtime(true);

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::NoVerdict)
        ->and($review->summary)->toBe('The agent gave no answer in [1] second(s).')
        ->and(microtime(true) - $started)->toBeLessThan(4.0);
});

it('finds the first agent that the path holds, and runs it by its name', function (): void {
    $directory = sys_get_temp_dir().'/vet-path-'.bin2hex(random_bytes(6));

    StubAgent::answering('{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}')
        ->install($directory, 'codex');

    try {
        [$name, $review] = withEnvironment(['VET_AGENT_BINARY' => null, 'PATH' => $directory], static function (): array {
            $agent = ReviewWithAgent::default();

            return [$agent->name(), $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget']];
        });
    } finally {
        File::deleteDirectory($directory);
    }

    expect($name)->toBe('codex')
        ->and($review->verdict)->toBe(AgentVerdict::Clear);
});

it('names each agent that it looks for when the path holds none', function (): void {
    $directory = sys_get_temp_dir().'/vet-path-'.bin2hex(random_bytes(6));

    mkdir($directory);

    try {
        expect(static fn (): ReviewWithAgent => withEnvironment(['VET_AGENT_BINARY' => null, 'PATH' => $directory], ReviewWithAgent::default(...)))
            ->toThrow(AgentFailedException::class, 'Could not find an agent on your PATH. Install one of [claude], [codex], [gemini], or name the binary in [VET_AGENT_BINARY].');
    } finally {
        rmdir($directory);
    }
});
