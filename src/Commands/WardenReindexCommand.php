<?php

namespace Warden\Commands;

use Illuminate\Console\Command;
use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;
use Warden\Services\GitHistoryService;
use Warden\Services\StagingDatabaseManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class WardenReindexCommand extends Command
{
    protected $signature = 'warden:reindex 
                            {--skip-migrations : Skip running migrations}
                            {--skip-seeders : Skip running seeders}
                            {--skip-history : Skip indexing commit history}
                            {--force : Force reindex even if index exists}';

    protected $description = 'Index current git branch: create staging DB, extract history, and trigger Deepwiki reindex';

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
        // Get current branch from git
        $branch = $this->branchManager->getCurrentBranch();
        info("🔄 Reindexing branch: {$branch}");

        // Step 1: Setup staging database
        if (! $this->option('skip-migrations')) {
            $this->setupStagingDatabase($branch);
        }

        // Step 2: Extract commit history
        if (! $this->option('skip-history')) {
            $this->extractCommitHistory($branch);
        }

        // Step 3: Trigger Deepwiki reindex
        $repoPath = $this->branchManager->getRepoPath();
        $this->triggerDeepwikiReindex($branch, $repoPath);

        $this->displaySummary($branch);

        return self::SUCCESS;
    }

    protected function setupStagingDatabase(string $branch): void
    {
        note('Setting up staging database...');

        try {
            $dbPath = spin(
                fn () => $this->stagingDbManager->createStagingDatabase($branch),
                'Creating staging database...'
            );

            info("✓ Staging database created: {$dbPath}");

            // Run migrations
            note('Running migrations...');
            try {
                spin(
                    fn () => $this->stagingDbManager->runMigrations($branch),
                    'Applying migrations...'
                );
                info('✓ Migrations applied');
            } catch (\Exception $e) {
                warning('Migration warning: '.$e->getMessage());
            }

            // Run seeders if not skipped
            if (! $this->option('skip-seeders')) {
                note('Running seeders...');
                try {
                    spin(
                        fn () => $this->stagingDbManager->runSeeders($branch),
                        'Running seeders...'
                    );
                    info('✓ Seeders completed');
                } catch (\Exception $e) {
                    warning('Seeder warning: '.$e->getMessage());
                }
            }
        } catch (\Exception $e) {
            warning('Database setup warning: '.$e->getMessage());
        }
    }

    protected function extractCommitHistory(string $branch): void
    {
        note('Extracting commit history...');

        try {
            $historyPath = spin(
                fn () => $this->gitHistoryService->extractHistory($branch),
                'Processing commit history...'
            );

            $stats = $this->gitHistoryService->getHistoryStats($branch);
            info("✓ Extracted {$stats['total_commits']} commits to: {$historyPath}");
        } catch (\Exception $e) {
            warning('History extraction warning: '.$e->getMessage());
        }
    }

    protected function triggerDeepwikiReindex(string $branch, string $repoPath): void
    {
        note('Triggering Deepwiki reindex...');

        // Check if Deepwiki is available
        if (! $this->deepwikiClient->health()) {
            warning('Deepwiki server is not available at '.config('warden.deepwiki_server_url'));
            warning('Please start Deepwiki and run this command again.');

            return;
        }

        $projectId = $this->branchManager->getProjectId($branch);

        try {
            $result = spin(
                fn () => $this->deepwikiClient->reindex($projectId, $repoPath, [
                    'include' => config('warden.indexing.include', []),
                    'exclude' => config('warden.indexing.exclude', []),
                    'force' => $this->option('force'),
                ]),
                "Indexing {$projectId}..."
            );

            info("✓ Deepwiki reindex triggered for project: {$projectId}");

            if (isset($result['status'])) {
                note("Status: {$result['status']}");
            }
        } catch (\Exception $e) {
            warning('Deepwiki reindex warning: '.$e->getMessage());
        }
    }

    protected function displaySummary(string $branch): void
    {
        $projectId = $this->branchManager->getProjectId($branch);
        $repoPath = $this->branchManager->getRepoPath();
        $dbPath = $this->branchManager->getStagingDatabasePath($branch);
        $indexPath = $this->branchManager->getIndexPath($branch);
        $historyPath = $this->branchManager->getHistoryPath($branch);

        info('');
        info('📊 Reindex Summary');
        info('==================');
        note("Branch:          {$branch}");
        note("Project ID:      {$projectId}");
        note("Repository:      {$repoPath}");
        note("Staging DB:      {$dbPath}");
        note("Index Path:      {$indexPath}");
        note("History Path:    {$historyPath}");
        info('');
        note('You can now query this branch via MCP tools:');
        note("  deepwiki.ask({repo: '".config('warden.repo_name')."', branch: '{$branch}', question: '...'})");
    }
}
