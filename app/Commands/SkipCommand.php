<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\VetException;
use App\Support\ControlSafe;
use App\ValueObjects\Project;
use App\ValueObjects\SkippedPackages;
use App\ValueObjects\TrustFile;
use LaravelZero\Framework\Commands\Command;

final class SkipCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'skip
        {packages* : Package names or patterns, such as laravel/*}
        {--path= : The project directory}';

    /**
     * @var string
     */
    protected $description = 'Record packages that vet does not compare, and mark that choice as unsafe';

    public function handle(): int
    {
        $patterns = $this->patterns();

        if ($patterns === []) {
            $this->components->error('The [skip] command needs one package or one pattern, such as [laravel/*].');

            return self::FAILURE;
        }

        $path = $this->option('path');
        assert($path === null || is_string($path));

        try {
            TrustFile::forProject(Project::locate($path ?? (string) getcwd()))->addSkips($patterns);
        } catch (VetException $vetException) {
            $this->components->error($vetException->getMessage());

            return self::FAILURE;
        }

        $this->components->warn(sprintf(
            'Skipped [%s] as unsafe, and wrote %s to [%s] in [vet.json].',
            implode('], [', array_map(ControlSafe::text(...), $patterns)),
            count($patterns) === 1 ? 'it' : 'them',
            SkippedPackages::SECTION,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        $patterns = $this->argument('packages');

        return array_values(array_filter(
            is_array($patterns) ? $patterns : [],
            static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));
    }
}
