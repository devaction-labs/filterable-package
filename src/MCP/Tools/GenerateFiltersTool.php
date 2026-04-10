<?php

namespace DevactionLabs\FilterablePackage\MCP\Tools;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class GenerateFiltersTool implements Tool
{
    private const TEXT_TYPES = ['string', 'text', 'char', 'varchar', 'mediumtext', 'longtext', 'tinytext'];

    private const DATE_TYPES = ['date', 'datetime', 'timestamp', 'timestamptz', 'datetimetz'];

    private const INTEGER_TYPES = ['integer', 'bigint', 'smallint', 'tinyint', 'int'];

    private const DECIMAL_TYPES = ['decimal', 'float', 'double', 'numeric'];

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
        $class = $this->resolveClass($args['model'] ?? '');

        if ($class === null) {
            return "Model '{$args['model']}' not found. Use list_filterable_models to see available models.";
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
            return "Could not generate filters for {$class}. Try calling get_model_schema('{$shortClass}') to inspect the model first.";
        }

        $filterLines = array_map(fn (string $f) => "    {$f}", $filters);
        $filterCode = implode("\n", $filterLines);

        $output = [];
        $output[] = "// Filters for {$shortClass}";
        $output[] = '// Add to your controller: use DevactionLabs\\FilterablePackage\\Filter;';
        $output[] = '';
        $output[] = "\${$this->varName($shortClass)} = {$shortClass}::filterable([";
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
                str_ends_with($column, '_at') => "Filter::between('{$column}')->castDate(), // date range",
                default => null,
            };
        }

        if (str_ends_with($column, '_id')) {
            return "Filter::exact('{$column}'), // foreign key exact match";
        }

        if (in_array($column, ['status', 'type', 'state', 'role', 'gender', 'category'], true) || str_starts_with($column, 'is_') || str_starts_with($column, 'has_')) {
            return "Filter::in('{$column}'), // accepts comma-separated values: ?filter[{$column}]=val1,val2";
        }

        $resolvedType = $cast ?? $type;

        if (in_array($resolvedType, self::DATE_TYPES, true) || $resolvedType === 'datetime' || $resolvedType === 'date') {
            return "Filter::between('{$column}')->castDate(), // date range: ?filter[{$column}]=2024-01-01,2024-12-31";
        }

        if (in_array($resolvedType, self::TEXT_TYPES, true)) {
            if (str_contains($column, 'email')) {
                return "Filter::ilike('{$column}'), // case-insensitive search";
            }

            if (str_contains($column, 'name') || str_contains($column, 'title') || str_contains($column, 'description') || str_contains($column, 'slug') || str_contains($column, 'bio')) {
                return "Filter::ilike('{$column}'), // case-insensitive text search";
            }

            return "Filter::exact('{$column}'),";
        }

        if (in_array($resolvedType, self::INTEGER_TYPES, true)) {
            return "Filter::between('{$column}'), // numeric range: ?filter[{$column}]=min,max";
        }

        if (in_array($resolvedType, self::DECIMAL_TYPES, true)) {
            return "Filter::between('{$column}'), // decimal range: ?filter[{$column}]=min,max";
        }

        if ($resolvedType === 'boolean') {
            return "Filter::exact('{$column}'), // boolean: ?filter[{$column}]=1 or 0";
        }

        if ($resolvedType === 'json' || $resolvedType === 'array' || $resolvedType === 'object') {
            return "// Filter::json('{$column}', 'nested.key'), // JSON field - specify path";
        }

        return null;
    }

    private function filterForRelationship(string $relation, string $type): string
    {
        $eagerLoad = in_array($type, ['HasOne', 'BelongsTo'], true) ? '->with()' : '';

        return "Filter::relationship('{$relation}', 'id'){$eagerLoad}, // filter by {$relation} relationship";
    }

    private function resolveClass(string $model): ?string
    {
        if ($model === '') {
            return null;
        }

        if (class_exists($model)) {
            return $model;
        }

        foreach (["App\\Models\\{$model}", "App\\{$model}"] as $candidate) {
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

    private function varName(string $class): string
    {
        return lcfirst($class).'s';
    }

    private function routeName(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class)).'s';
    }

    /** @return array<int, string> */
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
                $params[] = "filter[{$column}]=example";
                $count++;
            } elseif (in_array($resolvedType, self::DATE_TYPES, true) || str_ends_with($column, '_at')) {
                $params[] = "filter[{$column}]=2024-01-01,2024-12-31";
                $count++;
            }
        }

        return $params;
    }
}
