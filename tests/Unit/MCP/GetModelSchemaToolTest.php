<?php

declare(strict_types=1);

namespace Tests\Unit\MCP;

use DevactionLabs\FilterablePackage\MCP\Tools\GetModelSchemaTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

// Minimal Eloquent model for testing — no DB connection needed for getTable()/getCasts()
class SchemaTestModel extends Model
{
    protected $table = 'schema_test_models';

    protected $fillable = ['name', 'email'];

    protected $casts = ['created_at' => 'datetime'];
}

function callResolveModelClass(GetModelSchemaTool $tool, string $model): ?string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('resolveClass');

    return $method->invoke($tool, $model);
}

it('has correct name', function (): void {
    expect((new GetModelSchemaTool)->name())->toBe('get_model_schema');
});

it('has non-empty description', function (): void {
    expect((new GetModelSchemaTool)->description())->not->toBeEmpty();
});

it('has valid input schema with model property', function (): void {
    $schema = (new GetModelSchemaTool)->inputSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('model')
        ->and($schema['required'])->toContain('model');
});

it('returns error message when model is not found', function (): void {
    $result = (new GetModelSchemaTool)->execute(['model' => 'NonExistentModel99']);

    expect($result)->toContain('NonExistentModel99')
        ->toContain('not found');
});

it('returns error message when model arg is missing', function (): void {
    $result = (new GetModelSchemaTool)->execute([]);

    expect($result)->toContain('not found');
});

it('resolveClass returns null for empty string', function (): void {
    expect(callResolveModelClass(new GetModelSchemaTool, ''))->toBeNull();
});

it('resolveClass returns null for unknown class', function (): void {
    expect(callResolveModelClass(new GetModelSchemaTool, 'App\\Models\\Ghost'))->toBeNull();
});

it('resolveClass returns fully qualified class when it already exists', function (): void {
    expect(callResolveModelClass(new GetModelSchemaTool, GetModelSchemaTool::class))
        ->toBe(GetModelSchemaTool::class);
});

// execute() with mocked Schema — covers lines 49-91, 119-133, 136-148, 151-175
it('execute() returns schema info for a model with mocked columns', function (): void {
    Schema::clearResolvedInstances();

    Schema::shouldReceive('getColumnListing')
        ->with('schema_test_models')
        ->andReturn(['id', 'name', 'email', 'created_at']);

    Schema::shouldReceive('getColumnType')
        ->with('schema_test_models', 'id')->andReturn('integer');

    Schema::shouldReceive('getColumnType')
        ->with('schema_test_models', 'name')->andReturn('string');

    Schema::shouldReceive('getColumnType')
        ->with('schema_test_models', 'email')->andReturn('string');

    Schema::shouldReceive('getColumnType')
        ->with('schema_test_models', 'created_at')->andReturn('datetime');

    // isNullable uses Schema::getColumns
    Schema::shouldReceive('getColumns')
        ->with('schema_test_models')
        ->andReturn([
            ['name' => 'id', 'nullable' => false],
            ['name' => 'name', 'nullable' => true],
            ['name' => 'email', 'nullable' => false],
            ['name' => 'created_at', 'nullable' => true],
        ]);

    $result = (new GetModelSchemaTool)->execute(['model' => SchemaTestModel::class]);

    expect($result)
        ->toContain('Table: schema_test_models')
        ->toContain('Columns:')
        ->toContain('name: string')
        ->toContain('[nullable]')
        ->toContain('Fillable: name, email')
        ->toContain('generate_filters');
});

it('execute() returns schema without fillable section when model has no fillable', function (): void {
    Schema::clearResolvedInstances();

    Schema::shouldReceive('getColumnListing')
        ->with('schema_test_models')
        ->andReturn([]);

    Schema::shouldReceive('getColumns')
        ->with('schema_test_models')
        ->andReturn([]);

    $result = (new GetModelSchemaTool)->execute(['model' => SchemaTestModel::class]);

    expect($result)->toContain('generate_filters');
});
