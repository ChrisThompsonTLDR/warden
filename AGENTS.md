# AGENTS.md - Warden AI Integration Documentation

This document describes the AI agents system in this project, including how Deepwiki-Open integrates with Laravel's MCP (Model Context Protocol) to provide AI-powered codebase analysis.

## Overview

**Warden** is a Laravel package that integrates [Deepwiki-Open](https://github.com/AsyncFuncAI/deepwiki-open) with [laravel/mcp](https://github.com/laravel/mcp) to enable AI assistants (ChatGPT, Claude, Cursor, Windsurf, etc.) to query and analyze your codebase.

### Key Features

- 🔍 **Branch-aware indexing**: Each Git branch gets its own isolated index
- 🗄️ **Per-branch staging databases**: SQLite databases for deterministic analysis
- 📚 **Git history RAG**: Query commit history alongside code content
- 🔐 **Shared key authentication**: Protect MCP endpoints with a simple shared secret
- 🐳 **Docker integration**: Easy setup with Laravel Sail or standalone Docker

## Architecture

```
.warden/
├── main/
│   ├── database/
│   │   └── staging.sqlite   # Branch-specific staging database
│   ├── index/               # FAISS vector index for this branch
│   └── history/
│       ├── commits.jsonl    # Commit history in JSONL format
│       └── commits.md       # Human-readable commit history
├── develop/
│   ├── database/
│   ├── index/
│   └── history/
└── .gitignore
```

### Branch Naming

Branch names are sanitized for filesystem compatibility:
- `main` → `main`
- `develop` → `develop`
- `feature/2fa` → `feature-2fa`
- `release/v1.0.0` → `release-v1-0-0`

### Project IDs

Deepwiki project IDs follow the convention: `<repo>-<branch>`
- `myapp-main`
- `myapp-develop`
- `myapp-feature-2fa`

## MCP Tools

Warden exposes the following MCP tools for AI assistants:

### `deepwiki.ask`

Ask questions about the codebase for a specific branch.

**Input Schema:**
```json
{
  "repo": "string (required)",
  "branch": "string (required)",
  "question": "string (required)",
  "model": "string (optional, default: gpt-4o-mini)"
}
```

**Example:**
```json
{
  "repo": "myapp",
  "branch": "main",
  "question": "How does user authentication work?"
}
```

### `deepwiki.ask_history`

Ask questions about Git commit history for a specific branch.

**Input Schema:**
```json
{
  "repo": "string (required)",
  "branch": "string (required)",
  "question": "string (required)",
  "author": "string (optional)",
  "since": "string (optional, ISO 8601 date)",
  "until": "string (optional, ISO 8601 date)",
  "path": "string (optional, file path pattern)"
}
```

**Example:**
```json
{
  "repo": "myapp",
  "branch": "main",
  "question": "When was 2FA implemented?",
  "path": "app/Http/Controllers/Auth"
}
```

### `deepwiki.reindex_project`

Trigger a full reindex of the codebase for a specific branch.

**Input Schema:**
```json
{
  "repo": "string (required)",
  "branch": "string (required)",
  "force": "boolean (optional, default: false)",
  "skipHistory": "boolean (optional, default: false)"
}
```

### `deepwiki.list_projects`

List all indexed branches and their status.

**Input Schema:**
```json
{
  "repo": "string (optional)"
}
```

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# Required: OpenAI API key for embeddings and completions
OPENAI_API_KEY=sk-...

# Required: Shared secret for MCP authentication
WARDEN_SHARED_KEY=your-secure-random-key

# Optional: Deepwiki server URL (default: http://localhost:8001)
WARDEN_DEEPWIKI_URL=http://localhost:8001

# Optional: Repository name (default: directory name)
WARDEN_REPO_NAME=myapp

# Optional: Embeddings model (default: text-embedding-3-small)
WARDEN_EMBEDDINGS_MODEL=text-embedding-3-small

# Optional: Enable/disable MCP server (default: true)
WARDEN_MCP_ENABLED=true

# Optional: MCP endpoint path (default: /mcp/warden)
WARDEN_MCP_PATH=/mcp/warden
```

### MCP Server Registration

Register the Warden MCP server in `config/mcp.php`:

```php
'servers' => [
    'warden' => App\Mcp\Servers\WardenServer::class,
],
```

## Deepwiki Integration

### Docker Setup

Deepwiki-Open runs as a local Docker container:

```yaml
# docker-compose.warden.yml
services:
  deepwiki:
    image: ghcr.io/asyncfuncai/deepwiki-open:latest
    ports:
      - "127.0.0.1:8001:8001"  # Localhost only!
    environment:
      - OPENAI_API_KEY=${OPENAI_API_KEY}
    volumes:
      - ./.warden:/app/data
```

**Security Note:** Deepwiki binds to `127.0.0.1` only. Never expose it to the internet.

### Starting Deepwiki

```bash
# With Laravel Sail
sail up -d deepwiki

# With standalone Docker
docker compose -f docker-compose.warden.yml up -d
```

### Indexing Scope

When Deepwiki indexes the repository, it includes:

- ✅ Application source code
- ✅ `vendor/` (dependencies)
- ✅ `packages/` (local packages)
- ✅ `.aim/` (AI agent configs)
- ✅ `.taskmaster/` (task configs)
- ✅ `.cursor/` (editor rules)

It excludes:

- ❌ `node_modules/`
- ❌ `.git/`
- ❌ `storage/logs/`
- ❌ `storage/framework/cache/`
- ❌ `bootstrap/cache/`

## MCP Client Configuration

### ChatGPT

Configure ChatGPT to use the MCP server:

1. Set the MCP endpoint URL: `https://your-app.com/mcp/warden`
2. Add the `X-Warden-Key` header with your `WARDEN_SHARED_KEY`

### Cursor

Add to `.cursor/mcp.json`:

```json
{
  "servers": {
    "warden": {
      "url": "http://localhost:8000/mcp/warden",
      "headers": {
        "X-Warden-Key": "your-warden-shared-key"
      }
    }
  }
}
```

### Windsurf

Configure in Windsurf settings:

```json
{
  "mcp": {
    "servers": {
      "warden": {
        "endpoint": "http://localhost:8000/mcp/warden",
        "auth": {
          "type": "header",
          "name": "X-Warden-Key",
          "value": "your-warden-shared-key"
        }
      }
    }
  }
}
```

## Authentication

The `WARDEN_SHARED_KEY` provides basic authentication for MCP requests.

### How It Works

The middleware accepts the key via:
1. `X-Warden-Key` header (preferred)
2. `warden_key` query parameter
3. Bearer token

### Security Considerations

- **Local development**: The shared key prevents accidental exposure
- **Production**: Replace with stronger authentication (OAuth, API keys, etc.)
- **Never commit**: Add `WARDEN_SHARED_KEY` to `.env`, never to version control

## Commands

### `php artisan warden:install`

Install and configure Warden:

```bash
php artisan warden:install
php artisan warden:install --force        # Overwrite existing files
php artisan warden:install --skip-docker  # Skip Docker setup
```

### `php artisan warden:reindex`

Index current git branch: create staging DB, extract history, and trigger Deepwiki reindex:

```bash
php artisan warden:reindex              # Index current branch
php artisan warden:reindex --force      # Force reindex
php artisan warden:reindex --skip-history
php artisan warden:reindex --skip-migrations
```

**Note:** The command automatically detects the current git branch. To index a different branch, switch to it first: `git checkout <branch>` then run `php artisan warden:reindex`

### `php artisan warden:status`

Display Warden status and configuration:

```bash
php artisan warden:status
```

## Commit History RAG

Warden indexes Git commit history separately from code content:

### Data Structure

```jsonl
{"hash":"abc123","author":"John","date":"2024-01-15","subject":"Add 2FA","files":[...]}
{"hash":"def456","author":"Jane","date":"2024-01-14","subject":"Fix auth bug","files":[...]}
```

### Usage Notes

- **Code questions**: Use `deepwiki.ask` for questions about current code
- **History questions**: Use `deepwiki.ask_history` for questions about changes
- **Combined reasoning**: AI can use both tools to provide complete answers

### Example Questions

- "When did we first implement two-factor authentication?"
- "Which commits touched the payment module in the last month?"
- "Who has contributed most to the auth system?"
- "What changes were made to fix the login bug?"

## Troubleshooting

### Deepwiki Not Available

```
Deepwiki server is not available at http://localhost:8001
```

**Solution:**
```bash
docker compose -f docker-compose.warden.yml up -d
docker compose -f docker-compose.warden.yml logs deepwiki
```

### Branch Not Indexed

```
Branch 'feature/2fa' has not been indexed
```

**Solution:**
```bash
php artisan warden:reindex feature/2fa
```

### Authentication Failed

```
Invalid or missing Warden shared key
```

**Solution:**
1. Check `WARDEN_SHARED_KEY` in `.env`
2. Ensure MCP client sends the key via `X-Warden-Key` header

### Branch Not Indexed

```
Branch 'feature/2fa' has not been indexed
```

**Solution:**
```bash
# Switch to the branch you want to index
git checkout feature/2fa

# Then run reindex
php artisan warden:reindex
```

## Best Practices

1. **Index frequently**: Reindex after significant changes
2. **Use branch isolation**: Each branch has its own isolated environment (index, database, history)
3. **Switch branches to index**: To index a different branch, switch to it first with `git checkout`
4. **Commit small DBs**: For deterministic analysis, commit small staging DBs
5. **Rotate keys**: Change `WARDEN_SHARED_KEY` periodically
6. **Monitor logs**: Check Deepwiki logs for indexing issues

## Support

- **GitHub Issues**: [ChrisThompsonTLDR/warden](https://github.com/ChrisThompsonTLDR/warden/issues)
- **Documentation**: See this file and `.cursor/rules/warden.mdc`
