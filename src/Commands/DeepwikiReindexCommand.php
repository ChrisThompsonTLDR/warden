<?php

namespace Warden\Commands;

use Illuminate\Console\Command;
use Warden\Services\BranchManager;
use Warden\Services\DeepwikiClient;
use Warden\Services\GitHistoryService;
use Warden\Services\StagingDatabaseManager;
use Warden\Services\WorktreeManager;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class DeepwikiReindexCommand extends Command
{
    protected $signature = 'deepwiki:reindex 
                            {branch? : The branch to reindex (defaults to current branch)}
                            {--skip-migrations : Skip running migrations}
                            {--skip-seeders : Skip running seeders}
                            {--skip-history : Skip indexing commit history}
                            {--force : Force reindex even if index exists}';

    protected $description = 'Create worktree, staging DB, and trigger Deepwiki reindex for a branch';

    public function __construct(
        protected BranchManager $branchManager,
        protected WorktreeManager $worktreeManager,
        protected StagingDatabaseManager $stagingDbManager,
        protected GitHistoryService $gitHistoryService,
        protected DeepwikiClient $deepwikiClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Determine branch
        $branch = $this->argument('branch') ?? $this->branchManager->getCurrentBranch();

        info("🔄 Reindexing branch: {$branch}");

        // Step 1: Ensure worktree exists
        $worktreePath = $this->setupWorktree($branch);
        if (! $worktreePath) {
            return self::FAILURE;
        }

        // Step 2: Setup staging database
        if (! $this->option('skip-migrations')) {
            $this->setupStagingDatabase($branch);
        }

        // Step 3: Extract commit history
        if (! $this->option('skip-history')) {
            $this->extractCommitHistory($branch);
        }

        // Step 4: Trigger Deepwiki reindex
        $this->triggerDeepwikiReindex($branch, $worktreePath);

        $this->displaySummary($branch);

        return self::SUCCESS;
    }

    protected function setupWorktree(string $branch): ?string
    {
        note('Setting up Git worktree...');

        try {
            $worktreePath = spin(
                fn () => $this->worktreeManager->ensureWorktree($branch),
                'Creating/updating worktree...'
            );

            info("✓ Worktree ready at: {$worktreePath}");

            return $worktreePath;
        } catch (\Exception $e) {
            $this->error('Failed to setup worktree: '.$e->getMessage());

            return null;
        }
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

    protected function triggerDeepwikiReindex(string $branch, string $worktreePath): void
    {
        note('Triggering Deepwiki reindex...');

        // Check if Deepwiki is available
        if (! $this->deepwikiClient->health()) {
            warning('Deepwiki server is not available at '.config('warden.deepwiki_server_url'));
            warning('Please start Deepwiki and run this command again, or manually index the worktree.');

            return;
        }

        $projectId = $this->branchManager->getProjectId($branch);

        try {
            $result = spin(
                fn () => $this->deepwikiClient->reindex($projectId, $worktreePath, [
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
        $worktreePath = $this->branchManager->getWorktreePath($branch);
        $dbPath = $this->branchManager->getStagingDatabasePath($branch);
        $indexPath = $this->branchManager->getIndexPath($branch);
        $historyPath = $this->branchManager->getHistoryPath($branch);

        info('');
        info('📊 Reindex Summary');
        info('==================');
        note("Branch:          {$branch}");
        note("Project ID:      {$projectId}");
        note("Worktree:        {$worktreePath}");
        note("Staging DB:      {$dbPath}");
        note("Index Path:      {$indexPath}");
        note("History Path:    {$historyPath}");
        info('');
        note('You can now query this branch via MCP tools:');
        note("  deepwiki.ask({repo: '".config('warden.repo_name')."', branch: '{$branch}', question: '...'})");
    }
}
