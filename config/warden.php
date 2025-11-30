<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deepwiki Server URL
    |--------------------------------------------------------------------------
    |
    | The URL of the Deepwiki-Open server. When running locally via Docker,
    | this defaults to http://localhost:8001. You can override this via the
    | WARDEN_DEEPWIKI_URL environment variable.
    |
    */
    'deepwiki_server_url' => env('WARDEN_DEEPWIKI_URL', 'http://localhost:8001'),

    /*
    |--------------------------------------------------------------------------
    | Warden Shared Key
    |--------------------------------------------------------------------------
    |
    | A shared secret key used to authenticate MCP requests. This provides
    | basic protection for local development. In production, use stronger
    | authentication or disable MCP exposure entirely.
    |
    */
    'shared_key' => env('WARDEN_SHARED_KEY'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key
    |--------------------------------------------------------------------------
    |
    | The OpenAI API key used by Deepwiki for embeddings and completions.
    | This should be set in your .env file.
    |
    */
    'openai_api_key' => env('OPENAI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Repository Name
    |--------------------------------------------------------------------------
    |
    | The logical name of this repository, used to construct Deepwiki project
    | IDs in the format <repo>-<branch>.
    |
    */
    'repo_name' => env('WARDEN_REPO_NAME', basename(base_path())),

    /*
    |--------------------------------------------------------------------------
    | Warden Root Directory
    |--------------------------------------------------------------------------
    |
    | The root directory for all Warden-related files including worktrees,
    | databases, and indexes. Defaults to .warden/ in the project root.
    |
    */
    'warden_root' => env('WARDEN_ROOT', base_path('.warden')),

    /*
    |--------------------------------------------------------------------------
    | Indexing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which directories to include or exclude when indexing.
    |
    */
    'indexing' => [
        // Directories to explicitly include (beyond standard source)
        'include' => [
            'vendor',
            'packages',
            '.aim',
            '.taskmaster',
            '.cursor',
        ],

        // Directories to exclude from indexing
        'exclude' => [
            'node_modules',
            '.git',
            '.warden',
            'storage/logs',
            'storage/framework/cache',
            'bootstrap/cache',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the MCP server endpoint.
    |
    */
    'mcp' => [
        'enabled' => env('WARDEN_MCP_ENABLED', true),
        'path' => env('WARDEN_MCP_PATH', '/mcp/warden'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings Provider
    |--------------------------------------------------------------------------
    |
    | Configure the embeddings provider. Supports 'openai' or 'local' (FAISS).
    |
    */
    'embeddings' => [
        'provider' => env('WARDEN_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('WARDEN_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Git History Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Git commit history indexing for RAG.
    |
    */
    'history' => [
        // Maximum number of commits to index per branch
        'max_commits' => env('WARDEN_HISTORY_MAX_COMMITS', 1000),

        // Include diffs in commit history (increases index size)
        'include_diffs' => env('WARDEN_HISTORY_INCLUDE_DIFFS', false),

        // Maximum diff size in bytes to include
        'max_diff_size' => env('WARDEN_HISTORY_MAX_DIFF_SIZE', 10000),
    ],
];
