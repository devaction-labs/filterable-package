<?php

namespace Tests\Unit\MCP;

use DevactionLabs\FilterablePackage\MCP\FilterableMcpServer;
use ReflectionClass;

function callDispatch(FilterableMcpServer $server, array $request): array
{
    $ref = new ReflectionClass($server);
    $method = $ref->getMethod('dispatch');

    return $method->invoke($server, $request);
}

it('responds to initialize with protocol version and server info', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);

    expect($response['result']['protocolVersion'])->toBe('2024-11-05')
        ->and($response['result']['serverInfo']['name'])->toBe('filterable-package')
        ->and($response['id'])->toBe(1);
});

it('lists four tools on tools/list', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);

    $names = array_column($response['result']['tools'], 'name');

    expect($names)->toContain('get_package_docs')
        ->toContain('list_filterable_models')
        ->toContain('get_model_schema')
        ->toContain('generate_filters');
});

it('returns empty array for notifications/initialized', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    expect($response)->toBe([]);
});

it('returns error for unknown method', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'unknown/method']);

    expect($response['error']['code'])->toBe(-32601)
        ->and($response['error']['message'])->toContain('unknown/method');
});

it('returns error for unknown tool call', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => ['name' => 'nonexistent_tool', 'arguments' => []],
    ]);

    expect($response['error']['code'])->toBe(-32601)
        ->and($response['error']['message'])->toContain('nonexistent_tool');
});

it('executes get_package_docs tool and returns docs', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, [
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/call',
        'params' => ['name' => 'get_package_docs', 'arguments' => []],
    ]);

    $text = $response['result']['content'][0]['text'];

    expect($text)
        ->toContain('Filter::exact')
        ->toContain('Filter::ilike')
        ->toContain('Filter::between')
        ->toContain('filterable');
});

it('responds to ping', function (): void {
    $server = new FilterableMcpServer;
    $response = callDispatch($server, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'ping']);

    expect($response['id'])->toBe(6)
        ->and($response)->toHaveKey('result');
});
