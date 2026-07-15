<?php

namespace DevactionLabs\FilterablePackage\MCP\Tools;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use DevactionLabs\FilterablePackage\MCP\Support\InspectsModel;
use Illuminate\Database\Eloquent\Model;

class GenerateFiltersTool implements Tool
{
    use InspectsModel;

    private const array TEXT_TYPES = ['string', 'text', 'char', 'varchar', 'mediumtext', 'longtext', 'tinytext'];

    private const array DATE_TYPES = ['date', 'datetime', 'timestamp', 'timestamptz', 'datetimetz'];

    private const array INTEGER_TYPES = ['integer', 'bigint', 'smallint', 'tinyint', 'int'];

    private const array DECIMAL_TYPES = ['decimal', 'float', 'double', 'numeric'];

    public function name(): string
    {
        return 'generate_filters';
    }

    public function description(): string
    {
        return 'Generates a ready-to-use PHP filter array for an Eloquent model using the filterable package. Returns PHP code that can be pasted directly into a controller or repository.';
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
        $shortClass = class_basename($class);

        $columns = $this->getColumns($table);
        $casts = $instance->getCasts();
        $relationships = $this->getRelationships($class, $instance);

        $filters = [];
        $skipped = [];

        foreach ($columns as $column => $type) {
            $cast = $casts[$column] ?? null;
            $filter = $this->filterForColumn($column, $type, $cast);

            if ($filter !== null) {
                $filters[] = $filter;
            } else {
                $skipped[] = $column;
            }
        }

        foreach ($relationships as $relation => $relationType) {
            $filters[] = $this->filterForRelationship($relation, $relationType);
        }

        if ($filters === []) {
            return sprintf("Could not generate filters for %s. Try calling get_model_schema('%s') to inspect the model first.", $class, $shortClass);
        }

        $filterLines = array_map(fn (string $f): string => '    '.$f, $filters);
        $filterCode = implode("\n", $filterLines);

        $output = [];
        $output[] = '// Filters for '.$shortClass;
        $output[] = '// Add to your controller: use DevactionLabs\\FilterablePackage\\Filter;';
        $output[] = '';
        $output[] = sprintf('$%s = %s::filterable([', $this->varName($shortClass), $shortClass);
        $output[] = $filterCode;
        $output[] = "])->customPaginate('paginate', 15);";

        if ($skipped !== []) {
            $output[] = '';
            $output[] = '// Columns without auto-generated filters (review manually):';
            $output[] = '// '.implode(', ', $skipped);
        }

        $output[] = '';
        $output[] = '// Request example:';
        $output[] = '// GET /'.$this->routeName($shortClass).'?'.implode('&', $this->buildExampleParams($columns, $casts));

        return implode("\n", $output);
    }

    private function filterForColumn(string $column, string $type, ?string $cast): ?string
    {
        if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password', 'email_verified_at'], true)) {
            return match (true) {
                $column === 'id' => null,
                $column === 'deleted_at' => null,
                $column === 'remember_token' => null,
                $column === 'password' => null,
                str_ends_with($column, '_at') => sprintf("Filter::between('%s')->castDate(), // date range", $column),
                default => null,
            };
        }

        if (str_ends_with($column, '_id')) {
            return sprintf("Filter::exact('%s'), // foreign key exact match", $column);
        }

        if (in_array($column, ['status', 'type', 'state', 'role', 'gender', 'category'], true) || str_starts_with($column, 'is_') || str_starts_with($column, 'has_')) {
            return sprintf("Filter::in('%s'), // accepts comma-separated values: ?filter[%s]=val1,val2", $column, $column);
        }

        $resolvedType = $cast ?? $type;

        if (in_array($resolvedType, self::DATE_TYPES, true) || $resolvedType === 'datetime' || $resolvedType === 'date') {
            return sprintf("Filter::between('%s')->castDate(), // date range: ?filter[%s]=2024-01-01,2024-12-31", $column, $column);
        }

        if (in_array($resolvedType, self::TEXT_TYPES, true)) {
            if (str_contains($column, 'email')) {
                return sprintf("Filter::ilike('%s'), // case-insensitive search", $column);
            }

            if (str_contains($column, 'name') || str_contains($column, 'title') || str_contains($column, 'description') || str_contains($column, 'slug') || str_contains($column, 'bio')) {
                return sprintf("Filter::ilike('%s'), // case-insensitive text search", $column);
            }

            return sprintf("Filter::exact('%s'),", $column);
        }

        if (in_array($resolvedType, self::INTEGER_TYPES, true)) {
            return sprintf("Filter::between('%s'), // numeric range: ?filter[%s]=min,max", $column, $column);
        }

        if (in_array($resolvedType, self::DECIMAL_TYPES, true)) {
            return sprintf("Filter::between('%s'), // decimal range: ?filter[%s]=min,max", $column, $column);
        }

        if ($resolvedType === 'boolean') {
            return sprintf("Filter::exact('%s'), // boolean: ?filter[%s]=1 or 0", $column, $column);
        }

        if (in_array($resolvedType, ['json', 'array', 'object'], true)) {
            return sprintf("// Filter::json('%s', 'nested.key'), // JSON field - specify path", $column);
        }

        return null;
    }

    private function filterForRelationship(string $relation, string $type): string
    {
        $eagerLoad = in_array($type, ['HasOne', 'BelongsTo'], true) ? '->with()' : '';

        return sprintf("Filter::relationship('%s', 'id')%s, // filter by %s relationship", $relation, $eagerLoad, $relation);
    }

    private function varName(string $class): string
    {
        return lcfirst($class).'s';
    }

    private function routeName(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class)).'s';
    }

    /**
     * @param  array<string, string>  $columns
     * @param  array<string, string>  $casts
     * @return array<int, string>
     */
    private function buildExampleParams(array $columns, array $casts): array
    {
        $params = [];
        $count = 0;

        foreach ($columns as $column => $type) {
            if ($count >= 3) {
                break;
            }

            if (in_array($column, ['id', 'password', 'remember_token', 'email_verified_at'], true)) {
                continue;
            }

            $resolvedType = $casts[$column] ?? $type;

            if (in_array($resolvedType, self::TEXT_TYPES, true)) {
                $params[] = sprintf('filter[%s]=example', $column);
                $count++;
            } elseif (in_array($resolvedType, self::DATE_TYPES, true) || str_ends_with($column, '_at')) {
                $params[] = sprintf('filter[%s]=2024-01-01,2024-12-31', $column);
                $count++;
            }
        }

        return $params;
    }
}
