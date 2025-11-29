<?php

namespace Warden\Services;

use Prism\Prism\Prism;

class EmbeddingClient
{
    public function __construct(
        protected string $model = 'text-embedding-3-small'
    ) {}

    /**
     * Generate an embedding vector for the given text.
     *
     * @return array<float>
     */
    public function embed(string $text): array
    {
        $response = Prism::embeddings()
            ->using('openai', $this->model)
            ->fromInput($text)
            ->generate();

        return $response->embeddings[0]->embedding;
    }

    /**
     * Generate embeddings for multiple texts.
     *
     * Note: This currently processes embeddings sequentially. While less efficient
     * than true batch processing, this approach ensures compatibility with Prism's
     * API and provides better error isolation per embedding.
     *
     * @param  array<string>  $texts
     * @return array<array<float>>
     */
    public function embedBatch(array $texts): array
    {
        $embeddings = [];

        foreach ($texts as $text) {
            $embeddings[] = $this->embed($text);
        }

        return $embeddings;
    }
}
