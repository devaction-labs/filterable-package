<?php

namespace DevactionLabs\FilterablePackage\MCP;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use DevactionLabs\FilterablePackage\MCP\Tools\GenerateFiltersTool;
use DevactionLabs\FilterablePackage\MCP\Tools\GetModelSchemaTool;
use DevactionLabs\FilterablePackage\MCP\Tools\GetPackageDocsTool;
use DevactionLabs\FilterablePackage\MCP\Tools\ListFilterableModelsTool;
use stdClass;
use Throwable;

class FilterableMcpServer
{
    private const string PROTOCOL_VERSION = '2025-06-18';

    /** @var array<int, string> Protocol revisions this server can speak. */
    private const array SUPPORTED_PROTOCOL_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];

    private const string SERVER_NAME = 'filterable-package';

    private const string SERVER_VERSION = '2.3.0';

    /** @var Tool[] */
    private readonly array $tools;

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

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $request */
            $request = $decoded;

            $response = $this->dispatch($request);

            // A JSON-RPC notification (no "id" member) must never receive a
            // response, not even an error one.
            if (array_key_exists('id', $request) && $response !== []) {
                $this->write($response);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function dispatch(array $request): array
    {
        $id = $request['id'] ?? null;
        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';

        return match ($method) {
            'initialize' => $this->handleInitialize($id, isset($request['params']) && is_array($request['params']) ? $request['params'] : []),
            'notifications/initialized' => [],
            'ping' => $this->ok($id, new stdClass),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolCall($id, isset($request['params']) && is_array($request['params']) ? $request['params'] : []),
            default => $this->error($id, -32601, 'Method not found: '.$method),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleInitialize(mixed $id, array $params): array
    {
        $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion']) ? $params['protocolVersion'] : '';
        $version = in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSION;

        return $this->ok($id, [
            'protocolVersion' => $version,
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

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleToolCall(mixed $id, array $params): array
    {
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

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

        return $this->error($id, -32601, 'Tool not found: '.$name);
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
