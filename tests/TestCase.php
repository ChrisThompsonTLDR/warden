<?php

namespace Warden\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Warden\WardenServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            WardenServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up test configuration
        $app['config']->set('warden.warden_root', sys_get_temp_dir().'/warden-test');
        $app['config']->set('warden.repo_name', 'test-repo');
        $app['config']->set('warden.deepwiki_server_url', 'http://localhost:8001');
        $app['config']->set('warden.shared_key', 'test-shared-key');
    }
}
