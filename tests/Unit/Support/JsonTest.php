<?php

declare(strict_types=1);

use App\Exceptions\FileNotFoundException;
use App\Exceptions\InvalidJsonException;
use App\Support\Json;

it('names a json file that holds no byte', function (): void {
    $path = sys_get_temp_dir().'/vet-json-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($path, '');

    try {
        expect(fn (): array => Json::readFile($path, 'the lock file'))->toThrow(FileNotFoundException::class);
    } finally {
        unlink($path);
    }
});

it('names a json file that holds no object', function (string $contents): void {
    $path = sys_get_temp_dir().'/vet-json-'.bin2hex(random_bytes(6)).'.json';

    file_put_contents($path, $contents);

    try {
        expect(fn (): array => Json::readFile($path, 'the lock file'))
            ->toThrow(InvalidJsonException::class, 'expected a JSON object at the top level.');
    } finally {
        unlink($path);
    }
})->with([
    'a string' => '"acme/widget"',
    'a number' => '42',
    'null' => 'null',
]);
