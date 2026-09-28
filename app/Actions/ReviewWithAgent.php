<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AgentType;
use App\Enums\AgentVerdict;
use App\Exceptions\AgentFailedException;
use App\Support\ProgressDots;
use App\ValueObjects\AgentAnswer;
use App\ValueObjects\AgentModel;
use App\ValueObjects\AgentPrompt;
use App\ValueObjects\AgentReview;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class ReviewWithAgent
{
    private const int TIMEOUT = 300;

    private const int CONCURRENCY = 4;

    private const int MAX_SUMMARY = 200;

    public function __construct(
        private AgentType $type,
        private string $executable,
        private AgentModel $model,
        private int $timeout,
        private ProgressDots $dots,
    ) {}

    public static function default(): self
    {
        $configured = getenv('VET_AGENT');
        $name = $configured === false || trim($configured) === ''
            ? AgentType::Claude->value
            : trim($configured);

        return self::named($name);
    }

    public static function named(string $name): self
    {
        $name = trim($name);
        $type = AgentType::tryFrom($name);

        if ($type === null) {
            throw AgentFailedException::unknown($name);
        }

        $executable = (new ExecutableFinder)->find($type->value);

        if ($executable !== null) {
            return new self($type, $executable, AgentModel::default(), self::TIMEOUT, app(ProgressDots::class));
        }

        throw AgentFailedException::missingConfigured($type);
    }

    public function withModel(AgentModel $model): self
    {
        return new self($this->type, $this->executable, $model, $this->timeout, $this->dots);
    }

    public function name(): string
    {
        return $this->type->value;
    }

    public function type(): AgentType
    {
        return $this->type;
    }

    public function handle(array $prompts): array
    {
        $schemaFile = $this->schemaFile();

        try {
            return $this->review($schemaFile, $prompts);
        } finally {
            unlink($schemaFile);
        }
    }

    private function review(string $schemaFile, array $prompts): array
    {
        $arguments = $this->type->arguments($schemaFile, $this->model);

        $queue = $prompts;
        $reviews = [];
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::CONCURRENCY) {
                $package = array_key_first($queue);
                $prompt = $queue[$package] ?? null;

                if (! $prompt instanceof AgentPrompt) {
                    throw new LogicException('Each agent prompt must be an AgentPrompt.');
                }

                $command = [$this->executable, ...$arguments];
                $input = $prompt->text;

                if ($this->type === AgentType::Opencode) {
                    $command[] = $input;
                    $input = null;
                }

                $process = new Process($command, null, null, $input);
                $process->setTimeout($this->timeout);
                $process->start();

                $running[$package] = $process;
                unset($queue[$package]);
            }

            foreach ($running as $package => $process) {
                $prompt = $prompts[$package] ?? null;

                if (! $prompt instanceof AgentPrompt) {
                    throw new LogicException('Each agent prompt must be an AgentPrompt.');
                }

                $review = $this->settled($package, $prompt, $process);

                if ($review instanceof AgentReview) {
                    $this->dots->mark();
                    $reviews[$package] = $review;
                    unset($running[$package]);
                }
            }

            if ($running !== []) {
                usleep(20_000);
            }
        }

        return $reviews;
    }

    private function settled(string $package, AgentPrompt $prompt, Process $process): ?AgentReview
    {
        try {
            $process->checkTimeout();

            if ($process->isRunning()) {
                return null;
            }
        } catch (ProcessTimedOutException) {
            return $this->unreadable($package, sprintf(
                'The agent gave no answer in [%d] %s.',
                $this->timeout,
                Str::plural('second', $this->timeout),
            ));
        }

        return $this->read($package, $prompt, $process);
    }

    private function read(string $package, AgentPrompt $prompt, Process $process): AgentReview
    {
        $output = trim($process->getOutput());

        if (! $process->isSuccessful()) {
            return $this->unreadable($package, sprintf(
                'The agent stopped with exit code [%s]: %s',
                $process->getExitCode() === null ? 'unknown' : (string) $process->getExitCode(),
                $this->diagnostic(trim($process->getErrorOutput()).' '.$output),
            ));
        }

        $answer = AgentAnswer::read($this->type->answerOf($output));

        if (! $answer instanceof AgentAnswer) {
            return $this->unreadable($package, $output === ''
                ? 'The agent wrote nothing.'
                : $this->diagnostic($output));
        }

        foreach ($answer->findings as $finding) {
            if (! $prompt->holds($finding->path)) {
                return $this->unreadable($package, sprintf(
                    'The agent named [%s], and this delta holds no such file.',
                    $finding->path,
                ));
            }
        }

        $verdict = AgentVerdict::read($answer->verdict);
        $summary = $answer->summary === '' ? 'The agent wrote no summary.' : $answer->summary;

        if ($verdict === AgentVerdict::Clear && $prompt->unread !== []) {
            $verdict = AgentVerdict::Partial;
        }

        return new AgentReview($package, $verdict, $this->clamp($summary), $answer->findings, $prompt->unread);
    }

    private function unreadable(string $package, string $summary): AgentReview
    {
        return new AgentReview($package, AgentVerdict::NoVerdict, $this->clamp($summary), [], []);
    }

    private function diagnostic(string $output): string
    {
        $plain = trim((string) preg_replace('#\e\[[0-?]*[ -/]*[@-~]#', '', $output));
        $lines = preg_split('/\R/', $plain) ?: [];
        $failures = array_values(array_filter($lines, static fn (string $line): bool => preg_match(
            '/\b(?:error|failed|failure|denied|not permitted)\b/i',
            $line,
        ) === 1));
        $details = array_slice($failures === [] ? $lines : $failures, -3);
        $plain = implode(' ', $details);
        $plain = trim((string) preg_replace('/\s+/', ' ', $plain));
        $diagnostic = null;

        foreach ($details as $detail) {
            $start = strpos($detail, '{');

            if ($start === false) {
                continue;
            }

            $decoded = json_decode(substr($detail, $start), true);
            $error = is_array($decoded) ? ($decoded['error'] ?? []) : [];
            $data = is_array($decoded) ? ($decoded['data'] ?? []) : [];
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            $message ??= is_array($decoded) ? ($decoded['detail'] ?? null) : null;
            $message ??= is_array($data) ? ($data['message'] ?? null) : null;
            $reference = is_array($data) ? ($data['ref'] ?? null) : null;

            if (is_string($message) && $message !== '') {
                $diagnostic = implode(' ', array_filter([
                    $message,
                    is_string($reference) && $reference !== '' ? sprintf('[ref: %s]', $reference) : null,
                ]));

                if (str_contains($message, 'not supported when using Codex with a ChatGPT account')) {
                    return $diagnostic;
                }
            }
        }

        return $diagnostic ?? $plain;
    }

    private function clamp(string $line): string
    {
        return mb_strlen($line) > self::MAX_SUMMARY
            ? mb_substr($line, 0, self::MAX_SUMMARY - 1).'…'
            : $line;
    }

    private function schemaFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'vet-agent-schema-');

        file_put_contents($path, AgentAnswer::schema());

        return $path;
    }
}
