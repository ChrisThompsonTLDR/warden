<?php

namespace Warden\Tests\Unit;

use Warden\Services\VectorStore;
use Warden\Tests\TestCase;

class VectorStoreTest extends TestCase
{
    protected string $testDir;

    protected VectorStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir().'/warden-vector-test-'.uniqid();
        mkdir($this->testDir, 0755, true);

        $this->store = new VectorStore($this->testDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_upsert_and_get(): void
    {
        $vector = [0.1, 0.2, 0.3];
        $payload = ['file' => 'test.php', 'type' => 'class'];

        $this->store->upsert('id1', $vector, $payload);

        $retrieved = $this->store->get('id1');
        $this->assertEquals($payload, $retrieved);
    }

    public function test_delete(): void
    {
        $this->store->upsert('id1', [0.1, 0.2], ['data' => 'test']);
        $this->assertNotNull($this->store->get('id1'));

        $this->store->delete('id1');
        $this->assertNull($this->store->get('id1'));
    }

    public function test_count(): void
    {
        $this->assertEquals(0, $this->store->count());

        $this->store->upsert('id1', [0.1, 0.2], []);
        $this->store->upsert('id2', [0.3, 0.4], []);

        $this->assertEquals(2, $this->store->count());
    }

    public function test_get_all_ids(): void
    {
        $this->store->upsert('id1', [0.1], []);
        $this->store->upsert('id2', [0.2], []);

        $ids = $this->store->getAllIds();

        $this->assertContains('id1', $ids);
        $this->assertContains('id2', $ids);
    }

    public function test_search_with_cosine_similarity(): void
    {
        // Insert some test vectors
        $this->store->upsert('id1', [1.0, 0.0, 0.0], ['name' => 'a']);
        $this->store->upsert('id2', [0.0, 1.0, 0.0], ['name' => 'b']);
        $this->store->upsert('id3', [0.9, 0.1, 0.0], ['name' => 'c']);

        // Search for vectors similar to [1.0, 0.0, 0.0]
        $results = $this->store->search([1.0, 0.0, 0.0], 2);

        $this->assertCount(2, $results);
        $this->assertEquals('id1', $results[0]['id']); // Exact match
        $this->assertEquals('id3', $results[1]['id']); // Most similar
    }

    public function test_search_with_filter(): void
    {
        $this->store->upsert('id1', [1.0, 0.0], ['type' => 'class']);
        $this->store->upsert('id2', [0.9, 0.1], ['type' => 'function']);
        $this->store->upsert('id3', [0.8, 0.2], ['type' => 'class']);

        // Search with filter for type = 'class'
        $results = $this->store->search([1.0, 0.0], 10, ['type' => 'class']);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertEquals('class', $result['payload']['type']);
        }
    }

    public function test_save_and_load(): void
    {
        $this->store->upsert('id1', [0.1, 0.2, 0.3], ['file' => 'test.php']);
        $this->store->save();

        // Create a new store instance from the same path
        $newStore = new VectorStore($this->testDir);

        $this->assertEquals(1, $newStore->count());
        $this->assertEquals(['file' => 'test.php'], $newStore->get('id1'));
    }

    public function test_cosine_similarity_edge_cases(): void
    {
        // Zero vectors should return score of 0
        $this->store->upsert('id1', [0.0, 0.0, 0.0], []);

        $results = $this->store->search([1.0, 0.0, 0.0], 1);

        $this->assertEquals(0.0, $results[0]['score']);
    }
}
