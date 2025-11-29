<?php

namespace Warden\Mcp\Tools;

use Laravel\Mcp\Attributes\McpTool;
use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;
use Warden\Services\WorktreeManager;

#[McpTool(
    name: 'deepwiki.ask',
    description: 'Ask a question about the codebase for a specific repository and branch'
)]
class DeepwikiAskTool
{
    public function __construct(
        protected BranchManager $branchManager,
        protected WorktreeManager $worktreeManager,
        protected DeepwikiClient $deepwikiClient
    ) {}

    public function __invoke(
        string $repo,
        string $branch,
        string $question,
        ?string $model = null
    ): array {
        // Validate the branch exists
        if (! $this->worktreeManager->worktreeExists($branch)) {
            return [
                'success' => false,
                'error' => "Branch '{$branch}' has not been indexed. Run 'php artisan deepwiki:reindex {$branch}' first.",
            ];
        }

        $projectId = $this->branchManager->getProjectId($branch);

        try {
            $response = $this->deepwikiClient->ask($projectId, $question, [
                'model' => $model ?? 'gpt-4o-mini',
            ]);

            return [
                'success' => true,
                'repo' => $repo,
                'branch' => $branch,
                'project_id' => $projectId,
                'question' => $question,
                'answer' => $response['answer'] ?? $response['response'] ?? '',
                'sources' => $response['sources'] ?? [],
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
                    'description' => 'The question to ask about the codebase',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'The LLM model to use (default: gpt-4o-mini)',
                ],
            ],
            'required' => ['repo', 'branch', 'question'],
        ];
    }
}
