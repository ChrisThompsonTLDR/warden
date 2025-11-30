<?php

namespace Warden\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Warden\Services\AstExtractor;
use Warden\Services\BranchManager;
use Warden\Services\EmbeddingClient;
use Warden\Services\VectorStore;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\warning;

class WardenBuildIndexCommand extends Command
{
    protected $signature = 'warden:build-index 
                            {--path= : Custom path to index (defaults to project root)}
                            {--skip-embeddings : Skip generating embeddings (index AST only)}';

    protected $description = 'Build Warden AST + embedding index for current git branch';

    public function __construct(
        protected BranchManager $branchManager,
        protected AstExtractor $astExtractor,
        protected EmbeddingClient $embeddingClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $branch = $this->branchManager->getCurrentBranch();
        $pathOption = $this->option('path');
        $projectRoot = is_string($pathOption) ? $pathOption : base_path();
        $indexRoot = $this->branchManager->getIndexPath($branch);

        info("🔨 Building index for branch: {$branch}");

        // Ensure directories exist
        $this->branchManager->ensureDirectory($indexRoot.'/index');
        $this->branchManager->ensureDirectory($indexRoot.'/meta');

        // Create vector store
        $vectorStore = new VectorStore($indexRoot.'/index');

        // Find PHP files
        $finder = $this->createFileFinder($projectRoot);
        $files = iterator_to_array($finder);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            warning('No PHP files found to index.');

            return self::FAILURE;
        }

        note("Found {$totalFiles} PHP files to index...");

        $symbolIndex = [];
        $processedFiles = 0;
        $totalSymbols = 0;
        $errors = [];

        // Process files with progress bar
        $progress = progress(
            label: 'Indexing files...',
            steps: $totalFiles,
        );

        $progress->start();

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }
            $relPath = ltrim(str_replace($projectRoot, '', $filePath), DIRECTORY_SEPARATOR);
            $code = $file->getContents();

            try {
                $ast = $this->astExtractor->parse($code);

                if ($ast === null) {
                    $errors[] = "Failed to parse {$relPath}";
                    $progress->advance();

                    continue;
                }

                $symbols = $this->astExtractor->extractSymbols($ast, $relPath);

                foreach ($symbols as $symbol) {
                    $snippet = $this->astExtractor->sliceFileByLines(
                        $filePath,
                        $symbol['start_line'],
                        $symbol['end_line']
                    );

                    if (trim($snippet) === '') {
                        continue;
                    }

                    // Build embedding input
                    $embeddingInput = $this->astExtractor->buildEmbeddingInput($symbol, $snippet);

                    // Generate embedding unless skipped
                    $vector = [];
                    if (! $this->option('skip-embeddings')) {
                        try {
                            $vector = $this->embeddingClient->embed($embeddingInput);
                        } catch (\Exception $e) {
                            $errors[] = "Embedding error for {$symbol['fqcn']}: {$e->getMessage()}";

                            continue;
                        }
                    }

                    // Build payload
                    $payload = [
                        'branch' => $branch,
                        'file' => $symbol['file'],
                        'fqcn' => $symbol['fqcn'] ?? null,
                        'class' => $symbol['class'] ?? null,
                        'function' => $symbol['function'] ?? null,
                        'type' => $symbol['type'],
                        'start_line' => $symbol['start_line'],
                        'end_line' => $symbol['end_line'],
                        'docblock' => $symbol['docblock'] ?? null,
                    ];

                    // Build stable ID
                    $id = $this->astExtractor->buildSymbolId($branch, $payload);

                    // Upsert to vector store
                    $vectorStore->upsert($id, $vector, $payload);

                    // Add to symbol index
                    $symbolKey = $this->astExtractor->buildSymbolKey($payload);
                    if ($symbolKey) {
                        $symbolIndex[$symbolKey] = [
                            'id' => $id,
                            'file' => $payload['file'],
                            'start_line' => $payload['start_line'],
                            'end_line' => $payload['end_line'],
                            'fqcn' => $payload['fqcn'],
                            'type' => $payload['type'],
                        ];
                    }

                    $totalSymbols++;
                }

                $processedFiles++;
            } catch (\Exception $e) {
                $errors[] = "Error processing {$relPath}: {$e->getMessage()}";
            }

            $progress->advance();
        }

        $progress->finish();

        // Save vector store
        $vectorStore->save();

        // Save symbol index
        file_put_contents(
            $indexRoot.'/meta/symbol-index.json',
            json_encode($symbolIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Display summary
        $this->displaySummary($branch, $indexRoot, $processedFiles, $totalSymbols, $errors);

        return self::SUCCESS;
    }

    /**
     * Create a file finder for PHP files.
     */
    protected function createFileFinder(string $projectRoot): Finder
    {
        $finder = (new Finder)
            ->files()
            ->in($projectRoot)
            ->name('*.php');

        // Get exclude paths from config
        $excludePaths = config('warden.indexing.exclude', [
            'vendor',
            'node_modules',
            '.warden',
            'storage/framework',
            'bootstrap/cache',
        ]);

        foreach ($excludePaths as $path) {
            $finder->exclude($path);
        }

        return $finder;
    }

    /**
     * Display the build summary.
     *
     * @param  array<string>  $errors
     */
    protected function displaySummary(
        string $branch,
        string $indexRoot,
        int $processedFiles,
        int $totalSymbols,
        array $errors
    ): void {
        info('');
        info('📊 Index Build Summary');
        info('======================');
        note("Branch:           {$branch}");
        note("Index Path:       {$indexRoot}");
        note("Files Processed:  {$processedFiles}");
        note("Symbols Indexed:  {$totalSymbols}");

        if (! empty($errors)) {
            info('');
            warning('⚠️  Errors encountered:');
            foreach (array_slice($errors, 0, 10) as $error) {
                note("  - {$error}");
            }
            if (count($errors) > 10) {
                note('  ... and '.(count($errors) - 10).' more');
            }
        }

        info('');
        info("✓ Index built for branch [{$branch}] at {$indexRoot}");
    }
}
