<?php

namespace Warden\Mcp\Tools;

use Laravel\Mcp\Attributes\McpTool;
use Warden\Services\BranchManager;
use Warden\Services\GitHistoryService;
use Warden\Services\WorktreeManager;

#[McpTool(
    name: 'deepwiki.ask_history',
    description: 'Ask questions about Git commit history for a specific repository and branch'
)]
class DeepwikiAskHistoryTool
{
    public function __construct(
        protected BranchManager $branchManager,
        protected WorktreeManager $worktreeManager,
        protected GitHistoryService $gitHistoryService
    ) {}

    public function __invoke(
        string $repo,
        string $branch,
        string $question,
        ?string $author = null,
        ?string $since = null,
        ?string $until = null,
        ?string $path = null
    ): array {
        // Validate the branch exists
        if (! $this->worktreeManager->worktreeExists($branch)) {
            return [
                'success' => false,
                'error' => "Branch '{$branch}' has not been indexed. Run 'php artisan deepwiki:reindex {$branch}' first.",
            ];
        }

        // Build filters
        $filters = [];
        if ($author) {
            $filters['author'] = $author;
        }
        if ($since) {
            $filters['since'] = $since;
        }
        if ($until) {
            $filters['until'] = $until;
        }
        if ($path) {
            $filters['path'] = $path;
        }

        try {
            // Search for relevant commits
            $commits = $this->gitHistoryService->searchHistory($branch, $question, $filters);

            // Get statistics
            $stats = $this->gitHistoryService->getHistoryStats($branch);

            // Format commits for response
            $formattedCommits = array_map(function ($commit) {
                return [
                    'hash' => $commit['hash'],
                    'author' => $commit['author'],
                    'date' => $commit['date'],
                    'subject' => $commit['subject'],
                    'files' => array_map(fn ($f) => $f['path'], $commit['files'] ?? []),
                ];
            }, array_slice($commits, 0, 20)); // Limit to 20 most relevant

            return [
                'success' => true,
                'repo' => $repo,
                'branch' => $branch,
                'question' => $question,
                'filters' => $filters,
                'total_matches' => count($commits),
                'commits' => $formattedCommits,
                'stats' => [
                    'total_commits_indexed' => $stats['total_commits'],
                    'date_range' => $stats['date_range'],
                    'top_authors' => array_slice($stats['authors'], 0, 5, true),
                ],
                'note' => 'Commit history RAG is separate from code-content RAG. Use deepwiki.ask for code questions.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repo' => [
                    'type' => 'string',
                    'description' => 'The logical repository name',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'The Git branch name (e.g., main, develop, feature/2fa)',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'The question about commit history (e.g., "When did we add 2FA?", "Which commits touched auth?")',
                ],
                'author' => [
                    'type' => 'string',
                    'description' => 'Filter by commit author name',
                ],
                'since' => [
                    'type' => 'string',
                    'description' => 'Filter commits after this date (ISO 8601 format)',
                ],
                'until' => [
                    'type' => 'string',
                    'description' => 'Filter commits before this date (ISO 8601 format)',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Filter by file path pattern',
                ],
            ],
            'required' => ['repo', 'branch', 'question'],
        ];
    }
}
