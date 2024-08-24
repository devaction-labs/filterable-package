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

beforeEach(function () {

    $this->builder = Mockery::mock(Builder::class);
    $this->model = new FilterableTest();
});

it('applies exact filter using scopeFilterable', function () {
    $this->builder->shouldReceive('where')
        ->once()
        ->with('name', '=', 'John')
        ->andReturnSelf();

    $filters = [Filter::exact('name')->setValue('John')];
    $this->model->scopeFilterable($this->builder, $filters);
});

it('applies pagination using scopeCustomPaginate', function () {
    $this->builder->shouldReceive('orderBy')
        ->once()
        ->with('created_at', 'DESC')
        ->andReturnSelf();


    $paginator = Mockery::mock(LengthAwarePaginator::class);
    $paginator->shouldReceive('appends')
        ->once()
        ->with(['per_page' => 10, 'sort' => '-created_at'])
        ->andReturnSelf();

    $this->builder->shouldReceive('paginate')
        ->once()
        ->with(10)
        ->andReturn($paginator);

    $data = ['per_page' => 10, 'sort' => '-created_at'];
    $this->model->scopeCustomPaginate($this->builder, false, $data);
});
