<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AgentType;
use App\Enums\AgentVerdict;
use App\Exceptions\AgentFailedException;
use App\Support\BinaryName;
use App\ValueObjects\AgentAnswer;
use App\ValueObjects\AgentModel;
use App\ValueObjects\AgentPrompt;
use App\ValueObjects\AgentReview;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class ReviewWithAgent
{
    private const int TIMEOUT = 300;

    private const int CONCURRENCY = 4;

    private const int MAX_SUMMARY = 200;

    public function __construct(
        private string $binary,
        private AgentModel $model,
        private int $timeout,
    ) {}

    public static function default(): self
    {
        $binary = getenv('VET_AGENT_BINARY');

        if (is_string($binary) && $binary !== '') {
            return new self($binary, AgentModel::default(), self::TIMEOUT);
        }

        $finder = new ExecutableFinder;

        foreach (AgentType::cases() as $agentType) {
            if ($finder->find($agentType->value) !== null) {
                return new self($agentType->value, AgentModel::default(), self::TIMEOUT);
            }
        }

        throw AgentFailedException::missing();
    }

    public function withModel(AgentModel $model): self
    {
        if (! $model->isDefault() && ! $this->type() instanceof AgentType) {
            throw AgentFailedException::noModelFlag($this->binary);
        }

        return new self($this->binary, $model, $this->timeout);
    }

    public function name(): string
    {
        return BinaryName::of($this->binary);
    }

    public function type(): ?AgentType
    {
        return AgentType::of($this->binary);
    }

    /**
     * @param  array<string, AgentPrompt>  $prompts
     * @return array<string, AgentReview>
     */
    public function handle(array $prompts): array
    {
        $executable = $this->executable();
        $schemaFile = $this->schemaFile();

        try {
            return $this->review($executable, $schemaFile, $prompts);
        } finally {
            unlink($schemaFile);
        }
    }

    /**
     * @param  array<string, AgentPrompt>  $prompts
     * @return array<string, AgentReview>
     */
    private function review(string $executable, string $schemaFile, array $prompts): array
    {
        $agentType = $this->type();
        $arguments = $agentType instanceof AgentType ? $agentType->arguments($schemaFile, $this->model) : [];

        $queue = $prompts;
        $reviews = [];
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::CONCURRENCY) {
                $package = array_key_first($queue);
                $process = new Process([$executable, ...$arguments], null, null, $queue[$package]->text);
                $process->setTimeout($this->timeout);
                $process->start();

                $running[$package] = $process;
                unset($queue[$package]);
            }

            foreach ($running as $package => $process) {
                $review = $this->settled($package, $prompts[$package], $process, $agentType);

                if ($review instanceof AgentReview) {
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

    private function settled(string $package, AgentPrompt $prompt, Process $process, ?AgentType $agentType): ?AgentReview
    {
        try {
            $process->checkTimeout();

            if ($process->isRunning()) {
                return null;
            }
        } catch (ProcessTimedOutException) {
            return $this->unreadable($package, sprintf(
                'The agent gave no answer in [%d] second(s).',
                $this->timeout,
            ));
        }

        return $this->read($package, $prompt, $process, $agentType);
    }

    private function read(string $package, AgentPrompt $prompt, Process $process, ?AgentType $agentType): AgentReview
    {
        $output = trim($process->getOutput());

        if (! $process->isSuccessful()) {
            return $this->unreadable($package, sprintf(
                'The agent stopped with exit code [%s]: %s',
                $process->getExitCode() === null ? 'unknown' : (string) $process->getExitCode(),
                $this->firstLine(trim($process->getErrorOutput()).' '.$output),
            ));
        }

        $answer = AgentAnswer::read($agentType instanceof AgentType ? $agentType->answerOf($output) : $output);

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
            return new AgentReview($package, AgentVerdict::Partial, $this->clamp(sprintf(
                '[%d] file(s) did not reach the agent. %s',
                count($prompt->unread),
                $summary,
            )), $answer->findings);
        }

        return new AgentReview($package, $verdict, $this->clamp($summary), $answer->findings);
    }

    private function unreadable(string $package, string $summary): AgentReview
    {
        return new AgentReview($package, AgentVerdict::NoVerdict, $this->clamp($summary), []);
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

    private function executable(): string
    {
        if (is_file($this->binary) && is_executable($this->binary)) {
            return $this->binary;
        }

        $executable = (new ExecutableFinder)->find($this->binary);

        if ($executable === null) {
            throw AgentFailedException::notExecutable($this->binary);
        }

        return $executable;
    }
}
