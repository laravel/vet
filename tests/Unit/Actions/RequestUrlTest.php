<?php

declare(strict_types=1);

use App\Actions\RequestUrl;
use App\Exceptions\FetchFailedException;

function redirectServer(): string
{
    static $base = null;

    if (is_string($base)) {
        return $base;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');

    if ($socket === false) {
        throw new RuntimeException('Could not reserve a port for the test server.');
    }

    $name = (string) stream_socket_get_name($socket, false);
    $port = (int) mb_substr($name, (int) mb_strrpos($name, ':') + 1);

    fclose($socket);

    $router = __DIR__.'/../../Servers/redirect-router.php';

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the test server.');
    }

    register_shutdown_function(static function () use ($process): void {
        proc_terminate($process);
        proc_close($process);
    });

    $base = 'http://127.0.0.1:'.$port;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:'.$port, $code, $message, 1);

        if (is_resource($probe)) {
            fclose($probe);

            return $base;
        }

        usleep(50_000);
    }

    throw new RuntimeException('The test server did not accept a connection.');
}

it('follows a redirect chain to the final response', function (): void {
    $body = (new RequestUrl('vet-test', 'secret-token'))->get(redirectServer().'/hop/3');

    expect(json_decode($body, true))
        ->toMatchArray(['path' => '/end', 'authorization' => null, 'user_agent' => 'vet-test']);
});

it('resolves a relative location against the current url', function (): void {
    $body = (new RequestUrl('vet-test', 'secret-token'))->get(redirectServer().'/relative');

    expect(json_decode($body, true))->toMatchArray(['path' => '/deeper/end']);
});

it('follows five redirects and refuses the sixth', function (): void {
    $request = new RequestUrl('vet-test');

    expect(json_decode($request->get(redirectServer().'/hop/5'), true))->toMatchArray(['path' => '/end']);

    expect(fn (): string => $request->get(redirectServer().'/loop'))
        ->toThrow(FetchFailedException::class, 'sent more than [5] redirects');
});
