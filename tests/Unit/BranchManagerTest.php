<?php

namespace Warden\Tests\Unit;

use Warden\Services\BranchManager;
use Warden\Tests\TestCase;

class BranchManagerTest extends TestCase
{
    protected BranchManager $branchManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchManager = new BranchManager(
            sys_get_temp_dir().'/warden-test',
            'test-repo'
        );
    }

    public function test_get_project_id(): void
    {
        $this->assertEquals('test-repo-main', $this->branchManager->getProjectId('main'));
        $this->assertEquals('test-repo-develop', $this->branchManager->getProjectId('develop'));
    }

    public function test_get_project_id_sanitizes_branch_name(): void
    {
        $this->assertEquals('test-repo-feature-2fa', $this->branchManager->getProjectId('feature/2fa'));
        $this->assertEquals('test-repo-release-v1-0-0', $this->branchManager->getProjectId('release/v1.0.0'));
    }

    public function test_sanitize_branch_name(): void
    {
        $this->assertEquals('main', $this->branchManager->sanitizeBranchName('main'));
        $this->assertEquals('feature-2fa', $this->branchManager->sanitizeBranchName('feature/2fa'));
        $this->assertEquals('release-v1-0-0', $this->branchManager->sanitizeBranchName('release/v1.0.0'));
        $this->assertEquals('bug-fix-123', $this->branchManager->sanitizeBranchName('bug/fix#123'));
    }

    public function test_get_worktree_path(): void
    {
        $expected = sys_get_temp_dir().'/warden-test/worktrees/main';
        $this->assertEquals($expected, $this->branchManager->getWorktreePath('main'));

        $expected = sys_get_temp_dir().'/warden-test/worktrees/feature-2fa';
        $this->assertEquals($expected, $this->branchManager->getWorktreePath('feature/2fa'));
    }

    public function test_get_database_path(): void
    {
        $expected = sys_get_temp_dir().'/warden-test/main/database';
        $this->assertEquals($expected, $this->branchManager->getDatabasePath('main'));
    }

    public function test_get_staging_database_path(): void
    {
        $expected = sys_get_temp_dir().'/warden-test/main/database/staging.sqlite';
        $this->assertEquals($expected, $this->branchManager->getStagingDatabasePath('main'));
    }

    public function test_get_index_path(): void
    {
        $expected = sys_get_temp_dir().'/warden-test/main/index';
        $this->assertEquals($expected, $this->branchManager->getIndexPath('main'));
    }

    public function test_get_history_path(): void
    {
        $expected = sys_get_temp_dir().'/warden-test/main/history';
        $this->assertEquals($expected, $this->branchManager->getHistoryPath('main'));
    }

    public function test_ensure_directory_creates_directory(): void
    {
        $testDir = sys_get_temp_dir().'/warden-test-'.uniqid();

        $this->assertDirectoryDoesNotExist($testDir);

        $this->branchManager->ensureDirectory($testDir);

        $this->assertDirectoryExists($testDir);

        // Cleanup
        rmdir($testDir);
    }

    public function test_get_warden_root(): void
    {
        $this->assertEquals(sys_get_temp_dir().'/warden-test', $this->branchManager->getWardenRoot());
    }

    public function test_get_repo_name(): void
    {
        $this->assertEquals('test-repo', $this->branchManager->getRepoName());
    }
}
