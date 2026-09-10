<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\CacheArtifact;
use App\Actions\ColdCacheArtifact;
use App\Actions\ReviewWithAgent;
use App\Support\Bytes;
use App\Support\ControlSafeComponents;
use App\Support\ControlSafeFormatter;
use App\Support\PickedCountRenderer;
use App\ValueObjects\AgentBatch;
use App\ValueObjects\AgentReview;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Output\ConsoleOutput as PromptOutput;
use Laravel\Prompts\Prompt;
use LaravelZero\Framework\Commands\Command as LaravelZeroCommand;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
     * @return array<string, AgentReview>
     */
    protected function agentReviews(AgentBatch $batch): array
    {
        $agent = ReviewWithAgent::default();

        if ($batch->isEmpty()) {
            return [];
        }

        if ($this->writesProse()) {
            $this->newLine();
            $this->components->info(sprintf(
                'Reading [%d] delta(s) with [%s]. The prompts hold %s. This takes a moment.',
                $batch->count(),
                $agent->name(),
                Bytes::human($batch->bytes()),
            ));
        }

        return $agent->handle($batch->prompts);
    }

    private function writePromptsWithoutTheSanitizer(OutputInterface $output, OutputFormatterInterface $formatter): void
    {
        Prompt::setOutput(new PromptOutput(
            $output->getVerbosity(),
            $output->isDecorated(),
            $formatter,
        ));

        Prompt::addTheme('vet', [MultiSelectPrompt::class => PickedCountRenderer::class]);
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
