<?php

namespace DevactionLabs\FilterablePackage\MCP\Tools;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use DevactionLabs\FilterablePackage\MCP\Support\InspectsModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GetModelSchemaTool implements Tool
{
    use InspectsModel;

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
        $modelArg = isset($args['model']) && is_string($args['model']) ? $args['model'] : '';
        $class = $this->resolveClass($modelArg);

        if ($class === null) {
            return sprintf("Model '%s' not found. Use list_filterable_models to see available models.", $modelArg);
        }

        /** @var Model $instance */
        $instance = new $class;
        $table = $instance->getTable();

        $lines = [
            'Model: '.$class,
            'Table: '.$table,
            '',
        ];

        $columns = $this->getColumns($table);
        $casts = $instance->getCasts();

        if ($columns !== []) {
            $lines[] = 'Columns:';
            foreach ($columns as $column => $type) {
                $cast = $casts[$column] ?? null;
                $castSuffix = $cast ? sprintf(' (cast: %s)', $cast) : '';
                $nullable = $this->isNullable($table, $column) ? ' [nullable]' : '';
                $lines[] = sprintf('  %s: %s%s%s', $column, $type, $castSuffix, $nullable);
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
                $lines[] = sprintf('  %s: %s', $name, $type);
            }

            $lines[] = '';
        }

        $lines[] = 'Run generate_filters(model) to get a ready-to-use filter array for this model.';

        return implode("\n", $lines);
    }

    private function isNullable(string $table, string $column): bool
    {
        try {
            $columns = Schema::getColumns($table);
            foreach ($columns as $col) {
                if (is_array($col) && ($col['name'] ?? '') === $column) {
                    return (bool) ($col['nullable'] ?? false);
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }
}
