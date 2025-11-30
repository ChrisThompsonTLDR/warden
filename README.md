# Warden

[![Latest Version on Packagist](https://img.shields.io/packagist/v/christhompsontldr/warden.svg?style=flat-square)](https://packagist.org/packages/christhompsontldr/warden)
[![Total Downloads](https://img.shields.io/packagist/dt/christhompsontldr/warden.svg?style=flat-square)](https://packagist.org/packages/christhompsontldr/warden)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/christhompsontldr/warden/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ChrisThompsonTLDR/warden/actions?query=workflow%3Atests+branch%3Amain)

A Laravel package that integrates [Deepwiki-Open](https://github.com/AsyncFuncAI/deepwiki-open) with [laravel/mcp](https://github.com/laravel/mcp) for AI-powered codebase analysis. Enable ChatGPT, Claude, Cursor, Windsurf, and other MCP clients to query your codebase with branch-aware indexing.

## Features

- 🔍 **Branch-aware indexing** - Each Git branch gets its own isolated index
- 🗄️ **Per-branch staging databases** - SQLite databases for deterministic analysis
- 📚 **Git history RAG** - Query commit history alongside code content
- 🔐 **Shared key authentication** - Protect MCP endpoints with a simple shared secret
- 🐳 **Docker integration** - Easy setup with Laravel Sail or standalone Docker
- 🤖 **MCP Tools** - Ready-to-use tools for AI assistants

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+
- Docker (for running Deepwiki-Open)
- OpenAI API key

## Installation

Install the package via Composer:

```bash
composer require christhompsontldr/warden
```

Run the install command:

```bash
php artisan warden:install
```

This will:
- Publish the configuration file
- Publish MCP server and tools
- Publish documentation (AGENTS.md, .cursor/rules/warden.mdc)
- Set up the `.warden/` directory structure
- Optionally configure Docker/Sail for Deepwiki

## Configuration

Add the following to your `.env` file:

```env
# Required
OPENAI_API_KEY=sk-your-api-key
WARDEN_SHARED_KEY=your-secure-random-key

# Optional
WARDEN_DEEPWIKI_URL=http://localhost:8001
WARDEN_REPO_NAME=myapp
```

Register the MCP server in `config/mcp.php`:

```php
'servers' => [
    'warden' => App\Mcp\Servers\WardenServer::class,
],
```

## Quick Start

1. **Start Deepwiki**

   ```bash
   # With Laravel Sail
   sail up -d deepwiki

   # With standalone Docker
   docker compose -f docker-compose.warden.yml up -d
   ```

2. **Index your codebase**

   ```bash
   php artisan warden:reindex
   ```

3. **Connect your MCP client**

   Configure your AI assistant (ChatGPT, Cursor, etc.) to use:
   - Endpoint: `http://your-app.com/mcp/warden`
   - Header: `X-Warden-Key: your-warden-shared-key`

## MCP Tools

Warden provides these MCP tools for AI assistants:

| Tool | Description |
|------|-------------|
| `deepwiki.ask` | Ask questions about the codebase |
| `deepwiki.ask_history` | Ask questions about commit history |
| `deepwiki.reindex_project` | Trigger a full reindex |
| `deepwiki.list_projects` | List all indexed branches |

### Example Usage

```json
{
  "tool": "deepwiki.ask",
  "input": {
    "repo": "myapp",
    "branch": "main",
    "question": "How does user authentication work?"
  }
}
```

## Commands

```bash
# Install Warden
php artisan warden:install

# Index a branch
php artisan warden:reindex          # Current branch
php artisan warden:reindex main     # Specific branch
php artisan warden:reindex --force  # Force reindex

# Check status
php artisan warden:status
```

## Directory Structure

Warden stores all data in the `.warden/` directory:

```
.warden/
├── worktrees/<branch>/        # Git worktree checkouts
├── <branch>/database/         # Branch-specific staging DBs
├── <branch>/index/            # FAISS vector indexes
└── <branch>/history/          # Git commit history for RAG
```

## MCP Client Configuration

### ChatGPT

1. Add a new MCP server in ChatGPT settings
2. Set URL: `https://your-app.com/mcp/warden`
3. Add header: `X-Warden-Key: your-key`

### Cursor

Add to `.cursor/mcp.json`:

```json
{
  "servers": {
    "warden": {
      "url": "http://localhost:8000/mcp/warden",
      "headers": {
        "X-Warden-Key": "your-key"
      }
    }
  }
}
```

### Windsurf

Configure in Windsurf settings with the MCP endpoint and authentication header.

## Security

- **Shared Key**: The `WARDEN_SHARED_KEY` provides basic authentication
- **Localhost Only**: Deepwiki binds to `127.0.0.1` by default
- **Production**: Use stronger authentication in production environments

## Documentation

- [AGENTS.md](AGENTS.md) - Full AI integration documentation
- [.cursor/rules/warden.mdc](.cursor/rules/warden.mdc) - Cursor-specific rules

## Testing

```bash
composer test
```

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.