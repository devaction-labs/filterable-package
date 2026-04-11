<?php

declare(strict_types=1);

namespace Tests\Unit\MCP;

use DevactionLabs\FilterablePackage\Console\Commands\McpServeCommand;

it('has correct artisan signature', function (): void {
    $command = new McpServeCommand;

    expect($command->getName())->toBe('filterable:mcp');
});

it('has a non-empty description', function (): void {
    $command = new McpServeCommand;

    expect($command->getDescription())->not->toBeEmpty();
});
