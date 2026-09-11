<?php

declare(strict_types=1);

use App\ValueObjects\AgentBatch;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;
use Tests\Fixtures\StaleProject;

it('hands each delta to the agent, and writes the verdict under the package', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"[src/New.php] writes a path outside the package","findings":[]}\'');

    try {
        $status = vet(['--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)
        ->toContain('Reading [5] delta(s) with [agent].')
        ->toContain('agent  RISK  [src/New.php] writes a path outside the package');
});

it('writes each finding of the agent under the verdict', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"[src/New.php] runs a shell command","findings":[{"path":"src/New.php","reason":"it calls [exec]"}]}\'');

    try {
        vet(['packages' => ['acme/moved'], '--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('src/New.php  it calls [exec]');
});

it('writes no verdict when the agent names a file that the delta does not hold', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"it reads a secret","findings":[{"path":"src/Invented.php","reason":"it reads .env"}]}\'');

    try {
        vet(['packages' => ['acme/moved'], '--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('agent  no verdict  The agent named [src/Invented.php]');
});

it('writes a partial verdict when the prompt holds no byte of an opaque artifact', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}\'');

    try {
        vet(['packages' => ['acme/opaque'], '--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('agent  partial')
        ->toContain('file(s) did not reach the agent');
});

it('gives the agent the package, the versions and the source of each change', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $written = sys_get_temp_dir().'/vet-prompt-'.bin2hex(random_bytes(6));

    $fixture->agent('cat >> '.escapeshellarg($written)."\n".'echo \'{"verdict":"clear","summary":"nothing","findings":[]}\'');

    try {
        vet(['packages' => ['acme/moved'], '--path' => $fixture->rootPath, '--agent' => true]);
        $prompt = (string) file_get_contents($written);
    } finally {
        unlink($written);
        $fixture->remove();
    }

    expect($prompt)
        ->toContain('package: acme/moved')
        ->toContain('compared: 1.0.0 → 2.0.0')
        ->toContain('Answer with one JSON object')
        ->toContain('## runtime source')
        ->toContain('+++ b/src/New.php')
        ->and(preg_match('/<delta [0-9a-f]{8}>/', $prompt))->toBe(1);
});

it('writes the verdict of the agent in the json', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}\'');

    try {
        vet(['--path' => $fixture->rootPath, '--agent' => true, '--json' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    $report = json_decode($output, true);

    expect($report)->toBeArray();

    /** @var array{unaudited: array<int, array{agent: mixed}>} $report */
    expect($report['unaudited'][0]['agent'])->toBe([
        'verdict' => 'clear',
        'summary' => 'nothing reaches outside the package',
        'findings' => [],
    ]);
});

it('hands the whole tree to the agent when vet holds no earlier tree', function (): void {
    $fixture = Fixture::open('no-trust-file');
    $written = sys_get_temp_dir().'/vet-prompt-'.bin2hex(random_bytes(6));

    file_put_contents($fixture->path('vet.json'), '{"schema": 4, "require": {}, "require-dev": {}}');

    $fixture->agent('cat >> '.escapeshellarg($written)."\n".'echo \'{"verdict":"clear","summary":"this tree reaches outside nothing","findings":[]}\'');

    try {
        $status = vet(['--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
        $prompt = (string) file_get_contents($written);
    } finally {
        unlink($written);
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($prompt)->toContain('compared: nothing → ')
        ->and($output)->toContain('agent  clear  this tree reaches outside nothing');
});

it('names the agent that it cannot run', function (): void {
    $fixture = Fixture::open('delta-shapes');

    putenv('VET_AGENT_BINARY=agent-that-is-not-installed');

    try {
        $status = vet(['--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not run [agent-that-is-not-installed].');
});

it('needs no agent when every package is covered', function (): void {
    $fixture = Fixture::open('audited-project');
    $path = getenv('PATH');
    $empty = dirname($fixture->rootPath).'/empty-path';

    mkdir($empty);
    putenv('PATH='.$empty);

    try {
        $status = vet(['--path' => $fixture->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        putenv($path === false ? 'PATH' : 'PATH='.$path);
        $fixture->remove();
    }

    expect($status)->toBe(0)
        ->and($output)->toContain('All [2] packages are covered.')
        ->and(str_contains($output, 'Could not find an agent'))->toBeFalse();
});

it('asks no model when the json holds the verdict', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agentNamed('claude', 'cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true, '--json' => true])
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('invites the reader to the agent when the flag is absent', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('Hand every change to your coding agent with [vet --agent].');
});

it('invites the reader to a few packages at a time when the batch is over the budget', function (): void {
    $project = StaleProject::amongUngranted(AgentBatch::MAX_PROMPTS);

    try {
        vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($output)
        ->toContain('Hand a few packages at a time to your coding agent with [vet <package> --agent].')
        ->toContain('One run reads [20] package(s) or 2.0 MB, and this batch holds [21] package(s) and')
        ->and($output)->not->toContain('[vet --agent]');
});

it('asks no question about the agent when the batch is over the budget', function (): void {
    $project = StaleProject::amongUngranted(AgentBatch::MAX_PROMPTS);

    try {
        command('vet', ['--path' => $project->rootPath])
            ->expectsOutputToContain('Hand a few packages at a time to your coding agent with [vet <package> --agent].')
            ->expectsQuestion('Which packages do you trust?', [])
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(1)
            ->run();
    } finally {
        $project->remove();
    }
});

it('reads the whole batch when the flag names the agent, whatever the budget', function (): void {
    $project = StaleProject::amongUngranted(AgentBatch::MAX_PROMPTS);
    $binary = dirname($project->rootPath).'/agent';

    file_put_contents($binary, "#!/bin/sh\ncat > /dev/null\necho '{\"verdict\":\"clear\",\"summary\":\"nothing reaches outside the package\",\"findings\":[]}'\n");
    chmod($binary, 0o755);
    putenv('VET_AGENT_BINARY='.$binary);

    try {
        $status = vet(['--path' => $project->rootPath, '--agent' => true]);
        $output = Artisan::output();
    } finally {
        putenv('VET_AGENT_BINARY');
        $project->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Reading [21] delta(s) with [agent]. The prompts hold ');
});

it('invites the reader to one package when a trust file holds no earlier tree', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])->run();

        vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('Hand one whole package to your coding agent with [vet <package> --agent].');
});

it('puts the verdict of the agent on the row that you pick', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"risk","summary":"[src/Widget.php] renames the widget","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsOutputToContain('Reading [1] delta(s) with [agent].')
            ->expectsOutputToContain('agent  RISK  [src/Widget.php] renames the widget')
            ->expectsQuestion('Which packages do you trust?', [])
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('asks which model the agent uses before it reads, and passes the answer to the agent', function (): void {
    $fixture = Fixture::open('stale-project');
    $written = sys_get_temp_dir().'/vet-arguments-'.bin2hex(random_bytes(6));
    $fixture->agentNamed('claude', 'echo "$@" > '.escapeshellarg($written)."\n".'cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsQuestion('Which model do you want the agent to use?', 'opus')
            ->expectsOutputToContain('Reading [1] delta(s) with [claude].')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $arguments = (string) file_get_contents($written);
    } finally {
        @unlink($written);
        $fixture->remove();
    }

    expect($arguments)->toContain('--print')
        ->toContain('--model opus');
});

it('passes the model of the option to the agent without a question', function (): void {
    $fixture = Fixture::open('stale-project');
    $written = sys_get_temp_dir().'/vet-arguments-'.bin2hex(random_bytes(6));
    $fixture->agentNamed('claude', 'echo "$@" > '.escapeshellarg($written)."\n".'cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true, '--model' => 'sonnet'])
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $arguments = (string) file_get_contents($written);
    } finally {
        @unlink($written);
        $fixture->remove();
    }

    expect($arguments)->toContain('--model sonnet');
});

it('keeps the default model of the agent when you press enter', function (): void {
    $fixture = Fixture::open('stale-project');
    $written = sys_get_temp_dir().'/vet-arguments-'.bin2hex(random_bytes(6));
    $fixture->agentNamed('claude', 'echo "$@" > '.escapeshellarg($written)."\n".'cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsQuestion('Which model do you want the agent to use?', '')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $arguments = (string) file_get_contents($written);
    } finally {
        @unlink($written);
        $fixture->remove();
    }

    expect($arguments)->not->toContain('--model');
});

it('asks no model when the agent is not one that vet knows', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsOutputToContain('Reading [1] delta(s) with [agent].')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('refuses a model for an agent that vet does not know', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"nothing","findings":[]}\'');

    try {
        $status = vet(['--path' => $fixture->rootPath, '--agent' => true, '--model' => 'opus']);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not pass a model to [')
        ->toContain('Name one of [claude], [codex], [gemini] in [VET_AGENT_BINARY], or drop the model.');
});

it('hands every delta to the agent when you ask for it, then lets you pick', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('Reading [1] delta(s) with [agent].')
            ->expectsOutputToContain('agent  partial  [1] file(s) did not reach the agent. the delta renames one method')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "2.0.0"');
});

it('asks no question about the agent when the flag names it', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent('cat > /dev/null'."\n".'echo \'{"verdict":"clear","summary":"the delta renames one method","findings":[]}\'');

    try {
        command('vet', ['--path' => $fixture->rootPath, '--agent' => true])
            ->expectsOutputToContain('agent  partial  [1] file(s) did not reach the agent. the delta renames one method')
            ->expectsQuestion('Which packages do you trust?', [])
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('names the agent that it cannot run, and still lets you pick', function (): void {
    $fixture = Fixture::open('stale-project');

    putenv('VET_AGENT_BINARY=agent-that-is-not-installed');

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('Could not run [agent-that-is-not-installed].')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->expectsOutputToContain('Recorded [acme/widget] [2.0.0]')
            ->assertExitCode(0)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('names the agent that it cannot run for one package', function (): void {
    $fixture = Fixture::open('stale-project');

    putenv('VET_AGENT_BINARY=agent-that-is-not-installed');

    try {
        $status = vet(['packages' => ['acme/widget'], '--agent' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($status)->toBe(1)
        ->and($output)->toContain('Could not run [agent-that-is-not-installed].');
});

it('sends nothing to the agent when the delta of one package holds no change', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent("cat > /dev/null\nexit 1");

    try {
        vet(['packages' => ['acme/moved'], '--from' => '2.0.0', '--agent' => true, '--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)
        ->toContain('agent  not sent  This delta holds no change, so vet sent nothing.')
        ->toContain('No files differ between [2.0.0] and [2.0.0].');
});
