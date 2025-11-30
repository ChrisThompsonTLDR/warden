<?php

namespace Warden\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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

        if (! File::isDirectory($wardenRoot)) {
            File::makeDirectory($wardenRoot, 0755, true);
        }

        // Create .gitignore in .warden
        $gitignore = <<<'GITIGNORE'
# Warden directory structure
# ===========================
# This directory contains:
# - <branch>/database/staging.sqlite - Branch-specific staging databases
# - <branch>/index/ - FAISS vector indexes per branch
# - <branch>/history/ - Git commit history for RAG

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

        // Sail uses compose.yaml (generated from compose.stub)
        $composePath = base_path('compose.yaml');
        if (! File::exists($composePath)) {
            warning('compose.yaml not found. Sail may not be installed yet.');

            if (confirm('Would you like to publish Sail\'s compose.yaml first?', true)) {
                try {
                    $this->call('sail:publish');
                } catch (\Exception $e) {
                    warning('Failed to publish Sail compose.yaml: '.$e->getMessage());
                    note('Please run "php artisan sail:publish" manually, then run "php artisan warden:install" again.');

                    return;
                }
            } else {
                note('Please run "php artisan sail:publish" first, then run "php artisan warden:install" again.');
                note('Alternatively, you can manually add Deepwiki to your compose.yaml.');

                return;
            }
        }

        // Publish docker-compose.warden.yml as reference
        $this->call('vendor:publish', [
            '--tag' => 'warden-docker',
            '--force' => $this->option('force'),
        ]);

        // Merge Deepwiki service into Sail's compose.yaml
        $this->mergeDockerCompose($composePath);

        info('✓ Docker Compose configuration updated');

        note('To start Deepwiki, run:');
        note('  sail up -d deepwiki');
    }

    protected function mergeDockerCompose(string $path): void
    {
        $existingConfig = File::get($path);
        $filename = basename($path);

        // Check if deepwiki service already exists
        if (str_contains($existingConfig, 'deepwiki:')) {
            warning("Deepwiki service already exists in {$filename}");

            return;
        }

        // Try to use Symfony YAML if available
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->mergeDockerComposeWithYaml($path, $existingConfig);

            return;
        }

        // Fallback to string-based insertion
        $this->mergeDockerComposeWithString($path, $existingConfig);
    }

    protected function mergeDockerComposeWithYaml(string $path, string $existingConfig): void
    {
        try {
            $yaml = \Symfony\Component\Yaml\Yaml::parse($existingConfig);

            // Add deepwiki service
            if (! isset($yaml['services'])) {
                $yaml['services'] = [];
            }

            // Determine which network to use - check if Sail network exists
            $hasSailNetwork = isset($yaml['networks']['sail']);
            $network = $hasSailNetwork ? 'sail' : 'warden';

            $yaml['services']['deepwiki'] = [
                'image' => 'ghcr.io/asyncfuncai/deepwiki-open:latest',
                'container_name' => 'deepwiki',
                'restart' => 'unless-stopped',
                'ports' => [
                    '127.0.0.1:8001:8001',
                ],
                'environment' => [
                    'OPENAI_API_KEY=${OPENAI_API_KEY}',
                    'EMBEDDING_MODEL=${WARDEN_EMBEDDINGS_MODEL:-text-embedding-3-small}',
                    'HOST=0.0.0.0',
                    'PORT=8001',
                ],
                'volumes' => [
                    './.warden:/app/data',
                ],
                'healthcheck' => [
                    'test' => ['CMD', 'curl', '-f', 'http://localhost:8001/health'],
                    'interval' => '30s',
                    'timeout' => '10s',
                    'retries' => 3,
                    'start_period' => '10s',
                ],
                'networks' => [$network],
            ];

            // Add warden network only if not using Sail's network
            if (! $hasSailNetwork) {
                if (! isset($yaml['networks'])) {
                    $yaml['networks'] = [];
                }
                if (! isset($yaml['networks']['warden'])) {
                    $yaml['networks']['warden'] = [
                        'driver' => 'bridge',
                    ];
                }
            }

            // Write back the merged YAML
            $mergedYaml = \Symfony\Component\Yaml\Yaml::dump($yaml, 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            File::put($path, $mergedYaml);

            info('✓ Added Deepwiki service to compose.yaml');
        } catch (\Exception $e) {
            warning('Failed to merge with YAML parser: '.$e->getMessage());
            $this->mergeDockerComposeWithString($path, $existingConfig);
        }
    }

    protected function mergeDockerComposeWithString(string $path, string $existingConfig): void
    {
        // Check if Sail network exists - if so, use it; otherwise create warden network
        $hasSailNetwork = preg_match('/^\s+sail:\s*$/m', $existingConfig);
        $network = $hasSailNetwork ? 'sail' : 'warden';

        $deepwikiService = $this->getDeepwikiServiceYaml($network);

        // Find the services section
        if (! preg_match('/^services:\s*$/m', $existingConfig)) {
            // No services section, add it
            $existingConfig .= "\nservices:\n".$deepwikiService;
        } else {
            // Find where to insert - look for end of services section (networks, volumes, or end of file)
            $insertPos = null;

            // Try to find networks section first (common in Sail)
            if (preg_match('/^networks:\s*$/m', $existingConfig, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1];
            } elseif (preg_match('/^volumes:\s*$/m', $existingConfig, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $matches[0][1];
            }

            if ($insertPos !== null) {
                // Insert before networks/volumes section
                $existingConfig = substr_replace($existingConfig, "\n".$deepwikiService."\n", $insertPos, 0);
            } else {
                // No networks/volumes section, append at end
                $existingConfig = rtrim($existingConfig)."\n\n".$deepwikiService;
            }
        }

        // Add networks section if needed (only if not using Sail's network)
        if (! $hasSailNetwork) {
            if (! preg_match('/^networks:\s*$/m', $existingConfig)) {
                $existingConfig .= "\n\nnetworks:\n  warden:\n    driver: bridge\n";
            } elseif (! preg_match('/^\s+warden:/m', $existingConfig)) {
                // Networks section exists but warden network doesn't
                if (preg_match('/^networks:\s*$/m', $existingConfig, $matches, PREG_OFFSET_CAPTURE)) {
                    $insertPos = $matches[0][1] + strlen($matches[0][0]);
                    $existingConfig = substr_replace($existingConfig, "\n  warden:\n    driver: bridge", $insertPos, 0);
                }
            }
        }

        File::put($path, $existingConfig);
        $filename = basename($path);
        info("✓ Added Deepwiki service to {$filename}");
    }

    protected function getDeepwikiServiceYaml(string $network = 'warden'): string
    {
        return <<<YAML
  deepwiki:
    image: ghcr.io/asyncfuncai/deepwiki-open:latest
    container_name: deepwiki
    restart: unless-stopped
    ports:
      - "127.0.0.1:8001:8001"
    environment:
      - OPENAI_API_KEY=\${OPENAI_API_KEY}
      - EMBEDDING_MODEL=\${WARDEN_EMBEDDINGS_MODEL:-text-embedding-3-small}
      - HOST=0.0.0.0
      - PORT=8001
    volumes:
      - ./.warden:/app/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8001/health"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 10s
    networks:
      - {$network}
YAML;
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
        note('   php artisan warden:reindex');
        note('');
        note('4. Connect your MCP client (ChatGPT, Cursor, etc.) to:');
        $mcpPath = config('warden.mcp.path', '/mcp/warden');
        $mcpUrl = url($mcpPath);
        if (is_string($mcpUrl)) {
            note('   '.$mcpUrl);
        } else {
            note('   '.$mcpPath.' (configure your base URL)');
        }
        note('');
        note('For more information, see AGENTS.md');
    }
}
