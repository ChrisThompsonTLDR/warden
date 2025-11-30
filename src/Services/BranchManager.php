<?php

namespace Warden\Services;

use Illuminate\Support\Facades\Process;

class BranchManager
{
    public function __construct(
        protected string $wardenRoot,
        protected string $repoName
    ) {}

    /**
     * Get the current Git branch name.
     */
    public function getCurrentBranch(): string
    {
        $result = Process::run('git rev-parse --abbrev-ref HEAD');

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to detect current Git branch: '.$result->errorOutput());
        }

        return trim($result->output());
    }

    /**
     * Get the Warden root directory.
     */
    public function getWardenRoot(): string
    {
        return $this->wardenRoot;
    }

    /**
     * Get the repository name.
     */
    public function getRepoName(): string
    {
        return $this->repoName;
    }

    /**
     * Get the Deepwiki project ID for a branch.
     */
    public function getProjectId(string $branch): string
    {
        // Sanitize branch name for use as project ID
        $sanitizedBranch = preg_replace('/[^a-zA-Z0-9_-]/', '-', $branch);

        return $this->repoName.'-'.$sanitizedBranch;
    }

    /**
     * Get the repository root path (current working directory).
     */
    public function getRepoPath(): string
    {
        return base_path();
    }

    /**
     * Get the database directory for a branch.
     */
    public function getDatabasePath(string $branch): string
    {
        $sanitizedBranch = $this->sanitizeBranchName($branch);

        return $this->wardenRoot.'/'.$sanitizedBranch.'/database';
    }

    /**
     * Get the SQLite staging database path for a branch.
     */
    public function getStagingDatabasePath(string $branch): string
    {
        return $this->getDatabasePath($branch).'/staging.sqlite';
    }

    /**
     * Get the index directory for a branch.
     */
    public function getIndexPath(string $branch): string
    {
        $sanitizedBranch = $this->sanitizeBranchName($branch);

        return $this->wardenRoot.'/'.$sanitizedBranch.'/index';
    }

    /**
     * Get the history directory for a branch.
     */
    public function getHistoryPath(string $branch): string
    {
        $sanitizedBranch = $this->sanitizeBranchName($branch);

        return $this->wardenRoot.'/'.$sanitizedBranch.'/history';
    }

    /**
     * Get the worktree path for a branch.
     */
    public function getWorktreePath(string $branch): string
    {
        $sanitizedBranch = $this->sanitizeBranchName($branch);

        return $this->wardenRoot.'/worktrees/'.$sanitizedBranch;
    }

    /**
     * Sanitize a branch name for use in file paths.
     */
    public function sanitizeBranchName(string $branch): string
    {
        $result = preg_replace('/[^a-zA-Z0-9_-]/', '-', $branch);

        return $result ?? $branch;
    }

    /**
     * Ensure a directory exists.
     */
    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Check if a branch has been indexed (has index, database, or history).
     */
    public function isBranchIndexed(string $branch): bool
    {
        $sanitizedBranch = $this->sanitizeBranchName($branch);
        $branchDir = $this->wardenRoot.'/'.$sanitizedBranch;

        if (! is_dir($branchDir)) {
            return false;
        }

        // Check if branch has any Warden data
        $hasIndex = is_dir($branchDir.'/index');
        $hasDatabase = file_exists($branchDir.'/database/staging.sqlite');
        $hasHistory = file_exists($branchDir.'/history/commits.jsonl');

        return $hasIndex || $hasDatabase || $hasHistory;
    }

    /**
     * List all branches with Warden data (index, database, or history).
     *
     * @return array<string>
     */
    public function listBranches(): array
    {
        $branches = [];

        if (! is_dir($this->wardenRoot)) {
            return $branches;
        }

        $globResult = glob($this->wardenRoot.'/*');
        if ($globResult === false) {
            return $branches;
        }

        $dirs = array_filter($globResult, 'is_dir');

        foreach ($dirs as $dir) {
            $branchName = basename($dir);

            // Skip if it's not a branch directory (has index, database, or history)
            $hasIndex = is_dir($dir.'/index');
            $hasDatabase = is_dir($dir.'/database');
            $hasHistory = is_dir($dir.'/history');

            if ($hasIndex || $hasDatabase || $hasHistory) {
                $branches[] = $branchName;
            }
        }

        return $branches;
    }
}
