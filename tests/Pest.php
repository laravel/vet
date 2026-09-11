<?php

declare(strict_types=1);

use App\ValueObjects\AgentPrompt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\Fixtures\StubAgent;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * @param  array<string, mixed>  $parameters
 */
function command(string $name, array $parameters): PendingCommand
{
    /** @phpstan-ignore method.notFound, return.type */
    return test()->artisan($name, $parameters);
}

/**
 * @param  array<int, array<string, string|null>>  $operations
 */
function composerPlanFile(array $operations): string
{
    $path = sys_get_temp_dir().'/vet-plan-'.bin2hex(random_bytes(8)).'.json';

    file_put_contents($path, (string) json_encode(['operations' => $operations]));

    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

function stubAgent(StubAgent $stub): string
{
    $directory = sys_get_temp_dir().'/vet-tests/'.bin2hex(random_bytes(8));

    register_shutdown_function(static function () use ($directory): void {
        File::deleteDirectory($directory);
    });

    return $stub->install($directory, 'agent');
}

/**
 * @param  array<int, string>  $unread
 * @param  array<int, string>  $paths
 */
function agentPrompt(string $text, array $unread, array $paths): AgentPrompt
{
    return new AgentPrompt($text, $unread, $paths);
}

/**
 * @param  array<string, mixed>  $parameters
 */
function vet(array $parameters): int
{
    return Artisan::call('vet', [...$parameters, '--no-interaction' => true]);
}

/**
 * @param  array<string, mixed>  $parameters
 */
function trust(string $package, array $parameters): PendingCommand
{
    return command('vet', $parameters)
        ->expectsQuestion('How do you want to review these packages?', 'manual')
        ->expectsQuestion('Which packages do you trust?', [$package]);
}

/**
 * @template TResult
 *
 * @param  array<string, string|null>  $variables
 * @param  Closure(): TResult  $callback
 * @return TResult
 */
function withEnvironment(array $variables, Closure $callback): mixed
{
    $saved = [];

    foreach ($variables as $variable => $value) {
        $saved[$variable] = getenv($variable);

        putenv($value === null ? $variable : $variable.'='.$value);
    }

    try {
        return $callback();
    } finally {
        foreach ($saved as $variable => $value) {
            putenv($value === false ? $variable : $variable.'='.$value);
        }
    }
}
