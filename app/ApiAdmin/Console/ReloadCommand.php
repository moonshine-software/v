<?php

declare(strict_types=1);

namespace App\ApiAdmin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Override;
use Symfony\Component\Process\Process;

/**
 * Hot-reload command: dumps autoload and clears caches after MCP code generation.
 *
 * Run after new resource PHP files are generated to make them available
 * without restarting the container.
 */
class ReloadCommand extends Command
{
    #[Override]
    protected $signature = 'api-admin:reload
                            {--autoload : Run composer dump-autoload (slow — only needed for production classmap rebuild)}
                            {--no-cache : Skip cache clearing}';

    #[Override]
    protected $description = 'Reload after MCP code generation: clear caches (composer dump-autoload is optional via --autoload).';

    public function handle(): int
    {
        Log::info('api-admin:reload — starting hot-reload');

        $this->info('🔄 Running hot-reload...');

        $success = true;

        // composer dump-autoload blocks the MCP stdio loop for ~6s which
        // makes Claude Code miss pings and mark the server as failed. New
        // classes under App\MoonShine\... resolve via PSR-4 without a
        // classmap, so we skip the dump by default and only offer it
        // behind an explicit --autoload flag (useful for the monorepo
        // build-cache scenario, never for MCP-driven generation).
        if ($this->option('autoload') && ! $this->runComposerDumpAutoload()) {
            $success = false;
        }

        // Clear caches (fast — millisecond-range).
        if (! $this->option('no-cache')) {
            $success = $this->clearCaches() && $success;
        }

        if ($success) {
            Log::info('api-admin:reload — completed successfully');
            $this->info('✅ Hot-reload complete');

            return self::SUCCESS;
        }

        Log::warning('api-admin:reload — completed with errors');
        $this->warn('⚠️ Hot-reload completed with some errors (check logs)');

        return self::FAILURE;
    }

    /**
     * Run composer dump-autoload to register newly generated classes.
     */
    protected function runComposerDumpAutoload(): bool
    {
        $this->components->task('composer dump-autoload', function (): bool {
            Log::debug('api-admin:reload — running composer dump-autoload');

            $process = new Process(['composer', 'dump-autoload', '--optimize']);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::error('api-admin:reload — composer dump-autoload failed', [
                    'output' => $process->getErrorOutput(),
                ]);

                return false;
            }

            Log::debug('api-admin:reload — composer dump-autoload succeeded');

            return true;
        });

        return true;
    }

    /**
     * Clear Laravel caches that might hold stale class/config references.
     */
    protected function clearCaches(): bool
    {
        $caches = [
            'config:clear' => 'Clearing config cache',
            'route:clear' => 'Clearing route cache',
            'view:clear' => 'Clearing view cache',
        ];

        foreach ($caches as $command => $description) {
            $this->components->task($description, function () use ($command): true {
                Log::debug("api-admin:reload — running {$command}");

                $this->callSilently($command);

                return true;
            });
        }

        return true;
    }
}
