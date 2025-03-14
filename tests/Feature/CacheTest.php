<?php

use DevactionLabs\FilterablePackage\CacheManager;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

beforeEach(function () {
    global $builder, $model;

    Config::shouldReceive('get')->withAnyArgs()->andReturnUsing(fn ($key, $default) => match ($key) {
        'filterable.cache.enabled' => true,
        'filterable.cache.ttl' => 60,
        'filterable.cache.prefix' => 'filterable_',
        default => $default,
    });

    Request::shouldReceive('query')->andReturn(['filter' => ['status' => 'active']]);

    $builder = Mockery::mock(Builder::class);

    $model = new class {
        use DevactionLabs\FilterablePackage\Traits\Filterable;
    };
});

afterEach(fn () => Mockery::close());

it('stores value in cache', function (): void {
    $taggedCache = Mockery::mock('TaggedCache');
    $taggedCache->shouldReceive('remember')
        ->once()
        ->with('test_key', 60, Mockery::type('Closure'))
        ->andReturnUsing(function ($key, $ttl, $callback) {
            $callbackResult = $callback();
            echo "Callback retornou: " . $callbackResult . "\n";
            return 'cached-value';
        });

    Cache::shouldReceive('tags')
        ->once()
        ->with(['filterable'])
        ->andReturn($taggedCache);

    $result = CacheManager::remember('test_key', 60, fn (): string => 'real-value');

    expect($result)->toBe('cached-value');
});

it('clears cache correctly', function (): void {
    Cache::shouldReceive('tags')->once()->with(['filterable'])->andReturnSelf();
    Cache::shouldReceive('flush')->once();

    CacheManager::clear();

    expect(true)->toBeTrue();
});

it('applies filters using scopeFilterable with cache', function (): void {
    global $builder, $model;

    $mockedCollection = collect(['user1', 'user2', 'user3']);

    Cache::shouldReceive('tags')->once()->with(['filterable'])->andReturnSelf();
    Cache::shouldReceive('remember')
        ->once()
        ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $builder->shouldReceive('where')
        ->once()
        ->with('status', '=', 'active')
        ->andReturnSelf();

    $builder->shouldReceive('get')
        ->once()
        ->andReturn($mockedCollection = collect([1,2,3]));

    $result = $model
        ->scopeFilterable($builder, [Filter::exact('status')->setValue('active')])
        ->get();

    expect($result)->toHaveCount(3);
});
