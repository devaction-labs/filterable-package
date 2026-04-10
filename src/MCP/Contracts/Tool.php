<?php

namespace DevactionLabs\FilterablePackage\MCP\Contracts;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function inputSchema(): array;

    /** @param array<string, mixed> $args */
    public function execute(array $args): string;
}
