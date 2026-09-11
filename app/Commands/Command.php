<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\CacheArtifact;
use App\Actions\ColdCacheArtifact;
use App\Actions\ReviewWithAgent;
use App\Enums\AgentType;
use App\Support\Bytes;
use App\Support\ControlSafeComponents;
use App\Support\ControlSafeFormatter;
use App\Support\PickedCountRenderer;
use App\Support\PromptOutput;
use App\Support\RevertibleMultiSelectPrompt;
use App\ValueObjects\AgentBatch;
use App\ValueObjects\AgentModel;
use App\ValueObjects\AgentReview;
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

            $this->writePromptsWithoutTheSanitizer($formatter);
        }

        $this->components = new ControlSafeComponents($this->output);

        $this->installColdCache();
    }

    /**
     * @return array<string, AgentReview>
     */
    protected function agentReviews(AgentBatch $batch): array
    {
        if ($batch->isEmpty()) {
            return [];
        }

        $agent = ReviewWithAgent::default();
        $agent = $agent->withModel($this->agentModel($agent));

        if ($this->writesProse()) {
            $this->components->info(sprintf(
                'Reading [%d] delta(s) with [%s]. The prompts hold %s. This takes a moment.',
                $batch->count(),
                $agent->name(),
                Bytes::human($batch->bytes()),
            ));
        }

        return $agent->handle($batch->prompts);
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

        if (! $agentType instanceof AgentType || ! $this->asksQuestions() || ! $this->writesProse()) {
            return AgentModel::default();
        }

        return AgentModel::of(suggest(
            label: 'Which model do you want the agent to use?',
            options: $agentType->models(),
            placeholder: sprintf('Press enter for the default model of [%s].', $agent->name()),
            hint: 'Type a model name, or pick one from the list.',
        ));
    }

    private function writePromptsWithoutTheSanitizer(OutputFormatterInterface $formatter): void
    {
        Prompt::setOutput(new PromptOutput($this->output, $formatter));

        Prompt::addTheme('vet', [RevertibleMultiSelectPrompt::class => PickedCountRenderer::class]);
        Prompt::theme('vet');
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
