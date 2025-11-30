<?php

namespace Warden\Mcp\Tools;

use Illuminate\Support\Facades\Artisan;
use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;

class DeepwikiReindexTool
{
    public function __construct(
        protected BranchManager $branchManager,
        protected DeepwikiClient $deepwikiClient
    ) {}

    /**
     * @return array{success: bool, error?: string, output?: string, current_branch?: string, requested_branch?: string, repo?: string, branch?: string, project_id?: string, repo_path?: string, index_path?: string, history_path?: string, message?: string}
     */
    public function __invoke(
        string $repo,
        string $branch,
        bool $force = false,
        bool $skipHistory = false
    ): array {
        // Get current branch (the branch parameter is for validation/display only)
        $currentBranch = $this->branchManager->getCurrentBranch();

        // Validate that we're on the requested branch
        if ($currentBranch !== $branch) {
            return [
                'success' => false,
                'error' => "You are currently on branch '{$currentBranch}', but requested to index '{$branch}'. Switch to the branch first: git checkout {$branch}",
                'current_branch' => $currentBranch,
                'requested_branch' => $branch,
            ];
        }

        $projectId = $this->branchManager->getProjectId($branch);

        try {
            // Run the reindex command (it will use current branch automatically)
            $exitCode = Artisan::call('warden:reindex', [
                '--force' => $force,
                '--skip-history' => $skipHistory,
            ]);

            $output = Artisan::output();

            if ($exitCode !== 0) {
                return [
                    'success' => false,
                    'error' => 'Reindex command failed',
                    'output' => $output,
                ];
            }

            // Get paths for response
            $repoPath = $this->branchManager->getRepoPath();
            $indexPath = $this->branchManager->getIndexPath($branch);
            $historyPath = $this->branchManager->getHistoryPath($branch);

            return [
                'success' => true,
                'repo' => $repo,
                'branch' => $branch,
                'project_id' => $projectId,
                'repo_path' => $repoPath,
                'index_path' => $indexPath,
                'history_path' => $historyPath,
                'message' => "Successfully reindexed {$repo}/{$branch}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
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
                    'description' => 'The Git branch name to reindex (e.g., main, develop, feature/2fa)',
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Force reindex even if index exists',
                    'default' => false,
                ],
                'skipHistory' => [
                    'type' => 'boolean',
                    'description' => 'Skip indexing commit history',
                    'default' => false,
                ],
            ],
            'required' => ['repo', 'branch'],
        ];
    }
}
