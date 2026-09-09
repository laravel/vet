<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\PlanComposerUpdate;
use App\Actions\RenderDelta;
use App\Actions\ResolveDelta;
use App\Exceptions\ComposerFailedException;
use App\Exceptions\VetException;
use App\Support\Invitation;
use App\Support\Json;
use App\ValueObjects\ComposerOperation;
use App\ValueObjects\ComposerPlan;
use App\ValueObjects\Delta;
use App\ValueObjects\InstalledRepository;
use App\ValueObjects\PlannedReview;
use App\ValueObjects\Project;
use App\ValueObjects\TrustFile;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class PreviewCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'preview
        {--path= : The project directory to preview (defaults to the current one)}
        {--no-cache : Re-download archives instead of reusing the cache}
        {--json : Emit machine-readable output}';

    /**
     * @var string
     */
    protected $description = 'Show what the next composer update changes, before vendor/ is touched';

    public function handle(): int
    {
        $path = $this->option('path');
        assert($path === null || is_string($path));

        try {
            $project = Project::locate($path ?? (string) getcwd());
            $plan = PlanComposerUpdate::default()->handle($project->rootPath);
            $reviews = $this->reviews($project, $plan);
        } catch (ComposerFailedException $composerFailedException) {
            $this->components->error($composerFailedException->getMessage());

            foreach ($composerFailedException->output as $line) {
                $this->line(sprintf('  <fg=gray>%s</>', OutputFormatter::escape($line)));
            }

            $this->newLine();

            return self::FAILURE;
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        return $this->option('json') === true
            ? $this->renderJson($reviews)
            : $this->render($reviews);
    }

    /**
     * @return array<int, PlannedReview>
     */
    private function reviews(Project $project, ComposerPlan $plan): array
    {
        $trustFile = TrustFile::forProject($project);
        $resolver = ResolveDelta::forProject($project);
        $installed = is_file($project->installedJsonPath())
            ? InstalledRepository::fromProject($project)
            : null;

        $reviews = [];

        foreach ($plan->operations as $operation) {
            if (! $this->installsTree($installed, $operation)) {
                continue;
            }

            $trusted = $trustFile->grantFor($operation->package)?->version;

            $reviews[] = new PlannedReview(
                operation: $operation,
                trusted: $trusted,
                delta: $operation->change->comparesTrees()
                    ? $resolver->resolve(
                        package: $operation->package,
                        from: $trusted ?? $this->installedVersion($installed, $operation),
                        to: $operation->to,
                        useCache: $this->option('no-cache') !== true,
                    )
                    : null,
            );
        }

        usort($reviews, static fn (PlannedReview $a, PlannedReview $b): int => [
            $a->weight(),
            $b->files() ?? 0,
            $a->operation->package,
        ] <=> [
            $b->weight(),
            $a->files() ?? 0,
            $b->operation->package,
        ]);

        return $reviews;
    }

    private function installsTree(?InstalledRepository $installed, ComposerOperation $operation): bool
    {
        if (! $installed instanceof InstalledRepository || ! $installed->has($operation->package)) {
            return true;
        }

        return $installed->get($operation->package)->installsTree();
    }

    private function installedVersion(?InstalledRepository $installed, ComposerOperation $operation): ?string
    {
        if ($installed instanceof InstalledRepository && $installed->has($operation->package)) {
            return $installed->get($operation->package)->version;
        }

        return $operation->from;
    }

    /**
     * @param  array<int, PlannedReview>  $reviews
     */
    private function render(array $reviews): int
    {
        $this->newLine();

        if ($reviews === []) {
            $this->components->info('The next `composer update` changes nothing in vendor/.');
            $this->newLine();

            return self::SUCCESS;
        }

        $renderer = new RenderDelta($this->output);

        $this->line(sprintf('  <options=bold>to review</> <fg=gray>(%d, worst first)</>', count($reviews)));
        $this->newLine();

        foreach ($reviews as $review) {
            $this->components->twoColumnDetail(
                sprintf(
                    '<fg=yellow>%s</> <fg=gray>%s</>',
                    $review->operation->package,
                    $review->versions(),
                ),
                sprintf('<fg=gray>%s</>', $review->cost()),
            );

            $this->line(sprintf('      <fg=gray>%s</>', $review->reason()));

            if ($review->delta instanceof Delta) {
                $this->newLine();
                $renderer->buckets($review->delta);
            }
        }

        $this->newLine();
        $this->components->info($this->output->isVerbose()
            ? sprintf('[%d] package(s) change. Run `composer update`, then record them with `vet trust`.', count($reviews))
            : sprintf(
                '[%d] package(s) change. Read every change with `%s`, then run `composer update`.',
                count($reviews),
                Invitation::verbose('vet preview -v'),
            ));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, PlannedReview>  $reviews
     */
    private function renderJson(array $reviews): int
    {
        $renderer = new RenderDelta($this->output);

        $this->output->write(Json::encode([
            'operations' => count($reviews),
            'packages' => array_map(
                static fn (PlannedReview $review): array => $review->toArray($renderer),
                $reviews,
            ),
        ]), false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
