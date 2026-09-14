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
        $finder = new ExecutableFinder;

        foreach (AgentType::cases() as $type) {
            $executable = $finder->find($type->value);

            if ($executable !== null) {
                return new self($type, $executable, AgentModel::default(), self::TIMEOUT, app(ProgressDots::class));
            }
        }

        throw AgentFailedException::missing();
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

    /**
     * @param  array<string, AgentPrompt>  $prompts
     * @return array<string, AgentReview>
     */
    public function handle(array $prompts): array
    {
        $schemaFile = $this->schemaFile();

        try {
            return $this->review($schemaFile, $prompts);
        } finally {
            unlink($schemaFile);
        }
    }

    /**
     * @param  array<string, AgentPrompt>  $prompts
     * @return array<string, AgentReview>
     */
    private function review(string $schemaFile, array $prompts): array
    {
        $arguments = $this->type->arguments($schemaFile, $this->model);

        $queue = $prompts;
        $reviews = [];
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::CONCURRENCY) {
                $package = array_key_first($queue);
                $process = new Process([$this->executable, ...$arguments], null, null, $queue[$package]->text);
                $process->setTimeout($this->timeout);
                $process->start();

                $running[$package] = $process;
                unset($queue[$package]);
            }

            foreach ($running as $package => $process) {
                $review = $this->settled($package, $prompts[$package], $process);

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
                $this->firstLine(trim($process->getErrorOutput()).' '.$output),
            ));
        }

        $answer = AgentAnswer::read($this->type->answerOf($output));

        if (! $answer instanceof AgentAnswer) {
            return $this->unreadable($package, $output === ''
                ? 'The agent wrote nothing.'
                : $this->firstLine($output));
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

    private function firstLine(string $output): string
    {
        return trim((string) preg_replace('/\R.*/s', '', trim($output)));
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
