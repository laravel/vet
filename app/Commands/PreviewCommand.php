<?php

declare(strict_types=1);

namespace App\Commands;

use App\Actions\AuditProject;
use App\Actions\PlanComposerUpdate;
use App\Actions\RenderProjectAudit;
use App\Enums\AuditScreen;
use App\Exceptions\ComposerFailedException;
use App\Exceptions\VetException;
use App\ValueObjects\Project;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class PreviewCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'preview
        {--path= : The project directory to preview (defaults to the current one)}
        {--agent : Hand each delta to your coding agent, and show the verdict it writes}
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

        $useCache = $this->option('no-cache') !== true;

        try {
            $project = Project::locate($path ?? (string) getcwd());
            $plan = PlanComposerUpdate::default()->handle($project->rootPath);
            $auditor = AuditProject::forProject($project, $plan, $useCache);

            $discrepancies = $auditor->lockDiscrepancies();

            $screen = new RenderProjectAudit(
                $this->output,
                $project,
                $auditor,
                $auditor->reportOfPlan(),
                $useCache,
                AuditScreen::Planned,
            );

            $screen->withAgentReviews($this->agentReviews($screen->deltas($this->option('agent') === true)));
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
            ? $screen->json($discrepancies)
            : $screen->render($discrepancies, $this->option('agent') === true);
    }
}
