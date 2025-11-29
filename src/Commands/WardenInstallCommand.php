<?php

namespace Warden\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class WardenInstallCommand extends Command
{
    protected $signature = 'warden:install 
                            {--force : Overwrite existing files}
                            {--skip-docker : Skip Docker/Sail setup}';

    protected $description = 'Install and configure Warden for your Laravel application';

    public function handle(): int
    {
        info('🛡️ Installing Warden - Deepwiki + MCP Integration');

        $this->publishConfig();
        $this->publishMcpStubs();
        $this->publishDocumentation();
        $this->setupWardenDirectory();

        if (! $this->option('skip-docker')) {
            $this->setupDocker();
        }

        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        note('Publishing configuration...');

        $this->call('vendor:publish', [
            '--tag' => 'warden-config',
            '--force' => $this->option('force'),
        ]);
    }

    protected function publishMcpStubs(): void
    {
        note('Publishing MCP server and tools...');

        // Ensure directories exist
        $mcpServersPath = app_path('Mcp/Servers');
        $mcpToolsPath = app_path('Mcp/Tools');

        if (! File::isDirectory($mcpServersPath)) {
            File::makeDirectory($mcpServersPath, 0755, true);
        }

        if (! File::isDirectory($mcpToolsPath)) {
            File::makeDirectory($mcpToolsPath, 0755, true);
        }

        $this->call('vendor:publish', [
            '--tag' => 'warden-mcp',
            '--force' => $this->option('force'),
        ]);
    }

    protected function publishDocumentation(): void
    {
        note('Publishing documentation...');

        // Ensure .cursor/rules directory exists
        $cursorRulesPath = base_path('.cursor/rules');
        if (! File::isDirectory($cursorRulesPath)) {
            File::makeDirectory($cursorRulesPath, 0755, true);
        }

        $this->call('vendor:publish', [
            '--tag' => 'warden-docs',
            '--force' => $this->option('force'),
        ]);
    }

    protected function setupWardenDirectory(): void
    {
        note('Setting up .warden directory structure...');

        $wardenRoot = config('warden.warden_root', base_path('.warden'));

        $directories = [
            $wardenRoot,
            $wardenRoot.'/worktrees',
        ];

        foreach ($directories as $dir) {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
        }

        // Create .gitignore in .warden
        $gitignore = <<<'GITIGNORE'
# Warden directory structure
# ===========================
# This directory contains:
# - worktrees/<branch>/ - Git worktree checkouts per branch
# - <branch>/database/staging.sqlite - Branch-specific staging databases
# - <branch>/index/ - FAISS vector indexes per branch
# - <branch>/history/ - Git commit history for RAG

# Ignore all worktrees (they're ephemeral)
worktrees/

# Ignore staging databases (can be regenerated)
*/database/

# Optionally commit indexes if you want persistent analysis
# */index/

# Ignore history (can be regenerated)
*/history/

# Keep this file
!.gitignore
GITIGNORE;

        File::put($wardenRoot.'/.gitignore', $gitignore);

        info("✓ Created .warden directory at {$wardenRoot}");
    }

    protected function setupDocker(): void
    {
        note('Checking Docker/Sail setup...');

        $hasSail = $this->detectSail();

        if ($hasSail) {
            $this->setupSailIntegration();
        } else {
            $this->setupStandaloneDocker();
        }
    }

    protected function detectSail(): bool
    {
        // Check for Laravel Sail in composer.json
        $composerJson = base_path('composer.json');
        if (File::exists($composerJson)) {
            $composer = json_decode(File::get($composerJson), true);

            if (isset($composer['require']['laravel/sail']) ||
                isset($composer['require-dev']['laravel/sail'])) {
                return true;
            }
        }

        // Check for sail binary
        return File::exists(base_path('vendor/bin/sail'));
    }

    protected function setupSailIntegration(): void
    {
        info('✓ Laravel Sail detected');

        if (! confirm('Would you like to add Deepwiki-Open as a Sail service?', true)) {
            warning('Skipping Sail integration. You can configure Deepwiki manually.');

            return;
        }

        // Publish docker-compose.warden.yml
        $this->call('vendor:publish', [
            '--tag' => 'warden-docker',
            '--force' => $this->option('force'),
        ]);

        // Check if docker-compose.yml exists
        $dockerComposePath = base_path('docker-compose.yml');
        if (File::exists($dockerComposePath)) {
            $this->mergeDockerCompose($dockerComposePath);
        }

        info('✓ Docker Compose configuration updated');

        note('To start Deepwiki, run:');
        note('  sail up -d deepwiki');
    }

    protected function mergeDockerCompose(string $path): void
    {
        $existingConfig = File::get($path);

        // Check if deepwiki service already exists
        if (str_contains($existingConfig, 'deepwiki:')) {
            warning('Deepwiki service already exists in docker-compose.yml');

            return;
        }

        // Add reference to the warden compose file
        if (! str_contains($existingConfig, 'docker-compose.warden.yml')) {
            note('Add the following to your docker-compose.yml or use docker compose -f:');
            note('  docker compose -f docker-compose.yml -f docker-compose.warden.yml up');
        }
    }

    protected function setupStandaloneDocker(): void
    {
        warning('Laravel Sail not detected');

        if (confirm('Would you like to set up Deepwiki-Open with standalone Docker?', true)) {
            // Publish docker-compose file
            $this->call('vendor:publish', [
                '--tag' => 'warden-docker',
                '--force' => $this->option('force'),
            ]);

            info('✓ Published docker-compose.warden.yml');

            note('To start Deepwiki, run:');
            note('  docker compose -f docker-compose.warden.yml up -d');
        } else {
            note('Warden will use the default Deepwiki URL: '.config('warden.deepwiki_server_url'));
            note('You can configure WARDEN_DEEPWIKI_URL in your .env file to point to a remote Deepwiki server.');
        }
    }

    protected function displayNextSteps(): void
    {
        info('');
        info('🎉 Warden installation complete!');
        info('');
        note('Next steps:');
        note('');
        note('1. Add the following to your .env file:');
        note('   OPENAI_API_KEY=your-openai-api-key');
        note('   WARDEN_SHARED_KEY='.bin2hex(random_bytes(16)));
        note('');
        note('2. Register the MCP server in config/mcp.php:');
        note('   \'servers\' => [');
        note('       \'warden\' => App\\Mcp\\Servers\\WardenServer::class,');
        note('   ],');
        note('');
        note('3. Start Deepwiki and create your first index:');
        note('   php artisan deepwiki:reindex');
        note('');
        note('4. Connect your MCP client (ChatGPT, Cursor, etc.) to:');
        note('   '.url(config('warden.mcp.path', '/mcp/warden')));
        note('');
        note('For more information, see AGENTS.md');
    }
}
