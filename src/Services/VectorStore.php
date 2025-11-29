<?php

namespace Warden\Services;

class VectorStore
{
    protected string $indexPath;

    protected array $vectors = [];

    protected array $payloads = [];

    public function __construct(string $indexPath)
    {
        $this->indexPath = $indexPath;
        $this->ensureDirectory($indexPath);
        $this->load();
    }

    /**
     * Upsert a vector with its payload.
     *
     * @param  array<float>  $vector
     */
    public function upsert(string $id, array $vector, array $payload): void
    {
        $this->vectors[$id] = $vector;
        $this->payloads[$id] = $payload;
    }

    /**
     * Delete a vector by ID.
     */
    public function delete(string $id): void
    {
        unset($this->vectors[$id], $this->payloads[$id]);
    }

    /**
     * Search for similar vectors using cosine similarity.
     *
     * @param  array<float>  $queryVector
     * @return array<array{id: string, score: float, payload: array}>
     */
    public function search(array $queryVector, int $topK = 10, array $filter = []): array
    {
        $results = [];

        foreach ($this->vectors as $id => $vector) {
            $payload = $this->payloads[$id] ?? [];

            // Apply filters
            if (! $this->matchesFilter($payload, $filter)) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $vector);
            $results[] = [
                'id' => $id,
                'score' => $score,
                'payload' => $payload,
            ];
        }

        // Sort by score descending
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $topK);
    }

    /**
     * Get a payload by ID.
     */
    public function get(string $id): ?array
    {
        return $this->payloads[$id] ?? null;
    }

    /**
     * Get all IDs in the store.
     *
     * @return array<string>
     */
    public function getAllIds(): array
    {
        return array_keys($this->vectors);
    }

    /**
     * Get the count of vectors in the store.
     */
    public function count(): int
    {
        return count($this->vectors);
    }

    /**
     * Persist the vector store to disk.
     */
    public function save(): void
    {
        $data = [
            'vectors' => $this->vectors,
            'payloads' => $this->payloads,
        ];

        file_put_contents(
            $this->getDataFilePath(),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Load the vector store from disk.
     */
    protected function load(): void
    {
        $filePath = $this->getDataFilePath();

        if (file_exists($filePath)) {
            $data = json_decode(file_get_contents($filePath), true);
            $this->vectors = $data['vectors'] ?? [];
            $this->payloads = $data['payloads'] ?? [];
        }
    }

    /**
     * Get the path to the data file.
     */
    protected function getDataFilePath(): string
    {
        return $this->indexPath.'/vectors.json';
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param  array<float>  $a
     * @param  array<float>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        // Use epsilon comparison for floating-point zero check
        $epsilon = 1e-10;
        if ($normA < $epsilon || $normB < $epsilon) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Check if a payload matches the given filter.
     */
    protected function matchesFilter(array $payload, array $filter): bool
    {
        foreach ($filter as $key => $value) {
            if (! isset($payload[$key]) || $payload[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure a directory exists.
     */
    protected function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
