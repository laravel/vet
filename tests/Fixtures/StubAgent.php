<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;

final readonly class StubAgent
{
    private function __construct(
        private string $stdout,
        private string $stderr,
        private int $exitCode,
        private int $seconds,
    ) {}

    public static function answering(string $stdout): self
    {
        return new self($stdout, '', 0, 0);
    }

    public static function silent(): self
    {
        return new self('', '', 0, 0);
    }

    public static function promptGivenTo(string $executable): string
    {
        $prompt = file_get_contents($executable.'.prompt');

        if ($prompt === false) {
            throw new RuntimeException(sprintf('The stub agent [%s] received no prompt.', $executable));
        }

        return $prompt;
    }

    /**
     * @return array<int, string>
     */
    public static function argumentsGivenTo(string $executable): array
    {
        $arguments = file_get_contents($executable.'.arguments');

        if ($arguments === false) {
            throw new RuntimeException(sprintf('The stub agent [%s] received no arguments.', $executable));
        }

        return $arguments === '' ? [] : explode("\n", $arguments);
    }

    public function failing(int $exitCode, string $stderr): self
    {
        return new self($this->stdout, $stderr, $exitCode, $this->seconds);
    }

    public function sleeping(int $seconds): self
    {
        return new self($this->stdout, $this->stderr, $this->exitCode, $seconds);
    }

    public function install(string $directory, string $name): string
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $executable = $directory.'/'.$name.($this->windows() ? '.cmd' : '');
        $script = $directory.'/'.$name.'.php';

        file_put_contents($script, $this->script($executable));
        file_put_contents($executable, $this->windows()
            ? sprintf("@\"%s\" \"%s\" %%*\r\n", PHP_BINARY, $script)
            : sprintf("#!/bin/sh\nexec %s %s \"\$@\"\n", escapeshellarg(PHP_BINARY), escapeshellarg($script)));

        chmod($executable, 0o755);

        return $executable;
    }

    private function script(string $executable): string
    {
        return sprintf(
            "<?php\n".
            "file_put_contents(%s, (string) stream_get_contents(STDIN), FILE_APPEND);\n".
            "file_put_contents(%s, implode(\"\\n\", array_slice(\$argv, 1)));\n".
            "sleep(%d);\n".
            "fwrite(STDERR, %s);\n".
            "fwrite(STDOUT, %s);\n".
            "exit(%d);\n",
            var_export($executable.'.prompt', true),
            var_export($executable.'.arguments', true),
            $this->seconds,
            var_export($this->stderr, true),
            var_export($this->stdout, true),
            $this->exitCode,
        );
    }

    private function windows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
