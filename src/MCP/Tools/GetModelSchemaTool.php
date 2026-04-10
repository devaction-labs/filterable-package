<?php

namespace DevactionLabs\FilterablePackage\MCP\Tools;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class GetModelSchemaTool implements Tool
{
    public function name(): string
    {
        return 'get_model_schema';
    }

    public function description(): string
    {
        return 'Returns the database schema of an Eloquent model: table name, columns with types, casts, fillable fields, and Eloquent relationships. Use this to understand what data a model holds before generating filters.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'Fully qualified class name (e.g. App\\Models\\User) or short name (e.g. User)',
                ],
            ],
            'required' => ['model'],
        ];
    }

    public function execute(array $args): string
    {
        $class = $this->resolveClass($args['model'] ?? '');

        if ($class === null) {
            return "Model '{$args['model']}' not found. Use list_filterable_models to see available models.";
        }

        /** @var Model $instance */
        $instance = new $class;
        $table = $instance->getTable();

        $lines = [
            "Model: {$class}",
            "Table: {$table}",
            '',
        ];

        $columns = $this->getColumns($table);
        $casts = $instance->getCasts();

        if ($columns !== []) {
            $lines[] = 'Columns:';
            foreach ($columns as $column => $type) {
                $cast = $casts[$column] ?? null;
                $castSuffix = $cast ? " (cast: {$cast})" : '';
                $nullable = $this->isNullable($table, $column) ? ' [nullable]' : '';
                $lines[] = "  {$column}: {$type}{$castSuffix}{$nullable}";
            }
            $lines[] = '';
        }

        $fillable = $instance->getFillable();
        if ($fillable !== []) {
            $lines[] = 'Fillable: '.implode(', ', $fillable);
            $lines[] = '';
        }

        $relationships = $this->getRelationships($class, $instance);
        if ($relationships !== []) {
            $lines[] = 'Relationships:';
            foreach ($relationships as $name => $type) {
                $lines[] = "  {$name}: {$type}";
            }
            $lines[] = '';
        }

        $lines[] = 'Run generate_filters(model) to get a ready-to-use filter array for this model.';

        return implode("\n", $lines);
    }

    private function resolveClass(string $model): ?string
    {
        if ($model === '') {
            return null;
        }

        if (class_exists($model)) {
            return $model;
        }

        $candidates = [
            "App\\Models\\{$model}",
            "App\\{$model}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function getColumns(string $table): array
    {
        try {
            $columns = Schema::getColumnListing($table);
            $types = [];
            foreach ($columns as $column) {
                $types[$column] = Schema::getColumnType($table, $column);
            }

            return $types;
        } catch (Throwable) {
            return [];
        }
    }

    private function isNullable(string $table, string $column): bool
    {
        try {
            return ! Schema::getColumns($table)[$column]['nullable'] === false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    private function getRelationships(string $class, Model $instance): array
    {
        $relationships = [];
        $reflector = new ReflectionClass($class);

        foreach ($reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->getNumberOfParameters() > 0) {
                continue;
            }

            try {
                $result = $method->invoke($instance);
                if ($result instanceof Relation) {
                    $relationships[$method->getName()] = class_basename($result);
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $relationships;
    }
}
