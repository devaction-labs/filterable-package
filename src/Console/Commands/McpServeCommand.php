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
        // STDOUT carries framed JSON-RPC only; route PHP error output to STDERR
        // so a stray warning/notice cannot corrupt the message stream.
        ini_set('display_errors', 'stderr');

        (new FilterableMcpServer)->run();
    }
}
