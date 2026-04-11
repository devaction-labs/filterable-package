<?php

declare(strict_types=1);

namespace Tests\Unit\MCP;

use DevactionLabs\FilterablePackage\MCP\Tools\GenerateFiltersTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

// Minimal Eloquent model for testing — no DB connection needed
class GenerateTestModel extends Model
{
    protected $table = 'generate_test_models';

    protected $casts = ['created_at' => 'datetime', 'is_active' => 'boolean'];
}

// Helpers to access private methods via reflection
function callFilterForColumn(GenerateFiltersTool $tool, string $column, string $type, ?string $cast): ?string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('filterForColumn');

    return $method->invoke($tool, $column, $type, $cast);
}

function callFilterForRelationship(GenerateFiltersTool $tool, string $relation, string $type): string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('filterForRelationship');

    return $method->invoke($tool, $relation, $type);
}

function callVarName(GenerateFiltersTool $tool, string $class): string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('varName');

    return $method->invoke($tool, $class);
}

function callRouteName(GenerateFiltersTool $tool, string $class): string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('routeName');

    return $method->invoke($tool, $class);
}

function callBuildExampleParams(GenerateFiltersTool $tool, array $columns, array $casts): array
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('buildExampleParams');

    return $method->invoke($tool, $columns, $casts);
}

function callResolveClass(GenerateFiltersTool $tool, string $model): ?string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('resolveClass');

    return $method->invoke($tool, $model);
}

// Tool metadata
it('has correct name', function (): void {
    expect((new GenerateFiltersTool)->name())->toBe('generate_filters');
});

it('has non-empty description', function (): void {
    expect((new GenerateFiltersTool)->description())->not->toBeEmpty();
});

it('has valid input schema with model property', function (): void {
    $schema = (new GenerateFiltersTool)->inputSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('model')
        ->and($schema['required'])->toContain('model');
});

// execute() error path (no DB needed)
it('returns error message when model is not found', function (): void {
    $result = (new GenerateFiltersTool)->execute(['model' => 'NonExistentModel99']);

    expect($result)->toContain('NonExistentModel99')
        ->toContain('not found');
});

it('returns error message when model arg is empty', function (): void {
    $result = (new GenerateFiltersTool)->execute(['model' => '']);

    expect($result)->toContain('not found');
});

// resolveClass
it('resolveClass returns null for empty string', function (): void {
    expect(callResolveClass(new GenerateFiltersTool, ''))->toBeNull();
});

it('resolveClass returns null for unknown class', function (): void {
    expect(callResolveClass(new GenerateFiltersTool, 'App\\Models\\DoesNotExist'))->toBeNull();
});

it('resolveClass returns class when it exists', function (): void {
    expect(callResolveClass(new GenerateFiltersTool, GenerateFiltersTool::class))
        ->toBe(GenerateFiltersTool::class);
});

// filterForColumn — system columns
it('filterForColumn returns null for id', function (): void {
    expect(callFilterForColumn(new GenerateFiltersTool, 'id', 'integer', null))->toBeNull();
});

it('filterForColumn returns null for deleted_at', function (): void {
    expect(callFilterForColumn(new GenerateFiltersTool, 'deleted_at', 'datetime', null))->toBeNull();
});

it('filterForColumn returns null for remember_token', function (): void {
    expect(callFilterForColumn(new GenerateFiltersTool, 'remember_token', 'string', null))->toBeNull();
});

it('filterForColumn returns null for password', function (): void {
    expect(callFilterForColumn(new GenerateFiltersTool, 'password', 'string', null))->toBeNull();
});

it('filterForColumn returns date range for created_at', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'created_at', 'datetime', null);

    expect($result)->toContain('Filter::between')->toContain('castDate');
});

it('filterForColumn returns date range for updated_at', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'updated_at', 'datetime', null);

    expect($result)->toContain('Filter::between')->toContain('castDate');
});

// filterForColumn — foreign keys
it('filterForColumn returns exact for _id columns', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'user_id', 'integer', null);

    expect($result)->toContain("Filter::exact('user_id')");
});

// filterForColumn — enum-like columns
it('filterForColumn returns in() for status column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'status', 'string', null);

    expect($result)->toContain("Filter::in('status')");
});

it('filterForColumn returns in() for type column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'type', 'string', null);

    expect($result)->toContain("Filter::in('type')");
});

it('filterForColumn returns in() for is_ prefix columns', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'is_active', 'boolean', null);

    expect($result)->toContain("Filter::in('is_active')");
});

it('filterForColumn returns in() for has_ prefix columns', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'has_photo', 'boolean', null);

    expect($result)->toContain("Filter::in('has_photo')");
});

// filterForColumn — text types
it('filterForColumn returns ilike for email column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'email', 'string', null);

    expect($result)->toContain("Filter::ilike('email')");
});

it('filterForColumn returns ilike for name column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'name', 'string', null);

    expect($result)->toContain("Filter::ilike('name')");
});

it('filterForColumn returns ilike for title column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'title', 'string', null);

    expect($result)->toContain("Filter::ilike('title')");
});

it('filterForColumn returns ilike for description column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'description', 'text', null);

    expect($result)->toContain("Filter::ilike('description')");
});

it('filterForColumn returns ilike for slug column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'slug', 'string', null);

    expect($result)->toContain("Filter::ilike('slug')");
});

it('filterForColumn returns ilike for bio column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'bio', 'text', null);

    expect($result)->toContain("Filter::ilike('bio')");
});

it('filterForColumn returns exact for generic text column', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'token', 'string', null);

    expect($result)->toContain("Filter::exact('token')");
});

// filterForColumn — date/datetime types
it('filterForColumn returns between with castDate for date type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'birth_date', 'date', null);

    expect($result)->toContain('Filter::between')->toContain('castDate');
});

it('filterForColumn returns between with castDate for timestamp type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'published_at', 'timestamp', null);

    expect($result)->toContain('Filter::between')->toContain('castDate');
});

// filterForColumn — numeric types
it('filterForColumn returns between for integer type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'age', 'integer', null);

    expect($result)->toContain("Filter::between('age')");
});

it('filterForColumn returns between for bigint type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'views', 'bigint', null);

    expect($result)->toContain("Filter::between('views')");
});

it('filterForColumn returns between for decimal type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'price', 'decimal', null);

    expect($result)->toContain("Filter::between('price')");
});

it('filterForColumn returns between for float type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'rating', 'float', null);

    expect($result)->toContain("Filter::between('rating')");
});

// filterForColumn — boolean type
it('filterForColumn returns exact for boolean type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'active', 'boolean', null);

    expect($result)->toContain("Filter::exact('active')");
});

// filterForColumn — json type
it('filterForColumn returns json comment for json type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'settings', 'json', null);

    expect($result)->toContain("Filter::json('settings'");
});

it('filterForColumn returns json comment for array cast', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'meta', 'json', 'array');

    expect($result)->toContain("Filter::json('meta'");
});

// filterForColumn — cast overrides type
it('filterForColumn uses cast over raw type for date', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'scheduled_for', 'string', 'datetime');

    expect($result)->toContain('castDate');
});

// filterForColumn — unknown type returns null
it('filterForColumn returns null for unknown type', function (): void {
    $result = callFilterForColumn(new GenerateFiltersTool, 'unknown_col', 'geometry', null);

    expect($result)->toBeNull();
});

// filterForRelationship
it('filterForRelationship generates relationship filter without eager load for HasMany', function (): void {
    $result = callFilterForRelationship(new GenerateFiltersTool, 'posts', 'HasMany');

    expect($result)->toContain("Filter::relationship('posts', 'id')")
        ->not->toContain('->with()');
});

it('filterForRelationship adds with() for BelongsTo', function (): void {
    $result = callFilterForRelationship(new GenerateFiltersTool, 'category', 'BelongsTo');

    expect($result)->toContain("Filter::relationship('category', 'id')")->toContain('->with()');
});

it('filterForRelationship adds with() for HasOne', function (): void {
    $result = callFilterForRelationship(new GenerateFiltersTool, 'profile', 'HasOne');

    expect($result)->toContain('->with()');
});

// varName
it('varName lowercases first letter and appends s', function (): void {
    expect(callVarName(new GenerateFiltersTool, 'User'))->toBe('users');
});

it('varName handles multi-word class names', function (): void {
    expect(callVarName(new GenerateFiltersTool, 'BlogPost'))->toBe('blogPosts');
});

// routeName
it('routeName converts CamelCase to kebab-case with plural', function (): void {
    expect(callRouteName(new GenerateFiltersTool, 'User'))->toBe('users');
});

it('routeName handles multi-word class names', function (): void {
    expect(callRouteName(new GenerateFiltersTool, 'BlogPost'))->toBe('blog-posts');
});

// buildExampleParams
it('buildExampleParams returns text params for string columns', function (): void {
    $params = callBuildExampleParams(new GenerateFiltersTool, ['name' => 'string'], []);

    expect($params)->toContain('filter[name]=example');
});

it('buildExampleParams returns date params for date columns', function (): void {
    $params = callBuildExampleParams(new GenerateFiltersTool, ['created_at' => 'datetime'], []);

    expect($params)->toContain('filter[created_at]=2024-01-01,2024-12-31');
});

it('buildExampleParams limits to 3 params', function (): void {
    $columns = [
        'name' => 'string',
        'email' => 'string',
        'title' => 'string',
        'bio' => 'string',
    ];

    expect(callBuildExampleParams(new GenerateFiltersTool, $columns, []))->toHaveCount(3);
});

it('buildExampleParams skips password and id columns', function (): void {
    $params = callBuildExampleParams(new GenerateFiltersTool, ['id' => 'integer', 'password' => 'string', 'name' => 'string'], []);

    $joined = implode('&', $params);

    expect($joined)->not->toContain('filter[id]')
        ->not->toContain('filter[password]')
        ->toContain('filter[name]');
});

// execute() with mocked Schema — covers the generate loop + output building
it('execute() generates filter code for a model with mocked columns', function (): void {
    Schema::clearResolvedInstances();

    Schema::shouldReceive('getColumnListing')
        ->with('generate_test_models')
        ->andReturn(['id', 'name', 'status', 'price', 'created_at']);

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'id')->andReturn('integer');

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'name')->andReturn('string');

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'status')->andReturn('string');

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'price')->andReturn('decimal');

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'created_at')->andReturn('datetime');

    $result = (new GenerateFiltersTool)->execute(['model' => GenerateTestModel::class]);

    expect($result)
        ->toContain('Filter::ilike')
        ->toContain('Filter::in')
        ->toContain('Filter::between')
        ->toContain('customPaginate')
        ->toContain('GenerateTestModel::filterable');
});

it('execute() includes skipped columns comment for uncategorised types', function (): void {
    Schema::clearResolvedInstances();

    // 'name' generates a filter; 'geo_point' is unknown → ends up in skipped list
    Schema::shouldReceive('getColumnListing')
        ->with('generate_test_models')
        ->andReturn(['name', 'geo_point']);

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'name')->andReturn('string');

    Schema::shouldReceive('getColumnType')
        ->with('generate_test_models', 'geo_point')->andReturn('geometry');

    $result = (new GenerateFiltersTool)->execute(['model' => GenerateTestModel::class]);

    expect($result)->toContain('geo_point')
        ->toContain('Columns without auto-generated filters');
});
