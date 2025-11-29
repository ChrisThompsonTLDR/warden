<?php

namespace Warden\Services;

use Illuminate\Support\Facades\Process;

class GitHistoryService
{
    public function __construct(
        protected BranchManager $branchManager,
        protected WorktreeManager $worktreeManager,
        protected array $config = []
    ) {}

    /**
     * Extract and store commit history for a branch.
     */
    public function extractHistory(string $branch): string
    {
        $worktreePath = $this->branchManager->getWorktreePath($branch);
        $historyPath = $this->branchManager->getHistoryPath($branch);

        // Ensure the worktree exists
        if (! $this->worktreeManager->worktreeExists($branch)) {
            throw new \RuntimeException("Worktree for branch '{$branch}' does not exist");
        }

        // Ensure history directory exists
        $this->branchManager->ensureDirectory($historyPath);

        // Extract commits
        $commits = $this->getCommits($worktreePath);

        // Write commits to JSONL file
        $jsonlPath = $historyPath.'/commits.jsonl';
        $this->writeCommitsToJsonl($commits, $jsonlPath);

        // Write commits to markdown for easier reading
        $mdPath = $historyPath.'/commits.md';
        $this->writeCommitsToMarkdown($commits, $mdPath);

        return $historyPath;
    }

    /**
     * Get commits from a worktree.
     */
    protected function getCommits(string $worktreePath): array
    {
        $maxCommits = $this->config['max_commits'] ?? 1000;
        $includeDiffs = $this->config['include_diffs'] ?? false;
        $maxDiffSize = $this->config['max_diff_size'] ?? 10000;

        // Get commit log with structured output
        $format = '%H|%an|%ae|%aI|%s|%b';
        $result = Process::path($worktreePath)
            ->run("git log --format=\"{$format}\" -n {$maxCommits} --no-merges");

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to get git log: '.$result->errorOutput());
        }

        $commits = [];
        $lines = explode("\n", trim($result->output()));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $parts = explode('|', $line, 6);
            if (count($parts) < 5) {
                continue;
            }

            $commit = [
                'hash' => $parts[0],
                'author' => $parts[1],
                'email' => $parts[2],
                'date' => $parts[3],
                'subject' => $parts[4],
                'body' => $parts[5] ?? '',
            ];

            if ($includeDiffs) {
                $commit['diff'] = $this->getCommitDiff($worktreePath, $commit['hash'], $maxDiffSize);
            }

            // Get files changed in this commit
            $commit['files'] = $this->getCommitFiles($worktreePath, $commit['hash']);

            $commits[] = $commit;
        }

        return $commits;
    }

    /**
     * Get the diff for a commit.
     */
    protected function getCommitDiff(string $worktreePath, string $hash, int $maxSize): ?string
    {
        $result = Process::path($worktreePath)
            ->run("git show --format='' --stat --patch {$hash}");

        if (! $result->successful()) {
            return null;
        }

        $diff = $result->output();

        if (strlen($diff) > $maxSize) {
            return substr($diff, 0, $maxSize).'... [truncated]';
        }

        return $diff;
    }

    /**
     * Get files changed in a commit.
     */
    protected function getCommitFiles(string $worktreePath, string $hash): array
    {
        $result = Process::path($worktreePath)
            ->run("git show --format='' --name-status {$hash}");

        if (! $result->successful()) {
            return [];
        }

        $files = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            if (empty($line)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) === 2) {
                $files[] = [
                    'status' => $parts[0],
                    'path' => $parts[1],
                ];
            }
        }

        return $files;
    }

    /**
     * Write commits to JSONL file.
     */
    protected function writeCommitsToJsonl(array $commits, string $path): void
    {
        $handle = fopen($path, 'w');

        foreach ($commits as $commit) {
            fwrite($handle, json_encode($commit)."\n");
        }

        fclose($handle);
    }

    /**
     * Write commits to Markdown file.
     */
    protected function writeCommitsToMarkdown(array $commits, string $path): void
    {
        $handle = fopen($path, 'w');

        fwrite($handle, "# Git Commit History\n\n");
        fwrite($handle, "Generated: ".date('Y-m-d H:i:s')."\n\n");
        fwrite($handle, "Total commits: ".count($commits)."\n\n");
        fwrite($handle, "---\n\n");

        foreach ($commits as $commit) {
            fwrite($handle, "## {$commit['subject']}\n\n");
            fwrite($handle, "- **Hash:** `{$commit['hash']}`\n");
            fwrite($handle, "- **Author:** {$commit['author']} <{$commit['email']}>\n");
            fwrite($handle, "- **Date:** {$commit['date']}\n");

            if (! empty($commit['body'])) {
                fwrite($handle, "\n{$commit['body']}\n");
            }

            if (! empty($commit['files'])) {
                fwrite($handle, "\n**Files changed:**\n");
                foreach ($commit['files'] as $file) {
                    fwrite($handle, "- [{$file['status']}] {$file['path']}\n");
                }
            }

            fwrite($handle, "\n---\n\n");
        }

        fclose($handle);
    }

    /**
     * Search commit history for a branch.
     */
    public function searchHistory(string $branch, string $query, array $filters = []): array
    {
        $historyPath = $this->branchManager->getHistoryPath($branch);
        $jsonlPath = $historyPath.'/commits.jsonl';

        if (! file_exists($jsonlPath)) {
            return [];
        }

        $results = [];
        $handle = fopen($jsonlPath, 'r');

        while (($line = fgets($handle)) !== false) {
            $commit = json_decode(trim($line), true);

            if (! $commit) {
                continue;
            }

            // Apply filters
            if (isset($filters['author']) && stripos($commit['author'], $filters['author']) === false) {
                continue;
            }

            if (isset($filters['since']) && strtotime($commit['date']) < strtotime($filters['since'])) {
                continue;
            }

            if (isset($filters['until']) && strtotime($commit['date']) > strtotime($filters['until'])) {
                continue;
            }

            if (isset($filters['path'])) {
                $pathMatch = false;
                foreach ($commit['files'] ?? [] as $file) {
                    if (stripos($file['path'], $filters['path']) !== false) {
                        $pathMatch = true;
                        break;
                    }
                }
                if (! $pathMatch) {
                    continue;
                }
            }

            // Search in subject and body
            $searchText = $commit['subject'].' '.$commit['body'];
            if (stripos($searchText, $query) !== false) {
                $results[] = $commit;
            }
        }

        fclose($handle);

        return $results;
    }

    /**
     * Get history statistics for a branch.
     */
    public function getHistoryStats(string $branch): array
    {
        $historyPath = $this->branchManager->getHistoryPath($branch);
        $jsonlPath = $historyPath.'/commits.jsonl';

        if (! file_exists($jsonlPath)) {
            return [
                'total_commits' => 0,
                'authors' => [],
                'date_range' => null,
            ];
        }

        $authors = [];
        $dates = [];
        $totalCommits = 0;

        $handle = fopen($jsonlPath, 'r');

        while (($line = fgets($handle)) !== false) {
            $commit = json_decode(trim($line), true);

            if (! $commit) {
                continue;
            }

            $totalCommits++;
            $authors[$commit['author']] = ($authors[$commit['author']] ?? 0) + 1;
            $dates[] = strtotime($commit['date']);
        }

        fclose($handle);

        arsort($authors);

        return [
            'total_commits' => $totalCommits,
            'authors' => $authors,
            'date_range' => [
                'earliest' => $dates ? date('Y-m-d', min($dates)) : null,
                'latest' => $dates ? date('Y-m-d', max($dates)) : null,
            ],
        ];
    }
}
