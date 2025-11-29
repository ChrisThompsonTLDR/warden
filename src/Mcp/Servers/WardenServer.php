<?php

namespace Warden\Mcp\Servers;

use Laravel\Mcp\Server\McpServer;
use Warden\Mcp\Tools\DeepwikiAskHistoryTool;
use Warden\Mcp\Tools\DeepwikiAskTool;
use Warden\Mcp\Tools\DeepwikiListProjectsTool;
use Warden\Mcp\Tools\DeepwikiReindexTool;

class WardenServer extends McpServer
{
    /**
     * The server name.
     */
    protected string $name = 'warden';

    /**
     * The server version.
     */
    protected string $version = '1.0.0';

    /**
     * The server description.
     */
    protected string $description = 'Warden MCP Server - Deepwiki integration for AI-powered codebase analysis';

    /**
     * Get the tools provided by this server.
     */
    public function tools(): array
    {
        return [
            DeepwikiAskTool::class,
            DeepwikiAskHistoryTool::class,
            DeepwikiReindexTool::class,
            DeepwikiListProjectsTool::class,
        ];
    }

    /**
     * Get the server capabilities.
     */
    public function capabilities(): array
    {
        return [
            'tools' => [
                'listChanged' => true,
            ],
        ];
    }
}
