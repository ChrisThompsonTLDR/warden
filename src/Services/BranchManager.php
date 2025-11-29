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
     * Get the worktree path for a branch.
     */
    public function getWorktreePath(string $branch): string
    {
        $sanitizedBranch = $this->sanitizeBranchName($branch);

        return $this->wardenRoot.'/worktrees/'.$sanitizedBranch;
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
     * Sanitize a branch name for use in file paths.
     */
    public function sanitizeBranchName(string $branch): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '-', $branch);
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
     * List all branches with Warden data.
     */
    public function listBranches(): array
    {
        $branches = [];
        $worktreesPath = $this->wardenRoot.'/worktrees';

        if (is_dir($worktreesPath)) {
            $dirs = array_filter(glob($worktreesPath.'/*'), 'is_dir');
            foreach ($dirs as $dir) {
                $branches[] = basename($dir);
            }
        }

        return $branches;
    }
}
