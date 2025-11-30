<?php

namespace Warden\Services;

use Illuminate\Support\Facades\Process;

class WorktreeManager
{
    public function __construct(
        protected BranchManager $branchManager
    ) {}

    /**
     * Get the worktree path for a branch.
     */
    public function getWorktreePath(string $branch): string
    {
        return $this->branchManager->getWorktreePath($branch);
    }

    /**
     * Check if a worktree exists for a branch.
     */
    public function worktreeExists(string $branch): bool
    {
        $path = $this->getWorktreePath($branch);

        return is_dir($path);
    }

    /**
     * Create a worktree for a branch.
     */
    public function createWorktree(string $branch): void
    {
        $path = $this->getWorktreePath($branch);
        $sanitizedBranch = $this->branchManager->sanitizeBranchName($branch);

        // Ensure parent directory exists
        $parentDir = dirname($path);
        if (! is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        // Create worktree if it doesn't exist
        if (! $this->worktreeExists($branch)) {
            $result = Process::run([
                'git',
                'worktree',
                'add',
                $path,
                $branch,
            ]);

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to create worktree: '.$result->errorOutput());
            }
        }
    }

    /**
     * Remove a worktree for a branch.
     */
    public function removeWorktree(string $branch): void
    {
        $path = $this->getWorktreePath($branch);

        if ($this->worktreeExists($branch)) {
            $result = Process::run([
                'git',
                'worktree',
                'remove',
                $path,
            ]);

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to remove worktree: '.$result->errorOutput());
            }
        }
    }
}
