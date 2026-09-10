<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\BuildAgentPrompt;
use App\Actions\CacheArtifact;
use App\Actions\ColdCacheArtifact;
use App\Actions\ReviewWithAgent;
use App\Enums\AgentType;
use App\Support\ControlSafeComponents;
use App\Support\ControlSafeFormatter;
use App\ValueObjects\AgentModel;
use App\ValueObjects\AgentPrompt;
use App\ValueObjects\AgentReview;
use App\ValueObjects\Delta;
use Laravel\Prompts\Output\ConsoleOutput as PromptOutput;
use Laravel\Prompts\Prompt;
use LaravelZero\Framework\Commands\Command as LaravelZeroCommand;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\suggest;

abstract class Command extends LaravelZeroCommand
{
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $formatter = $output->getFormatter();

        if (! $formatter instanceof ControlSafeFormatter) {
            $output->setFormatter(new ControlSafeFormatter($formatter));

            $this->writePromptsWithoutTheSanitizer($output, $formatter);
        }

        $this->components = new ControlSafeComponents($this->output);

        $this->installColdCache();
    }

    /**
     * @param  array<string, ?Delta>  $deltas
     * @return array<string, AgentReview>
     */
    protected function agentReviews(array $deltas): array
    {
        $builder = new BuildAgentPrompt;

        /** @var array<string, AgentPrompt> $prompts */
        $prompts = [];

        foreach ($deltas as $package => $delta) {
            if ($delta instanceof Delta && ! $delta->isEmpty()) {
                $prompts[$package] = $builder->handle($delta);
            }
        }

        $agent = ReviewWithAgent::default();

        if ($prompts === []) {
            return [];
        }

        $agent = $agent->withModel($this->agentModel($agent));

        if ($this->writesProse()) {
            $this->newLine();
            $this->components->info(sprintf(
                'Reading [%d] delta(s) with [%s]. This takes a moment.',
                count($prompts),
                $agent->name(),
            ));
        }

        return $agent->handle($prompts);
    }

    protected function asksQuestions(): bool
    {
        return $this->input->isInteractive()
            && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }

    private function agentModel(ReviewWithAgent $agent): AgentModel
    {
        $option = $this->input->hasOption('model') ? $this->option('model') : null;

        if (is_string($option)) {
            return AgentModel::of($option);
        }

        $agentType = $agent->type();

        if (! $agentType instanceof AgentType || ! $this->asksQuestions()) {
            return AgentModel::default();
        }

        return AgentModel::of(suggest(
            label: 'Which model do you want the agent to use?',
            options: $agentType->models(),
            placeholder: sprintf('Press enter for the default model of [%s].', $agent->name()),
            hint: 'Type a model name, or pick one from the list.',
        ));
    }

    private function writePromptsWithoutTheSanitizer(OutputInterface $output, OutputFormatterInterface $formatter): void
    {
        Prompt::setOutput(new PromptOutput(
            $output->getVerbosity(),
            $output->isDecorated(),
            $formatter,
        ));
    }

    private function installColdCache(): void
    {
        if (! $this->input->hasOption('no-cache') || $this->option('no-cache') !== true) {
            return;
        }

        $this->laravel->extend(
            CacheArtifact::class,
            static fn (CacheArtifact $cache): CacheArtifact => new ColdCacheArtifact($cache),
        );
    }

    private function writesProse(): bool
    {
        return ! $this->input->hasOption('json') || $this->option('json') !== true;
    }
}
