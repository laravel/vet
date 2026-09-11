<?php

declare(strict_types=1);

use App\Support\BinaryName;

it('reads the name of a binary from its path', function (string $binary, string $name): void {
    expect(BinaryName::of($binary))->toBe($name);
})->with([
    'a bare name' => ['claude', 'claude'],
    'a unix path' => ['/usr/local/bin/claude', 'claude'],
    'a windows shim' => ['C:\\Users\\x\\AppData\\Roaming\\npm\\claude.cmd', 'claude'],
    'a windows shim in upper case' => ['C:\\tools\\CODEX.CMD', 'CODEX'],
    'a batch file' => ['C:\\tools\\gemini.bat', 'gemini'],
    'an executable' => ['C:\\tools\\claude.exe', 'claude'],
    'a dot in the name' => ['/opt/agent.v2', 'agent.v2'],
]);
