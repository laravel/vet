<?php

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';

$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$base = 'http://'.(is_string($host) ? $host : '127.0.0.1');

if (preg_match('#^/hop/(\d+)$#', $path, $matches) === 1) {
    $remaining = (int) $matches[1];

    header('Location: '.($remaining <= 1 ? $base.'/end' : $base.'/hop/'.($remaining - 1)), true, 302);

    return true;
}

if ($path === '/relative') {
    header('Location: deeper/end', true, 302);

    return true;
}

if ($path === '/loop') {
    header('Location: '.$base.'/loop', true, 302);

    return true;
}

if ($path === '/end' || $path === '/deeper/end') {
    header('Content-Type: application/json');

    echo json_encode([
        'path' => $path,
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    return true;
}

http_response_code(404);

echo 'missing';

return true;
