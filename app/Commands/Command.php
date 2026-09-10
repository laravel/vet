<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\BuildAgentPrompt;
use App\Actions\ReviewWithAgent;
use App\Support\ControlSafeComponents;
use App\Support\ControlSafeFormatter;
use App\ValueObjects\AgentPrompt;
use App\ValueObjects\AgentReview;
use App\ValueObjects\Delta;
use LaravelZero\Framework\Commands\Command as LaravelZeroCommand;
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
        }

        $this->components = new ControlSafeComponents($this->output);
    }

    /**
     * @param  array<string, ?Delta>  $deltas
     * @return array<string, AgentReview>
     */
    protected function agentReviews(array $deltas): array
    {
        if ($this->option('agent') !== true) {
            return [];
        }

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

    private function writesProse(): bool
    {
        return ! $this->input->hasOption('json') || $this->option('json') !== true;
    }
}
