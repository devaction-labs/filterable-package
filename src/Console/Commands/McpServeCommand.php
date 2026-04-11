<?php

declare(strict_types=1);

namespace DevactionLabs\FilterablePackage\Console\Commands;

use DevactionLabs\FilterablePackage\MCP\FilterableMcpServer;
use Illuminate\Console\Command;

class McpServeCommand extends Command
{
    /** @var string */
    protected $signature = 'filterable:mcp';

    /** @var string */
    protected $description = 'Start the Filterable MCP server for AI agent integration (stdio transport)';

    public function handle(): void
    {
        (new FilterableMcpServer)->run();
    }
}
