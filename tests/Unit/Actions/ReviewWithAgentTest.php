<?php

declare(strict_types=1);

use App\Actions\ReviewWithAgent;
use App\Enums\AgentVerdict;
use App\Exceptions\AgentFailedException;

it('reads the verdict, the summary and the findings that the agent writes', function (): void {
    $agent = new ReviewWithAgent(stubBinary(
        'cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"[src/Ship.php] sends the contents of .env to an unknown host","findings":[{"path":"src/Ship.php","reason":"it posts .env to a host"}]}\'',
    ));

    $reviews = $agent->handle(['acme/widget' => agentPrompt('the delta of acme/widget', [], ['src/Ship.php'])]);

    expect($reviews['acme/widget']->verdict)->toBe(AgentVerdict::Risk)
        ->and($reviews['acme/widget']->summary)->toBe('[src/Ship.php] sends the contents of .env to an unknown host')
        ->and($reviews['acme/widget']->findings)->toHaveCount(1)
        ->and($reviews['acme/widget']->findings[0]->path)->toBe('src/Ship.php');
});

it('gives the prompt to the agent on its standard input', function (): void {
    $written = sys_get_temp_dir().'/vet-agent-'.bin2hex(random_bytes(6));

    $agent = new ReviewWithAgent(stubBinary(
        'cat > '.escapeshellarg($written)."\n".'echo \'{"verdict":"clear","summary":"nothing","findings":[]}\'',
    ));

    $agent->handle(['acme/widget' => agentPrompt('the prompt of acme/widget', [], [])]);

    $prompt = (string) file_get_contents($written);
    unlink($written);

    expect($prompt)->toBe('the prompt of acme/widget');
});

it('reads one verdict for each package of the prompts', function (): void {
    $agent = new ReviewWithAgent(stubBinary(
        'cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}\'',
    ));

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
    $agent = new ReviewWithAgent(stubBinary(
        'cat > /dev/null'."\n".'printf \'Here is my answer:\n```json\n{"verdict":"clear","summary":"two return types changed","findings":[]}\n```\n\'',
    ));

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Clear)
        ->and($review->summary)->toBe('two return types changed');
});

it('writes a partial verdict when the prompt holds no byte of a file', function (): void {
    $agent = new ReviewWithAgent(stubBinary(
        'cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta changes two return types","findings":[]}\'',
    ));

    $review = $agent->handle([
        'acme/widget' => agentPrompt('the delta', ['src/vendor.phar'], ['src/vendor.phar']),
    ])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Partial)
        ->and($review->summary)->toContain('[1] file(s) did not reach the agent');
});

it('keeps a risk verdict when the prompt holds no byte of a file', function (): void {
    $agent = new ReviewWithAgent(stubBinary(
        'cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"it runs a shell command","findings":[]}\'',
    ));

    $review = $agent->handle([
        'acme/widget' => agentPrompt('the delta', ['src/vendor.phar'], ['src/vendor.phar']),
    ])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Risk);
});

it('writes no verdict when the agent names a file that the delta does not hold', function (): void {
    $agent = new ReviewWithAgent(stubBinary(
        'cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"it reads a secret","findings":[{"path":"src/Invented.php","reason":"it reads .env"}]}\'',
    ));

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], ['src/Ship.php'])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Unreadable)
        ->and($review->summary)->toBe('The agent named [src/Invented.php], and this delta holds no such file.');
});

it('writes no verdict when the agent stops with a failure', function (): void {
    $agent = new ReviewWithAgent(stubBinary("cat > /dev/null\necho 'the model is not reachable' >&2\nexit 3"));

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Unreadable)
        ->and($review->summary)->toContain('exit code [3]')
        ->and($review->summary)->toContain('the model is not reachable');
});

it('writes no verdict when the agent answers with prose', function (): void {
    $agent = new ReviewWithAgent(stubBinary("cat > /dev/null\necho 'I think it is fine.'"));

    $review = $agent->handle(['acme/widget' => agentPrompt('the delta', [], [])])['acme/widget'];

    expect($review->verdict)->toBe(AgentVerdict::Unreadable)
        ->and($review->summary)->toBe('I think it is fine.');
});

it('names the binary that it cannot run', function (): void {
    (new ReviewWithAgent('agent-that-is-not-installed'))->handle(['acme/widget' => agentPrompt('the delta', [], [])]);
})->throws(AgentFailedException::class, 'Could not run [agent-that-is-not-installed].');

it('finds the agent that the environment names', function (): void {
    putenv('VET_AGENT_BINARY=/usr/local/bin/my-agent');

    try {
        expect(ReviewWithAgent::default()->name())->toBe('my-agent');
    } finally {
        putenv('VET_AGENT_BINARY');
    }
});
