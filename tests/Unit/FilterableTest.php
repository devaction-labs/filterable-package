<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mockery;

class FilterableTest extends Model
{
    use Filterable;
}

beforeEach(function (): void {
    global $builder, $model;

    Mockery::close();

    $builder = Mockery::mock(Builder::class);

    $model = new FilterableTest;
});

it('applies exact filter using scopeFilterable', function (): void {
    global $model;

    $builder = Mockery::mock(Builder::class);
    $filter = Mockery::mock(Filter::class);

    $filter->shouldReceive('shouldIgnore')->andReturn(false);
    $filter->shouldReceive('getRelationship')->andReturn(null);
    $filter->shouldReceive('getAttribute')->andReturn('name');
    $filter->shouldReceive('getValue')->andReturn('John');
    $filter->shouldReceive('getFilterBy')->andReturn('name');
    $filter->shouldReceive('getOperator')->andReturn('=');
    $filter->shouldReceive('getJsonPath')->andReturn(null);
    $filter->shouldReceive('isDate')->andReturn(false);

    $builder->shouldReceive('where')
        ->once()
        ->with('name', '=', 'John')
        ->andReturnSelf();

    $result = $model->scopeFilterable($builder, [$filter]);

    expect($result)->toBe($builder);
});

it('applies pagination using scopeCustomPaginate', function (): void {
    global $builder, $model;

    $builder->shouldReceive('orderBy')
        ->once()
        ->with('created_at', 'DESC')
        ->andReturnSelf();

    $paginator = Mockery::mock(LengthAwarePaginator::class);
    $paginator->shouldReceive('appends')
        ->once()
        ->with(['per_page' => 10, 'sort' => '-created_at'])
        ->andReturnSelf();

    $builder->shouldReceive('paginate')
        ->once()
        ->with(10)
        ->andReturn($paginator);

    $data = ['per_page' => 10, 'sort' => '-created_at'];
    $model->scopeCustomPaginate($builder, false, $data);
});
