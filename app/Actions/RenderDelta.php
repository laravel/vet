<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BucketType;
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
    ) {
        $this->components = new ControlSafeComponents($output);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Delta $delta): array
    {
        return [
            'from' => $delta->from,
            'to' => $delta->to,
            'from_hash' => (string) $delta->fromHash,
            'to_hash' => (string) $delta->toHash,
            'source' => $delta->source->value,
            'compared_against_install' => $delta->toIsLocalInstall,
            'notes' => $delta->notes,
            'counts' => $delta->counts(),
            'inert_only' => $delta->isInertOnly(),
            'needs_no_review' => $delta->needsNoReview(),
            'review_blockers' => $delta->reviewBlockers(),
            'manifest_keys' => $delta->manifestChange?->changedKeys() ?? [],
            'changes' => array_map(static fn (Change $change): array => [
                'path' => $change->path,
                'status' => $change->status->value,
                'bucket' => $change->bucket->value,
            ], $delta->changes()),
        ];
    }

    public function report(Delta $delta, ?string $only = null): void
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

        $this->buckets($delta, $only);

        $this->renderVerdict($delta, $this->output->isVerbose());
    }

    public function buckets(Delta $delta, ?string $only = null): void
    {
        $verbose = $this->output->isVerbose();

        foreach (BucketType::inReviewOrder() as $bucket) {
            if ($only !== null && $only !== $bucket->value) {
                continue;
            }

            $changes = $delta->inBucket($bucket);

            if ($changes === []) {
                continue;
            }

            $this->output->writeln(sprintf(
                '  <options=bold>%s</> <fg=gray>(%d)</>%s',
                $bucket->label(),
                count($changes),
                $bucket === BucketType::Opaque ? '  <fg=red>cannot be reviewed — trust and provenance only</>' : '',
            ));

            $shown = $verbose ? $changes : array_slice($changes, 0, self::MAX_PATHS);

            foreach ($shown as $change) {
                $this->renderChange($delta, $change);

                if ($bucket !== BucketType::Opaque) {
                    $this->renderPatch($change);
                }
            }

            $hidden = count($changes) - count($shown);

            if ($hidden > 0) {
                $this->output->writeln(sprintf(
                    '    <fg=gray>… and %d more, with [%s]</>',
                    $hidden,
                    $this->invitation->command,
                ));
            }

            $this->output->newLine();
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

        $this->output->writeln(sprintf(
            '    <fg=%s>%s</> %s%s',
            $color,
            $change->status->symbol(),
            $this->escape($change->path),
            $annotation === null ? '' : sprintf('  <fg=gray>%s</>', $this->escape($annotation)),
        ));

        if ($change->bucket === BucketType::InstallManifest && $delta->manifestChange instanceof ManifestChange) {
            foreach ($delta->manifestChange->changedKeys() as $key) {
                $this->output->writeln(sprintf(
                    '        <fg=gray>%s:</> %s',
                    $this->escape($key),
                    $this->escape($delta->manifestChange->render($key)),
                ));
            }
        }
    }

    private function renderPatch(Change $change): void
    {
        $old = $change->oldFile === null ? null : $this->read($change->oldFile);
        $new = $change->newFile === null ? null : $this->read($change->newFile);

        if ($this->holdsNoSource($old) || $this->holdsNoSource($new)) {
            $this->output->writeln('      <fg=gray>this file holds no readable source, so its bytes are not shown</>');
            $this->output->newLine();

            return;
        }

        $diff = BuildUnifiedDiff::handle($old, $new, 'a/'.$change->path, 'b/'.$change->path);

        if ($diff === '') {
            return;
        }

        foreach (array_slice(explode("\n", rtrim($diff, "\n")), 2) as $line) {
            $this->output->writeln('      '.match (true) {
                str_starts_with($line, '+') => sprintf('<fg=green>%s</>', $this->escape($line)),
                str_starts_with($line, '-') => sprintf('<fg=red>%s</>', $this->escape($line)),
                str_starts_with($line, '@@') => sprintf('<fg=cyan>%s</>', $this->escape($line)),
                default => sprintf('<fg=gray>%s</>', $this->escape($line)),
            });
        }

        $this->output->newLine();
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

    private function read(string $file): ?string
    {
        $contents = @file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    private function holdsNoSource(?string $contents): bool
    {
        return $contents !== null && str_contains($contents, "\0");
    }

    private function escape(string $line): string
    {
        return OutputFormatter::escapeTrailingBackslash(
            str_replace(['<', '>'], ['\\<', '\\>'], $line),
        );
    }
}
