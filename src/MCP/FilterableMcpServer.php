<?php

namespace DevactionLabs\FilterablePackage\MCP;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use DevactionLabs\FilterablePackage\MCP\Tools\GenerateFiltersTool;
use DevactionLabs\FilterablePackage\MCP\Tools\GetModelSchemaTool;
use DevactionLabs\FilterablePackage\MCP\Tools\GetPackageDocsTool;
use DevactionLabs\FilterablePackage\MCP\Tools\ListFilterableModelsTool;
use Throwable;

class FilterableMcpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private const SERVER_NAME = 'filterable-package';

    private const SERVER_VERSION = '1.0.0';

    /** @var Tool[] */
    private array $tools;

    public function __construct()
    {
        $this->tools = [
            new GetPackageDocsTool,
            new ListFilterableModelsTool,
            new GetModelSchemaTool,
            new GenerateFiltersTool,
        ];
    }

    public function run(): void
    {
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);

            if (! is_array($request)) {
                continue;
            }

            $response = $this->dispatch($request);

            if ($response !== []) {
                $this->write($response);
            }
        }
    }

    /** @return array<string, mixed> */
    private function dispatch(array $request): array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'notifications/initialized' => [],
            'ping' => $this->ok($id, new \stdClass),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolCall($id, $request['params'] ?? []),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    /** @return array<string, mixed> */
    private function handleInitialize(mixed $id): array
    {
        return $this->ok($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
    {
        return $this->ok($id, [
            'tools' => array_map(fn (Tool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ], $this->tools),
        ]);
    }

    /** @return array<string, mixed> */
    private function handleToolCall(mixed $id, array $params): array
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        foreach ($this->tools as $tool) {
            if ($tool->name() !== $name) {
                continue;
            }

            try {
                return $this->ok($id, [
                    'content' => [['type' => 'text', 'text' => $tool->execute($args)]],
                ]);
            } catch (Throwable $e) {
                return $this->error($id, -32000, $e->getMessage());
            }
        }

        return $this->error($id, -32601, "Tool not found: {$name}");
    }

    /** @return array<string, mixed> */
    private function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        fwrite(STDOUT, json_encode($data)."\n");
        fflush(STDOUT);
    }
}
