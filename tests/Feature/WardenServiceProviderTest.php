<?php

namespace Warden\Tests\Feature;

use Warden\Tests\TestCase;

class WardenServiceProviderTest extends TestCase
{
    public function test_config_is_published(): void
    {
        $this->assertNotNull(config('warden'));
    }

    public function test_config_has_required_keys(): void
    {
        $this->assertNotNull(config('warden.deepwiki_server_url'));
        $this->assertNotNull(config('warden.warden_root'));
        $this->assertNotNull(config('warden.repo_name'));
    }

    public function test_services_are_registered(): void
    {
        $this->assertTrue($this->app->bound(\Warden\Services\BranchManager::class));
        $this->assertTrue($this->app->bound(\Warden\Services\WorktreeManager::class));
        $this->assertTrue($this->app->bound(\Warden\Services\StagingDatabaseManager::class));
        $this->assertTrue($this->app->bound(\Warden\Services\DeepwikiClient::class));
        $this->assertTrue($this->app->bound(\Warden\Services\GitHistoryService::class));
    }

    public function test_commands_are_registered(): void
    {
        $this->artisan('list')
            ->assertSuccessful();

        // Check that our commands exist
        $commands = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $this->assertArrayHasKey('warden:install', $commands);
        $this->assertArrayHasKey('warden:status', $commands);
        $this->assertArrayHasKey('deepwiki:reindex', $commands);
    }
}
