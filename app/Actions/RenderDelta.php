<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BucketType;
use App\Enums\Gutter;
use App\Enums\PatchExtent;
use App\Support\ControlSafeComponents;
use App\Support\Invitation;
use App\ValueObjects\Change;
use App\ValueObjects\Delta;
use App\ValueObjects\ManifestChange;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class RenderDelta
{
    private const int MAX_PATHS = 5;

    private ControlSafeComponents $components;

    public function __construct(
        private OutputStyle $output,
        private Invitation $invitation,
        private Gutter $gutter,
        private PatchExtent $extent,
    ) {
        $this->components = new ControlSafeComponents($output);
    }

    public function report(Delta $delta): void
    {
        $this->output->newLine();
        $this->output->writeln(sprintf(
            '  <options=bold>delta</> <fg=gray>(%s)</>',
            $delta->comparesPublishedToInstalled()
                ? sprintf('[%s] as published → [%s] as installed', $delta->from, $delta->to)
                : sprintf('[%s] → [%s]', $delta->from, $delta->to),
        ));

        $this->components->twoColumnDetail(
            '<fg=gray>identity</>',
            sprintf('<fg=gray>%s → %s (%s)</>', $delta->fromHash->short(), $delta->toHash->short(), $delta->source->value),
        );

        if ($delta->toIsLocalInstall) {
            $this->components->twoColumnDetail('<fg=gray>compared against</>', '<fg=gray>your installed tree</>');
        }

        foreach ($delta->notes as $note) {
            $this->components->warn($note);
        }

        $this->output->newLine();

        if ($delta->isEmpty()) {
            $this->components->info(sprintf('No files differ between [%s] and [%s].', $delta->from, $delta->to));

            return;
        }

        $this->buckets($delta);

        $this->renderVerdict($delta, $this->output->isVerbose());
    }

    public function buckets(Delta $delta): void
    {
        $verbose = $this->output->isVerbose();
        $budget = ($verbose ? PatchExtent::Full : $this->extent)->lines();
        $hiddenLines = 0;

        foreach (BucketType::inReviewOrder() as $bucket) {
            $changes = $delta->inBucket($bucket);

            if ($changes === []) {
                continue;
            }

            $this->output->writeln($this->gutter->line(sprintf(
                '<options=bold>%s</> <fg=gray>(%d)</>%s',
                $bucket->label(),
                count($changes),
                $bucket === BucketType::Opaque ? '  <fg=red>cannot be reviewed — trust and provenance only</>' : '',
            )));

            $shown = $verbose ? $changes : array_slice($changes, 0, self::MAX_PATHS);

            foreach ($shown as $change) {
                $this->renderChange($delta, $change);

                if ($bucket === BucketType::Opaque) {
                    continue;
                }

                $lines = $this->patchLines($change);
                $written = $this->writePatch(array_slice($lines, 0, $budget));

                $budget -= $written;
                $hiddenLines += count($lines) - $written;
            }

            $hidden = count($changes) - count($shown);

            if ($hidden > 0) {
                $this->output->writeln($this->gutter->line(sprintf(
                    '  <fg=gray>… and %d more, with [%s]</>',
                    $hidden,
                    $this->invitation->command,
                )));
            }

            $this->output->writeln($this->gutter->blank());
        }

        if ($hiddenLines > 0) {
            $this->output->writeln($this->gutter->line(sprintf(
                '  <fg=gray>… and %d more lines, with [vet %s]</>',
                $hiddenLines,
                OutputFormatter::escape($delta->package),
            )));
            $this->output->writeln($this->gutter->blank());
        }
    }

    private function renderChange(Delta $delta, Change $change): void
    {
        $color = match ($change->status->value) {
            'added' => 'green',
            'removed' => 'red',
            default => 'yellow',
        };

        $annotation = $change->annotation($delta->manifestChange);

        $this->output->writeln($this->gutter->line(sprintf(
            '  <fg=%s>%s</> %s%s',
            $color,
            $change->status->symbol(),
            OutputFormatter::escape($change->path),
            $annotation === null ? '' : sprintf('  <fg=gray>%s</>', OutputFormatter::escape($annotation)),
        )));

        if ($change->bucket === BucketType::InstallManifest && $delta->manifestChange instanceof ManifestChange) {
            foreach ($delta->manifestChange->changedKeys() as $key) {
                $this->output->writeln($this->gutter->line(sprintf(
                    '      <fg=gray>%s:</> %s',
                    OutputFormatter::escape($key),
                    OutputFormatter::escape($delta->manifestChange->render($key)),
                )));
            }
        }
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function writePatch(array $lines): int
    {
        foreach ($lines as $line) {
            $this->output->writeln($this->gutter->line('    '.$line));
        }

        if ($lines !== []) {
            $this->output->writeln($this->gutter->blank());
        }

        return count($lines);
    }

    /**
     * @return array<int, string>
     */
    private function patchLines(Change $change): array
    {
        $old = $change->oldFile === null ? '' : $this->read($change->oldFile);
        $new = $change->newFile === null ? '' : $this->read($change->newFile);

        if ($old === false || $new === false) {
            return ['<fg=gray>vet cannot read this file, so its bytes are not shown</>'];
        }

        if ($this->holdsNoSource($old) || $this->holdsNoSource($new)) {
            return ['<fg=gray>this file holds no readable source, so its bytes are not shown</>'];
        }

        $diff = BuildUnifiedDiff::handle($old, $new, 'a/'.$change->path, 'b/'.$change->path, 3);

        if ($diff === '') {
            return [];
        }

        return array_map(static fn (string $line): string => match (true) {
            str_starts_with($line, '+') => sprintf('<fg=green>%s</>', OutputFormatter::escape($line)),
            str_starts_with($line, '-') => sprintf('<fg=red>%s</>', OutputFormatter::escape($line)),
            str_starts_with($line, '@@') => sprintf('<fg=cyan>%s</>', OutputFormatter::escape($line)),
            default => sprintf('<fg=gray>%s</>', OutputFormatter::escape($line)),
        }, array_slice(explode("\n", rtrim($diff, "\n")), 2));
    }

    private function renderVerdict(Delta $delta, bool $verbose): void
    {
        $blockers = $delta->reviewBlockers();

        if ($blockers !== []) {
            foreach ($blockers as $blocker) {
                $this->components->warn($blocker);
            }

            return;
        }

        if ($delta->isInertOnly()) {
            $this->components->info('No autoload rule, no bin entry and no script of this package points at the files that changed.');

            return;
        }

        $hidden = $this->hiddenCount($delta, $verbose);

        if ($hidden > 0) {
            $this->components->info(sprintf(
                '[%d] change(s) are not shown. Read them with [%s].',
                $hidden,
                $this->invitation->command,
            ));
        }
    }

    private function hiddenCount(Delta $delta, bool $verbose): int
    {
        if ($verbose) {
            return 0;
        }

        $hidden = 0;

        foreach (BucketType::inReviewOrder() as $bucket) {
            $hidden += max(0, count($delta->inBucket($bucket)) - self::MAX_PATHS);
        }

        return $hidden;
    }

    private function read(string $file): string|false
    {
        return @file_get_contents($file);
    }

    private function holdsNoSource(string $contents): bool
    {
        return str_contains($contents, "\0");
    }
}
