<?php

namespace Warden\Mcp\Tools;

use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;

class DeepwikiAskTool
{
    public function __construct(
        protected BranchManager $branchManager,
        protected DeepwikiClient $deepwikiClient
    ) {}

    /**
     * @return array{success: bool, error?: string, repo?: string, branch?: string, project_id?: string, question?: string, answer?: string, sources?: array<int, mixed>}
     */
    public function __invoke(
        string $repo,
        string $branch,
        string $question,
        ?string $model = null
    ): array {
        // Validate the branch has been indexed
        if (! $this->branchManager->isBranchIndexed($branch)) {
            return [
                'success' => false,
                'error' => "Branch '{$branch}' has not been indexed. Switch to that branch and run 'php artisan warden:reindex' first.",
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
