<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Mockery;

// Classe de modelo fictícia que usa o trait Filterable
class FilterableTest extends Model
{
    use Filterable;
}

beforeEach(function () {
    global $builder, $model;

    // Mock do Builder
    $builder = Mockery::mock(Builder::class);

    // Mock do Request facade
    Request::shouldReceive('query')
        ->andReturn(['name' => 'John']);  // Mockando o valor esperado

    // Instância do modelo
    $model = new FilterableTest();
});

it('applies exact filter using scopeFilterable', function () {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('name', '=', 'John')
        ->andReturnSelf();

    $filters = [Filter::exact('name')->setValue('John')]; // Setando explicitamente o valor
    $model->scopeFilterable($builder, $filters);
});


it('applies pagination using scopeCustomPaginate', function () {
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
