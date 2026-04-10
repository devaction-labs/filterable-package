<?php

namespace DevactionLabs\FilterablePackage\Console\Commands;

use DevactionLabs\FilterablePackage\MCP\FilterableMcpServer;
use Illuminate\Console\Command;

class McpServeCommand extends Command
{
    protected $signature = 'filterable:mcp';

    protected $description = 'Start the Filterable MCP server for AI agent integration (stdio transport)';

    public function handle(): void
    {
        (new FilterableMcpServer)->run();
    }
}
