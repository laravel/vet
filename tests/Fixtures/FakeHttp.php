<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class FakeHttp
{
    /**
     * @var array<int, RequestInterface>
     */
    public array $requests = [];

    public readonly Client $client;

    /**
     * @param  array<int, Response|Throwable>  $responses
     */
    public function __construct(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler): mixed {
            $this->requests[] = $request;

            return $handler($request, $options);
        });

        $this->client = new Client(['handler' => $stack]);
    }

    public static function redirect(string $location): Response
    {
        return new Response(302, ['Location' => $location]);
    }

    public static function body(string $body): Response
    {
        return new Response(200, [], $body);
    }

    /**
     * @return array<int, string>
     */
    public function urls(): array
    {
        return array_map(static fn (RequestInterface $request): string => (string) $request->getUri(), $this->requests);
    }

    public function header(string $url, string $name): ?string
    {
        foreach ($this->requests as $request) {
            if ((string) $request->getUri() !== $url) {
                continue;
            }

            return $request->hasHeader($name) ? $request->getHeaderLine($name) : null;
        }

        return null;
    }
}
