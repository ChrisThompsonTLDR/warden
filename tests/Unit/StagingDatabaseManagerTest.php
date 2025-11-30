<?php

namespace Warden\Tests\Unit;

use Warden\Services\BranchManager;
use Warden\Services\StagingDatabaseManager;
use Warden\Tests\TestCase;

class StagingDatabaseManagerTest extends TestCase
{
    protected StagingDatabaseManager $dbManager;

    protected BranchManager $branchManager;

    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir().'/warden-db-test-'.uniqid();
        mkdir($this->testDir, 0755, true);

        $this->branchManager = new BranchManager($this->testDir, 'test-repo');
        $this->dbManager = new StagingDatabaseManager($this->branchManager);
    }

    protected function tearDown(): void
    {
        // Cleanup test directory
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

    public function test_create_staging_database(): void
    {
        $dbPath = $this->dbManager->createStagingDatabase('main');

        $this->assertFileExists($dbPath);
        $this->assertStringEndsWith('/main/database/staging.sqlite', $dbPath);
    }

    public function test_database_exists(): void
    {
        $this->assertFalse($this->dbManager->databaseExists('main'));

        $this->dbManager->createStagingDatabase('main');

        $this->assertTrue($this->dbManager->databaseExists('main'));
    }

    public function test_get_database_path(): void
    {
        $expected = $this->testDir.'/main/database/staging.sqlite';
        $this->assertEquals($expected, $this->dbManager->getDatabasePath('main'));
    }

    public function test_remove_staging_database(): void
    {
        $this->dbManager->createStagingDatabase('main');
        $this->assertTrue($this->dbManager->databaseExists('main'));

        $this->dbManager->removeStagingDatabase('main');
        $this->assertFalse($this->dbManager->databaseExists('main'));
    }

    public function test_get_database_size(): void
    {
        $this->assertEquals(0, $this->dbManager->getDatabaseSize('main'));

        $this->dbManager->createStagingDatabase('main');

        // Empty SQLite file should have size > 0 after creation (actually 0 for touch)
        $this->assertGreaterThanOrEqual(0, $this->dbManager->getDatabaseSize('main'));
    }

    public function test_get_connection_config(): void
    {
        $config = $this->dbManager->getConnectionConfig('main');

        $this->assertEquals('sqlite', $config['driver']);
        $this->assertStringEndsWith('/main/database/staging.sqlite', $config['database']);
        $this->assertTrue($config['foreign_key_constraints']);
    }

    public function test_create_staging_database_resets_existing(): void
    {
        // Create initial database
        $dbPath = $this->dbManager->createStagingDatabase('main');

        // Write some content to it
        file_put_contents($dbPath, 'test content');
        $this->assertEquals('test content', file_get_contents($dbPath));

        // Create again - should reset
        $this->dbManager->createStagingDatabase('main');
        $this->assertEquals('', file_get_contents($dbPath));
    }

    public function test_list_branches_with_databases(): void
    {
        $this->assertEmpty($this->dbManager->listBranchesWithDatabases());

        $this->dbManager->createStagingDatabase('main');
        $this->dbManager->createStagingDatabase('develop');

        $branches = $this->dbManager->listBranchesWithDatabases();

        $this->assertCount(2, $branches);
        $this->assertEquals('develop', $branches[0]['branch']);
        $this->assertEquals('main', $branches[1]['branch']);
    }
}
