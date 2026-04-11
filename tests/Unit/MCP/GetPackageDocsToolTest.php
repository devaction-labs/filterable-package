<?php

namespace Tests\Unit\MCP;

use DevactionLabs\FilterablePackage\MCP\Tools\GetPackageDocsTool;

it('returns docs containing all filter methods', function (): void {
    $tool = new GetPackageDocsTool;
    $result = $tool->execute([]);

    expect($result)
        ->toContain('Filter::exact')
        ->toContain('Filter::like')
        ->toContain('Filter::ilike')
        ->toContain('Filter::in')
        ->toContain('Filter::between')
        ->toContain('Filter::relationship')
        ->toContain('Filter::fullText')
        ->toContain('Filter::json')
        ->toContain('customPaginate');
});

it('has correct name and description', function (): void {
    $tool = new GetPackageDocsTool;

    expect($tool->name())->toBe('get_package_docs')
        ->and($tool->description())->not->toBeEmpty();
});

it('has valid input schema', function (): void {
    $tool = new GetPackageDocsTool;
    $schema = $tool->inputSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBeArray()->toBeEmpty();
});
