<?php

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\CacheManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

beforeEach(function () {
    global $builder, $model;

    $builder = Mockery::mock(Builder::class);

    Request::shouldReceive('query')
        ->andReturn(['name' => 'John']);

    Config::shouldReceive('get')
        ->with('filterable.cache.enabled', true)
        ->andReturn(true);

    Config::shouldReceive('get')
        ->with('filterable.cache.ttl', 60)
        ->andReturn(60);

    Config::shouldReceive('get')
        ->with('filterable.cache.prefix', 'filterable_')
        ->andReturn('filterable_');

    Cache::shouldReceive('tags')
        ->with(['filterable'])
        ->andReturnSelf();

    Cache::shouldReceive('remember')
        ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $model = new class {
        use DevactionLabs\FilterablePackage\Traits\Filterable;
    };
});

afterEach(function () {
    Mockery::close();
});

it('applies exact filter using scopeFilterable', function () {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('name', '=', 'John')
        ->andReturnSelf();

    $filters = [Filter::exact('name')->setValue('John')];

    $result = $model->scopeFilterable($builder, $filters);

    expect($result)->toBe($builder);
});

it('applies custom paginate correctly', function () {
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

    $result = $model->scopeCustomPaginate($builder, false, [
        'per_page' => 10,
        'sort' => '-created_at'
    ]);

    expect($result)->toBe($paginator);
});
