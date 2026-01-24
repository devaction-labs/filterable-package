<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Mockery;

class FilterableFullTextTestModel extends Model
{
    use Filterable;

    protected $table = 'test_table';
}

beforeEach(function (): void {
    global $builder, $model;

    Mockery::close();
    $builder = Mockery::mock(Builder::class);

    Request::shouldReceive('query')
        ->andReturn([]);

    Config::shouldReceive('get')
        ->with('app.fulltext_language', 'simple')
        ->andReturn('simple');

    $model = new FilterableFullTextTestModel;
});

afterEach(function (): void {
    Mockery::close();
});

it('applies full-text search filter on PostgreSQL', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => str_contains((string) $sql, 'to_tsvector') &&
               str_contains((string) $sql, 'to_tsquery') &&
               count($bindings) === 1)
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['title', 'content'], 'search')
        ->setDatabaseDriver('pgsql')
        ->setValue('laravel framework');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('applies full-text search filter on MySQL using LIKE', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->withArgs(fn ($callback): bool => is_callable($callback))
        ->andReturnUsing(function ($callback) use ($builder) {
            $subQuery = Mockery::mock(Builder::class);

            $subQuery->shouldReceive('orWhere')
                ->twice()
                ->andReturnSelf();

            $callback($subQuery);

            return $builder;
        });

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['title', 'content'], 'search')
        ->setDatabaseDriver('mysql')
        ->setValue('laravel');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('applies full-text search filter on SQLite using LIKE', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->withArgs(fn ($callback): bool => is_callable($callback))
        ->andReturnUsing(function ($callback) use ($builder) {
            $subQuery = Mockery::mock(Builder::class);

            $subQuery->shouldReceive('orWhere')
                ->times(3)
                ->andReturnSelf();

            $callback($subQuery);

            return $builder;
        });

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['title', 'body', 'tags'], 'search')
        ->setDatabaseDriver('sqlite')
        ->setValue('test');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('uses websearch_to_tsquery for tsvector column with useTsVector', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => str_contains((string) $sql, 'search_vector') &&
               str_contains((string) $sql, 'websearch_to_tsquery') &&
               $bindings[0] === 'laravel')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText('search_vector', 'search')
        ->setDatabaseDriver('pgsql')
        ->useTsVector()
        ->setValue('laravel');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('uses websearch_to_tsquery for custom tsvector column name', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => str_contains((string) $sql, 'custom_fts_column') &&
               str_contains((string) $sql, 'websearch_to_tsquery') &&
               $bindings[0] === 'test query')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText('custom_fts_column', 'q')
        ->setDatabaseDriver('pgsql')
        ->useTsVector()
        ->setValue('test query');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('uses configured language for tsvector column', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => str_contains((string) $sql, "'portuguese'") &&
               str_contains((string) $sql, 'websearch_to_tsquery'))
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText('search_vector', 'search')
        ->setDatabaseDriver('pgsql')
        ->useTsVector()
        ->setFullTextLanguage('portuguese')
        ->setValue('teste');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('ignores empty search terms in full-text', function (): void {
    global $builder, $model;

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $builder->shouldNotReceive('whereRaw');
    $builder->shouldNotReceive('where');

    $filter = Filter::fullText('content', 'search')->setValue('');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('applies configured language in PostgreSQL full-text search', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql): bool => str_contains((string) $sql, "'english'"))
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['content'], 'search')
        ->setDatabaseDriver('pgsql')
        ->setFullTextLanguage('english')
        ->setValue('test');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('applies prefix matching in PostgreSQL full-text search', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => str_contains((string) $bindings[0], ':*'))
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['title'], 'search')
        ->setDatabaseDriver('pgsql')
        ->setFullTextPrefixMatch(true)
        ->setValue('test');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('disables prefix matching when configured', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => ! str_contains((string) $bindings[0], ':*'))
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['title'], 'search')
        ->setDatabaseDriver('pgsql')
        ->setFullTextPrefixMatch(false)
        ->setValue('test');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('handles multiple words in PostgreSQL full-text search', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereRaw')
        ->once()
        ->withArgs(fn ($sql, $bindings): bool => str_contains((string) $bindings[0], ' & '))
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText(['content'], 'search')
        ->setDatabaseDriver('pgsql')
        ->setValue('laravel php framework');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});

it('searches single column with generic search', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->withArgs(fn ($callback): bool => is_callable($callback))
        ->andReturnUsing(function ($callback) use ($builder) {
            $subQuery = Mockery::mock(Builder::class);

            $subQuery->shouldReceive('orWhere')
                ->once()
                ->with('title', 'like', '%laravel%')
                ->andReturnSelf();

            $callback($subQuery);

            return $builder;
        });

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filter = Filter::fullText('title', 'search')
        ->setDatabaseDriver('mysql')
        ->setValue('laravel');

    $model->scopeFilterable($builder, [$filter]);

    expect(true)->toBeTrue();
});
