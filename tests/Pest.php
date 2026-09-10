<?php

declare(strict_types=1);

use App\ValueObjects\AgentPrompt;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * @param  array<string, mixed>  $parameters
 */
function command(string $name, array $parameters): PendingCommand
{
    /** @phpstan-ignore method.notFound */
    return test()->artisan($name, $parameters);
}

function stubBinary(string $script): string
{
    $path = sys_get_temp_dir().'/vet-tests/'.bin2hex(random_bytes(8));

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }

    file_put_contents($path, "#!/bin/sh\n".$script."\n");
    chmod($path, 0o755);

    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

/**
 * @param  array<int, string>  $unread
 * @param  array<int, string>  $paths
 */
function agentPrompt(string $text, array $unread, array $paths): AgentPrompt
{
    return new AgentPrompt($text, $unread, $paths);
}
