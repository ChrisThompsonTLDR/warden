<?php

namespace Warden\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DeepwikiClient
{
    protected PendingRequest $http;

    public function __construct(
        protected string $serverUrl,
        protected ?string $openaiApiKey = null
    ) {
        $this->http = Http::baseUrl($this->serverUrl)
            ->timeout(120)
            ->acceptJson();
    }

    /**
     * Ask a question about a project.
     */
    public function ask(string $projectId, string $question, array $options = []): array
    {
        $response = $this->http->post('/api/ask', [
            'project_id' => $projectId,
            'question' => $question,
            'model' => $options['model'] ?? 'gpt-4o-mini',
            'stream' => $options['stream'] ?? false,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Deepwiki ask failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Trigger a reindex of a project.
     */
    public function reindex(string $projectId, string $worktreePath, array $options = []): array
    {
        $response = $this->http->post('/api/index', [
            'project_id' => $projectId,
            'repo_path' => $worktreePath,
            'include_patterns' => $options['include'] ?? [],
            'exclude_patterns' => $options['exclude'] ?? [],
            'force' => $options['force'] ?? false,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Deepwiki reindex failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * List all indexed projects.
     */
    public function listProjects(): array
    {
        $response = $this->http->get('/api/projects');

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to list projects: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get project status.
     */
    public function getProjectStatus(string $projectId): array
    {
        $response = $this->http->get("/api/projects/{$projectId}");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to get project status: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Delete a project index.
     */
    public function deleteProject(string $projectId): void
    {
        $response = $this->http->delete("/api/projects/{$projectId}");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to delete project: '.$response->body());
        }
    }

    /**
     * Check if the Deepwiki server is healthy.
     */
    public function health(): bool
    {
        try {
            $response = $this->http->timeout(5)->get('/health');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get server info.
     */
    public function getServerInfo(): array
    {
        $response = $this->http->get('/api/info');

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }
}
