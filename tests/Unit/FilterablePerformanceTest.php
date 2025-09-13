<?php

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Eloquent\Builder;

class FilterableTestModel
{
    use Filterable;
}

beforeEach(function (): void {
    Mockery::close();
});

function createDirectFilter(string $attribute, string $value): Filter
{
    $filter = Mockery::mock(Filter::class);
    $filter->shouldReceive('shouldIgnore')->andReturn(false);
    $filter->shouldReceive('getRelationship')->andReturn(null);
    $filter->shouldReceive('getAttribute')->andReturn($attribute);
    $filter->shouldReceive('getValue')->andReturn($value);
    $filter->shouldReceive('getFilterBy')->andReturn($attribute);
    $filter->shouldReceive('getOperator')->andReturn('=');
    $filter->shouldReceive('getJsonPath')->andReturn(null);
    $filter->shouldReceive('isDate')->andReturn(false);

    return $filter;
}

function createRelationshipFilter(string $relationship, string $attribute, string|int $value, bool $withRelation): Filter
{
    $filter = Mockery::mock(Filter::class);
    $filter->shouldReceive('shouldIgnore')->andReturn(false);
    $filter->shouldReceive('getRelationship')->andReturn($relationship);
    $filter->shouldReceive('getAttribute')->andReturn($attribute);
    $filter->shouldReceive('getValue')->andReturn($value);
    $filter->shouldReceive('getFilterBy')->andReturn("{$relationship}.{$attribute}");
    $filter->shouldReceive('getOperator')->andReturn('=');
    $filter->shouldReceive('shouldWith')->andReturn($withRelation);
    $filter->shouldReceive('getJsonPath')->andReturn(null);
    $filter->shouldReceive('isDate')->andReturn(false);
    $filter->shouldReceive('getConditionalLogic')->andReturn(null);

    return $filter;
}

function createConditionalFilter(string $relationship, string $logic, array $conditions): Filter
{
    $filter = Mockery::mock(Filter::class);
    $filter->shouldReceive('shouldIgnore')->andReturn(false);
    $filter->shouldReceive('getRelationship')->andReturn($relationship);
    $filter->shouldReceive('getAttribute')->andReturn('*');
    $filter->shouldReceive('getValue')->andReturn(null);
    $filter->shouldReceive('getFilterBy')->andReturn("{$relationship}.*");
    $filter->shouldReceive('getOperator')->andReturn('=');
    $filter->shouldReceive('shouldWith')->andReturn(false);
    $filter->shouldReceive('getJsonPath')->andReturn(null);
    $filter->shouldReceive('isDate')->andReturn(false);
    $filter->shouldReceive('getConditionalLogic')->andReturn($logic);
    $filter->shouldReceive('getConditionalConditions')->andReturn($conditions);

    return $filter;
}

it('optimizes relationship loading', function (): void {
    $model = new FilterableTestModel;
    $query = Mockery::mock(Builder::class);

    $filter1 = createRelationshipFilter('user', 'name', 'John', true);
    $filter2 = createRelationshipFilter('user', 'email', 'john@example.com', true);
    $filter3 = createRelationshipFilter('user', 'age', 30, true);

    $query->shouldReceive('with')
        ->once()
        ->with(['user'])
        ->andReturnSelf();

    // The filters are grouped by relationship, so only one whereHas call for 'user'
    $query->shouldReceive('whereHas')
        ->once()
        ->andReturnSelf();

    $result = $model->scopeFilterable($query, [$filter1, $filter2, $filter3]);

    expect($result)->toBe($query);
});

it('optimizes direct filtering', function (): void {
    $model = new FilterableTestModel;
    $query = Mockery::mock(Builder::class);

    $filter1 = createDirectFilter('name', 'John');
    $filter2 = createDirectFilter('email', 'john@example.com');

    // Each filter calls where once, and we call scopeFilterable twice (2 * 2 = 4 calls)
    $query->shouldReceive('where')
        ->times(4)
        ->andReturnSelf();

    $result = $model->scopeFilterable($query, [$filter1, $filter2]);
    $result = $model->scopeFilterable($query, [$filter1, $filter2]);

    expect($result)->toBe($query);
});

it('optimizes simple relationship filtering', function (): void {
    $model = new FilterableTestModel;
    $query = Mockery::mock(Builder::class);

    $filter = createRelationshipFilter('user', 'name', 'John', false);

    $query->shouldReceive('whereHas')
        ->once()
        ->with('user', Mockery::on(function ($callback): bool {
            $innerQuery = Mockery::mock(Builder::class);
            $innerQuery->shouldReceive('where')
                ->once()
                ->with('name', 'John')
                ->andReturnSelf();

            $callback($innerQuery);

            return true;
        }))
        ->andReturnSelf();

    $result = $model->scopeFilterable($query, [$filter]);

    expect($result)->toBe($query);
});

it('optimizes conditional logic filtering', function (): void {
    $model = new FilterableTestModel;
    $query = Mockery::mock(Builder::class);

    $filter = createConditionalFilter('user', 'any', [
        ['name', '=', 'John'],
        ['email', '=', 'john@example.com'],
    ]);

    $query->shouldReceive('whereHas')
        ->once()
        ->andReturnSelf();

    $result = $model->scopeFilterable($query, [$filter]);

    expect($result)->toBe($query);
});

it('performs well with many filters', function (): void {
    $model = new FilterableTestModel;
    $query = Mockery::mock(Builder::class);

    $filters = [];

    for ($i = 0; $i < 50; $i++) {
        if ($i % 3 === 0) {
            $filters[] = createRelationshipFilter('user', "field{$i}", "value{$i}", $i % 2 === 0);
        } else {
            $filters[] = createDirectFilter("field{$i}", "value{$i}");
        }
    }

    // Mock all the expected calls
    $query->shouldReceive('with')->andReturnSelf();
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('whereHas')->andReturnSelf();

    $start = microtime(true);

    $model->scopeFilterable($query, $filters);

    $end = microtime(true);
    $executionTime = ($end - $start) * 1000;

    expect($executionTime)->toBeLessThan(500);
});
