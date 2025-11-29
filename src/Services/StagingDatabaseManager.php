<?php

namespace Warden\Services;

use Illuminate\Support\Facades\Process;

class StagingDatabaseManager
{
    public function __construct(
        protected BranchManager $branchManager
    ) {}

    /**
     * Create or reset the staging database for a branch.
     */
    public function createStagingDatabase(string $branch): string
    {
        $databasePath = $this->branchManager->getStagingDatabasePath($branch);
        $databaseDir = dirname($databasePath);

        // Ensure directory exists
        $this->branchManager->ensureDirectory($databaseDir);

        // Remove existing database if it exists
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }

        // Create empty SQLite database
        touch($databasePath);

        return $databasePath;
    }

    /**
     * Get the staging database path for a branch.
     */
    public function getDatabasePath(string $branch): string
    {
        return $this->branchManager->getStagingDatabasePath($branch);
    }

    /**
     * Check if a staging database exists for a branch.
     */
    public function databaseExists(string $branch): bool
    {
        return file_exists($this->branchManager->getStagingDatabasePath($branch));
    }

    /**
     * Run migrations on the staging database.
     */
    public function runMigrations(string $branch): void
    {
        $databasePath = $this->branchManager->getStagingDatabasePath($branch);

        // Set the database path in environment and run migrations
        $result = Process::env([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath,
        ])->run('php artisan migrate --force');

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to run migrations: '.$result->errorOutput());
        }
    }

    /**
     * Run seeders on the staging database.
     */
    public function runSeeders(string $branch, ?string $seeder = null): void
    {
        $databasePath = $this->branchManager->getStagingDatabasePath($branch);

        $command = 'php artisan db:seed --force';
        if ($seeder) {
            $command .= " --class={$seeder}";
        }

        $result = Process::env([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath,
        ])->run($command);

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to run seeders: '.$result->errorOutput());
        }
    }

    /**
     * Get database connection config for a branch.
     */
    public function getConnectionConfig(string $branch): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $this->branchManager->getStagingDatabasePath($branch),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
    }

    /**
     * Remove the staging database for a branch.
     */
    public function removeStagingDatabase(string $branch): void
    {
        $databasePath = $this->branchManager->getStagingDatabasePath($branch);

        if (file_exists($databasePath)) {
            unlink($databasePath);
        }
    }

    /**
     * Get the size of the staging database in bytes.
     */
    public function getDatabaseSize(string $branch): int
    {
        $databasePath = $this->branchManager->getStagingDatabasePath($branch);

        if (! file_exists($databasePath)) {
            return 0;
        }

        return filesize($databasePath);
    }

    /**
     * List all branches with staging databases.
     */
    public function listBranchesWithDatabases(): array
    {
        $branches = [];
        $wardenRoot = $this->branchManager->getWardenRoot();

        if (! is_dir($wardenRoot)) {
            return $branches;
        }

        $dirs = array_filter(glob($wardenRoot.'/*'), 'is_dir');

        foreach ($dirs as $dir) {
            $branchName = basename($dir);
            if ($branchName === 'worktrees') {
                continue;
            }

            $dbPath = $dir.'/database/staging.sqlite';
            if (file_exists($dbPath)) {
                $branches[] = [
                    'branch' => $branchName,
                    'database_path' => $dbPath,
                    'size' => filesize($dbPath),
                ];
            }
        }

        return $branches;
    }
}
