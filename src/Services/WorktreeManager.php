<?php

namespace Warden\Services;

use Illuminate\Support\Facades\Process;

class WorktreeManager
{
    public function __construct(
        protected BranchManager $branchManager
    ) {}

    /**
     * Ensure a worktree exists for the given branch.
     */
    public function ensureWorktree(string $branch): string
    {
        $worktreePath = $this->branchManager->getWorktreePath($branch);

        if ($this->worktreeExists($branch)) {
            // Update the worktree to the latest state
            $this->updateWorktree($branch);

            return $worktreePath;
        }

        $this->createWorktree($branch);

        return $worktreePath;
    }

    /**
     * Check if a worktree exists for the given branch.
     */
    public function worktreeExists(string $branch): bool
    {
        $worktreePath = $this->branchManager->getWorktreePath($branch);

        return is_dir($worktreePath) && is_dir($worktreePath.'/.git');
    }

    /**
     * Create a new worktree for the given branch.
     */
    public function createWorktree(string $branch): void
    {
        $worktreePath = $this->branchManager->getWorktreePath($branch);

        // Ensure parent directory exists
        $this->branchManager->ensureDirectory(dirname($worktreePath));

        // Check if the branch exists locally or remotely
        $branchExists = $this->branchExists($branch);

        if ($branchExists) {
            // Create worktree for existing branch
            $result = Process::run("git worktree add \"{$worktreePath}\" \"{$branch}\"");
        } else {
            // Create worktree with new branch from current HEAD
            $result = Process::run("git worktree add -b \"{$branch}\" \"{$worktreePath}\"");
        }

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to create worktree: '.$result->errorOutput());
        }
    }

    /**
     * Update an existing worktree to the latest state.
     */
    public function updateWorktree(string $branch): void
    {
        $worktreePath = $this->branchManager->getWorktreePath($branch);

        // Fetch latest changes
        $result = Process::path($worktreePath)->run('git fetch origin');

        if (! $result->successful()) {
            // Fetch might fail if offline, continue anyway
        }

        // Reset to the remote branch if it exists
        $remoteRef = "origin/{$branch}";
        $hasRemote = Process::path($worktreePath)
            ->run("git rev-parse --verify {$remoteRef}")
            ->successful();

        if ($hasRemote) {
            Process::path($worktreePath)->run("git reset --hard {$remoteRef}");
        } else {
            // Just ensure we're on the right branch
            Process::path($worktreePath)->run("git checkout {$branch}");
        }

        // Clean untracked files
        Process::path($worktreePath)->run('git clean -fd');
    }

    /**
     * Remove a worktree for the given branch.
     */
    public function removeWorktree(string $branch): void
    {
        $worktreePath = $this->branchManager->getWorktreePath($branch);

        if (! $this->worktreeExists($branch)) {
            return;
        }

        $result = Process::run("git worktree remove \"{$worktreePath}\" --force");

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to remove worktree: '.$result->errorOutput());
        }
    }

    /**
     * List all existing worktrees.
     */
    public function listWorktrees(): array
    {
        $result = Process::run('git worktree list --porcelain');

        if (! $result->successful()) {
            return [];
        }

        $worktrees = [];
        $current = [];

        foreach (explode("\n", $result->output()) as $line) {
            $line = trim($line);

            if (empty($line)) {
                if (! empty($current)) {
                    $worktrees[] = $current;
                    $current = [];
                }

                continue;
            }

            if (str_starts_with($line, 'worktree ')) {
                $current['path'] = substr($line, 9);
            } elseif (str_starts_with($line, 'branch ')) {
                $current['branch'] = substr($line, 7);
            } elseif (str_starts_with($line, 'HEAD ')) {
                $current['head'] = substr($line, 5);
            }
        }

        if (! empty($current)) {
            $worktrees[] = $current;
        }

        return $worktrees;
    }

    /**
     * Check if a branch exists.
     */
    protected function branchExists(string $branch): bool
    {
        // Check local branch
        $localResult = Process::run("git rev-parse --verify refs/heads/{$branch}");
        if ($localResult->successful()) {
            return true;
        }

        // Check remote branch
        $remoteResult = Process::run("git rev-parse --verify refs/remotes/origin/{$branch}");

        return $remoteResult->successful();
    }
}
