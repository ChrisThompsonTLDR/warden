<?php

namespace Warden;

use Illuminate\Support\ServiceProvider;
use Warden\Commands\WardenBuildIndexCommand;
use Warden\Commands\WardenInstallCommand;
use Warden\Commands\WardenReindexCommand;
use Warden\Commands\WardenStatusCommand;
use Warden\Http\Middleware\ValidateWardenSharedKey;
use Warden\Services\AstExtractor;
use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;
use Warden\Services\EmbeddingClient;
use Warden\Services\GitHistoryService;
use Warden\Services\StagingDatabaseManager;
use Warden\Services\WorktreeManager;

class WardenServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/warden.php', 'warden');

        $this->app->singleton(BranchManager::class, function ($app) {
            return new BranchManager(
                config('warden.warden_root'),
                config('warden.repo_name')
            );
        });

        $this->app->singleton(StagingDatabaseManager::class, function ($app) {
            return new StagingDatabaseManager(
                $app->make(BranchManager::class)
            );
        });

        $this->app->singleton(WorktreeManager::class, function ($app) {
            return new WorktreeManager(
                $app->make(BranchManager::class)
            );
        });

        $this->app->singleton(DeepwikiClient::class, function ($app) {
            return new DeepwikiClient(
                config('warden.deepwiki_server_url'),
                config('warden.openai_api_key')
            );
        });

        $this->app->singleton(GitHistoryService::class, function ($app) {
            return new GitHistoryService(
                $app->make(BranchManager::class),
                config('warden.history')
            );
        });

        $this->app->singleton(AstExtractor::class, function ($app) {
            return new AstExtractor;
        });

        $this->app->singleton(EmbeddingClient::class, function ($app) {
            return new EmbeddingClient(
                config('warden.embeddings.model', 'text-embedding-3-small')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/warden.php' => config_path('warden.php'),
            ], 'warden-config');

            $this->publishes([
                __DIR__.'/../stubs/WardenServer.stub' => app_path('Mcp/Servers/WardenServer.php'),
                __DIR__.'/../stubs/DeepwikiAskTool.stub' => app_path('Mcp/Tools/DeepwikiAskTool.php'),
                __DIR__.'/../stubs/DeepwikiAskHistoryTool.stub' => app_path('Mcp/Tools/DeepwikiAskHistoryTool.php'),
                __DIR__.'/../stubs/DeepwikiReindexTool.stub' => app_path('Mcp/Tools/DeepwikiReindexTool.php'),
                __DIR__.'/../stubs/DeepwikiListProjectsTool.stub' => app_path('Mcp/Tools/DeepwikiListProjectsTool.php'),
            ], 'warden-mcp');

            $this->publishes([
                __DIR__.'/../stubs/AGENTS.md' => base_path('AGENTS.md'),
                __DIR__.'/../stubs/warden.mdc' => base_path('.cursor/rules/warden.mdc'),
            ], 'warden-docs');

            $this->publishes([
                __DIR__.'/../stubs/docker-compose.warden.yml' => base_path('docker-compose.warden.yml'),
            ], 'warden-docker');

            $this->commands([
                WardenInstallCommand::class,
                WardenStatusCommand::class,
                WardenReindexCommand::class,
                WardenBuildIndexCommand::class,
            ]);
        }

        // Register middleware alias
        if ($this->app->bound('router')) {
            $this->app->make('router')->aliasMiddleware('warden.auth', ValidateWardenSharedKey::class);
        }
    }
}
