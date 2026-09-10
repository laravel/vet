<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Fixture;

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

it('invites the reader to a baseline when no trust file exists', function (): void {
    $fixture = Fixture::open('no-trust-file');

    try {
        vet(['--path' => $fixture->rootPath]);
        $output = Artisan::output();
    } finally {
        $fixture->remove();
    }

    expect($output)->toContain('Record this one as your baseline with [vet --fresh]')
        ->and($output)->not->toContain('[vet --agent]')
        ->and($output)->not->toContain('Read every change with [vet -v]');
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
