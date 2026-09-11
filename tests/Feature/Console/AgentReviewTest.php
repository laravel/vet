<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Fixture;
use Tests\Fixtures\StaleProject;
use Tests\Fixtures\StubAgent;

it('hands each delta to the agent, and writes the verdict under the package', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent(StubAgent::answering('{"verdict":"risk","summary":"[src/New.php] writes a path outside the package","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('[agent] reviews [5] packages (')
            ->expectsOutputToContain('FAIL   [src/New.php] writes a path outside the package')
            ->expectsQuestion('Which packages do you trust?', [])
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('writes each finding of the agent under the verdict', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent(StubAgent::answering('{"verdict":"risk","summary":"[src/New.php] runs a shell command","findings":[{"path":"src/New.php","reason":"it calls [exec]"}]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('src/New.php  it calls [exec]')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('writes no verdict when the agent names a file that the delta does not hold', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent(StubAgent::answering('{"verdict":"risk","summary":"it reads a secret","findings":[{"path":"src/Invented.php","reason":"it reads .env"}]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('WARN   The agent named [src/Invented.php]')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('writes a partial verdict when the prompt holds no byte of an opaque artifact', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('WARN   nothing reaches outside the package')
            ->expectsOutputToContain('The agent did not read [2] files, because they are not text. Read them yourself:')
            ->expectsOutputToContain('builds/native.so  17 B')
            ->expectsOutputToContain('builds/tool.phar  30 B')
            ->doesntExpectOutputToContain('partial')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('gives the agent the package, the versions and the source of each change', function (): void {
    $fixture = Fixture::open('stale-project');

    $agent = $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"nothing","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $prompt = StubAgent::promptGivenTo($agent);
    } finally {
        $fixture->remove();
    }

    expect($prompt)
        ->toContain('package: acme/widget')
        ->toContain('compared: 1.0.0 → 2.0.0')
        ->toContain('Answer with one JSON object')
        ->toContain('## runtime source')
        ->toContain('+++ b/src/Widget.php')
        ->and(preg_match('/<delta [0-9a-f]{8}>/', $prompt))->toBe(1);
});

it('hands the whole tree to the agent when vet holds no earlier tree', function (): void {
    $fixture = Fixture::open('no-trust-file');

    file_put_contents($fixture->path('vet.json'), '{"schema": 4, "require": {}, "require-dev": {}}');

    $agent = $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"this tree reaches outside nothing","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('PASS   this tree reaches outside nothing')
            ->expectsQuestion('Which packages do you trust?', [])
            ->assertExitCode(1)
            ->run();

        $prompt = StubAgent::promptGivenTo($agent);
    } finally {
        $fixture->remove();
    }

    expect($prompt)->toContain('compared: nothing → ');
});

it('invites the reader to the agent in a terminal', function (): void {
    $fixture = Fixture::open('delta-shapes');

    try {
        vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('Run [vet] in a terminal to hand every change to your coding agent.');
});

it('invites the reader to the agent, whatever the size of the batch', function (): void {
    $project = StaleProject::amongUngranted(21);

    try {
        vet(['--path' => $project->rootPath]);
        $output = Artisan::output();
    } finally {
        $project->remove();
    }

    expect($output)->toContain('Run [vet] in a terminal to hand every change to your coding agent.');
});

it('asks how to review a batch of any size', function (): void {
    $project = StaleProject::amongUngranted(21);

    try {
        command('vet', ['--path' => $project->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'manual')
            ->expectsQuestion('Which packages do you trust?', [])
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(1)
            ->run();
    } finally {
        $project->remove();
    }
});

it('reads a batch of any size when you ask for the agent', function (): void {
    $project = StaleProject::amongUngranted(20);
    $executable = StubAgent::answering('{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}')
        ->install(dirname($project->rootPath), 'agent');

    putenv('VET_AGENT_BINARY='.$executable);

    try {
        command('vet', ['--path' => $project->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('[agent] reviews [21] packages (')
            ->expectsQuestion('Which packages do you trust?', [])
            ->assertExitCode(1)
            ->run();
    } finally {
        putenv('VET_AGENT_BINARY');
        $project->remove();
    }
});

it('invites the reader to the whole packages when a trust file holds no earlier tree', function (): void {
    $fixture = Fixture::open('partly-audited');

    try {
        trust('acme/widget', ['--path' => $fixture->rootPath])->run();

        vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('Vet holds no earlier version to compare these packages to. Run [vet] in a terminal to hand the whole packages to your coding agent.');
});

it('puts the verdict of the agent on the row that you pick', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent(StubAgent::answering('{"verdict":"risk","summary":"[src/Widget.php] renames the widget","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('[agent] reviews [1] package (')
            ->expectsOutputToContain('FAIL   [src/Widget.php] renames the widget')
            ->expectsQuestion('Which packages do you trust?', [])
            ->expectsOutputToContain('Recorded nothing.')
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('aligns the verdict, the package and the versions of each row that you pick', function (): void {
    $fixture = Fixture::open('delta-shapes');
    $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"nothing reaches outside the package","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsChoice('Which packages do you trust?', [], [
                'acme/inert-only' => 'PASS  acme/inert-only     1.0.0 → 2.0.0',
                'acme/moved' => 'PASS  acme/moved          1.0.0 → 2.0.0',
                'acme/media' => 'WARN  acme/media          1.0.0 → 2.0.0  1 file not text',
                'acme/opaque' => 'WARN  acme/opaque         1.0.0 → 2.0.0  2 files not text',
                'acme/manifest-only' => 'PASS  acme/manifest-only  1.0.0 → 2.0.0',
            ], true)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('asks which model the agent uses before it reads, and passes the answer to the agent', function (): void {
    $fixture = Fixture::open('stale-project');
    $agent = $fixture->agentNamed('claude', StubAgent::answering('{"verdict":"clear","summary":"the delta renames one method","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsQuestion('Which model do you want the agent to use?', 'opus')
            ->expectsOutputToContain('[claude] reviews [1] package (')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $arguments = implode(' ', StubAgent::argumentsGivenTo($agent));
    } finally {
        $fixture->remove();
    }

    expect($arguments)->toContain('--print')
        ->toContain('--model opus');
});

it('passes the model of the option to the agent without a question', function (): void {
    $fixture = Fixture::open('stale-project');
    $agent = $fixture->agentNamed('claude', StubAgent::answering('{"verdict":"clear","summary":"the delta renames one method","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath, '--model' => 'sonnet'])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $arguments = implode(' ', StubAgent::argumentsGivenTo($agent));
    } finally {
        $fixture->remove();
    }

    expect($arguments)->toContain('--model sonnet');
});

it('keeps the default model of the agent when you press enter', function (): void {
    $fixture = Fixture::open('stale-project');
    $agent = $fixture->agentNamed('claude', StubAgent::answering('{"verdict":"clear","summary":"the delta renames one method","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsQuestion('Which model do you want the agent to use?', '')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();

        $arguments = implode(' ', StubAgent::argumentsGivenTo($agent));
    } finally {
        $fixture->remove();
    }

    expect($arguments)->not->toContain('--model');
});

it('asks no model when the agent is not one that vet knows', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"the delta renames one method","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('[agent] reviews [1] package (')
            ->expectsQuestion('Which packages do you trust?', [])
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('refuses a model for an agent that vet does not know', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"nothing","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath, '--model' => 'opus'])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('Could not pass a model to [')
            ->expectsQuestion('Which packages do you trust?', [])
            ->assertExitCode(1)
            ->run();
    } finally {
        $fixture->remove();
    }
});

it('hands every delta to the agent when you ask for it, then lets you pick', function (): void {
    $fixture = Fixture::open('stale-project');
    $fixture->agent(StubAgent::answering('{"verdict":"clear","summary":"the delta renames one method","findings":[]}'));

    try {
        command('vet', ['--path' => $fixture->rootPath])
            ->expectsQuestion('How do you want to review these packages?', 'agent')
            ->expectsOutputToContain('[agent] reviews [1] package (')
            ->expectsOutputToContain('WARN   the delta renames one method')
            ->expectsQuestion('Which packages do you trust?', ['acme/widget'])
            ->assertExitCode(0)
            ->run();

        $trustFile = $fixture->read('vet.json');
    } finally {
        $fixture->remove();
    }

    expect($trustFile)->toContain('"version": "2.0.0"');
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
