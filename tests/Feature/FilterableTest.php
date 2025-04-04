<?php

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

beforeEach(function (): void {
    global $builder, $model;

    $builder = Mockery::mock(Builder::class);

    Request::shouldReceive('query')->andReturn(['filter' => ['name' => 'John']]);

    Config::shouldReceive('get')->withAnyArgs()->andReturnUsing(fn ($key, $default): true|int|string => match ($key) {
        'filterable.cache.enabled' => true,
        'filterable.cache.ttl' => 60,
        'filterable.cache.prefix' => 'filterable_',
    });

    Cache::shouldReceive('tags')->andReturnSelf();
    Cache::shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $model = new class
    {
        use Filterable;
    };
});

afterEach(fn () => Mockery::close());

it('applies exact filter using scopeFilterable', function (): void {
    global $builder, $model;
    $builder->shouldReceive('where')->once()->with('name', '=', 'John')->andReturnSelf();

    // Allow with() and whereHas() calls from our optimization
    $builder->shouldReceive('with')->zeroOrMoreTimes()->andReturnSelf();
    $builder->shouldReceive('whereHas')->zeroOrMoreTimes()->andReturnSelf();

    $model->scopeFilterable($builder, [Filter::exact('name')->setValue('John')]);
});
