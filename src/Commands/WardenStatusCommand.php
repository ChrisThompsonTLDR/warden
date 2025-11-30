<?php

namespace Warden\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;
use Warden\Services\GitHistoryService;
use Warden\Services\StagingDatabaseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class WardenStatusCommand extends Command
{
    protected $signature = 'warden:status';

    protected $description = 'Display Warden status and configuration';

    public function __construct(
        protected BranchManager $branchManager,
        protected StagingDatabaseManager $stagingDbManager,
        protected GitHistoryService $gitHistoryService,
        protected DeepwikiClient $deepwikiClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        info('🛡️ Warden Status');
        info('================');

        $this->displayConfiguration();
        $this->displayDeepwikiStatus();
        $this->displayBranches();
        $this->displayDatabases();

        return self::SUCCESS;
    }

    protected function displayConfiguration(): void
    {
        note('');
        note('Configuration:');

        $configItems = [
            ['Deepwiki URL', config('warden.deepwiki_server_url')],
            ['Repository', config('warden.repo_name')],
            ['Warden Root', config('warden.warden_root')],
            ['MCP Enabled', config('warden.mcp.enabled') ? 'Yes' : 'No'],
            ['MCP Path', config('warden.mcp.path')],
            ['Shared Key', config('warden.shared_key') ? '***configured***' : 'NOT SET'],
            ['OpenAI Key', config('warden.openai_api_key') ? '***configured***' : 'NOT SET'],
        ];

        table(['Setting', 'Value'], $configItems);
    }

    protected function displayDeepwikiStatus(): void
    {
        note('');
        note('Deepwiki Server:');

        $isHealthy = $this->deepwikiClient->health();

        if ($isHealthy) {
            info('✓ Deepwiki server is running');

            $serverInfo = $this->deepwikiClient->getServerInfo();
            if (! empty($serverInfo)) {
                foreach ($serverInfo as $key => $value) {
                    note("  {$key}: {$value}");
                }
            }
        } else {
            warning('✗ Deepwiki server is not available');

            // Detect if Sail is being used
            $hasSail = $this->detectSail();
            if ($hasSail && File::exists(base_path('compose.yaml'))) {
                note('  Start with: sail up -d deepwiki');
            } else {
                note('  Start with: docker compose -f docker-compose.warden.yml up -d');
            }
        }
    }

    protected function displayBranches(): void
    {
        note('');
        note('Current Branch: '.$this->branchManager->getCurrentBranch());

        $branches = $this->branchManager->listBranches();
        if (empty($branches)) {
            note('No Warden branches configured yet.');
            note('Run: php artisan warden:reindex');

            return;
        }

        note('');
        note('Indexed Branches:');

        $branchData = [];
        foreach ($branches as $branch) {
            $projectId = $this->branchManager->getProjectId($branch);
            $hasDb = $this->stagingDbManager->databaseExists($branch) ? '✓' : '✗';
            $historyPath = $this->branchManager->getHistoryPath($branch);
            $hasHistory = file_exists($historyPath.'/commits.jsonl') ? '✓' : '✗';
            $indexPath = $this->branchManager->getIndexPath($branch);
            $hasIndex = is_dir($indexPath) ? '✓' : '✗';

            $branchData[] = [$branch, $projectId, $hasIndex, $hasDb, $hasHistory];
        }

        table(['Branch', 'Project ID', 'Index', 'Database', 'History'], $branchData);
    }

    protected function displayDatabases(): void
    {
        note('');
        note('Staging Databases:');

        $databases = $this->stagingDbManager->listBranchesWithDatabases();
        if (empty($databases)) {
            note('No staging databases found.');

            return;
        }

        $dbData = [];
        foreach ($databases as $db) {
            $dbData[] = [
                $db['branch'],
                $db['database_path'],
                $this->formatBytes($db['size']),
            ];
        }

        table(['Branch', 'Path', 'Size'], $dbData);
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
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
}
