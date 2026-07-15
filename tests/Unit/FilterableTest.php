<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use Mockery;

class FilterableTest extends Model
{
    use Filterable;
}

beforeEach(function (): void {
    global $builder, $model;

    Mockery::close();
    $builder = Mockery::mock(Builder::class);

    Request::shouldReceive('query')
        ->andReturn(['name' => 'John']);

    // Sort/pagination hardening reads config; return defaults here.
    Config::shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $default);

    $model = new FilterableTest;
});

afterEach(function (): void {
    Mockery::close();
});

it('applies exact filter using scopeFilterable', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('name', '=', 'John')
        ->andReturnSelf();

    // Allow with() calls for relationship loading
    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::exact('name')->setValue('John')];
    $model->scopeFilterable($builder, $filters);
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
    $model->scopeCustomPaginate($builder, 'paginate', 10, $data);
});

it('applies simple pagination using customPaginate', function (): void {
    global $builder, $model;

    $builder->shouldReceive('orderBy')
        ->once()
        ->with('name', 'ASC')
        ->andReturnSelf();

    $paginator = Mockery::mock(Paginator::class);
    $paginator->shouldReceive('appends')
        ->once()
        ->with(['per_page' => 15, 'sort' => 'name'])
        ->andReturnSelf();

    $builder->shouldReceive('simplePaginate')
        ->once()
        ->with(15)
        ->andReturn($paginator);

    $data = ['per_page' => 15, 'sort' => 'name'];
    $model->scopeCustomPaginate($builder, 'simple', 15, $data);
});

it('applies cursor pagination using customPaginate', function (): void {
    global $builder, $model;

    $builder->shouldReceive('orderBy')
        ->once()
        ->with('id', 'DESC')
        ->andReturnSelf();

    $paginator = Mockery::mock(CursorPaginator::class);
    $paginator->shouldReceive('appends')
        ->once()
        ->with(['per_page' => 20, 'sort' => '-id'])
        ->andReturnSelf();

    $builder->shouldReceive('cursorPaginate')
        ->once()
        ->with(20)
        ->andReturn($paginator);

    $data = ['per_page' => 20, 'sort' => '-id'];
    $model->scopeCustomPaginate($builder, 'cursor', 20, $data);
});

it('throws exception for invalid pagination type', function (): void {
    global $builder, $model;

    $model->scopeCustomPaginate($builder, 'invalid', 15, ['per_page' => 15]);
})->throws(InvalidArgumentException::class, "Invalid pagination type [invalid]. Use 'paginate', 'simple', or 'cursor'.");

it('applies ilike filter using native whereLike (case-insensitive)', function (): void {
    global $builder, $model;

    // On Laravel 11.17+ ILIKE routes to the driver-aware whereLike(); the
    // resulting SQL (ilike/like/glob) is the connection grammar's job and is
    // asserted per-driver in the integration suite.
    $filter = Filter::ilike('name')->setValue('%john%');

    $builder->shouldReceive('whereLike')
        ->once()
        ->with('name', '%john%', false)
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $model->scopeFilterable($builder, [$filter]);
});

it('creates ilike filter with correct operator', function (): void {
    $filter = Filter::ilike('name');
    expect($filter->getOperator())->toBe('ILIKE');
});

it('applies wildcard pattern to ilike filter value', function (): void {
    global $filters;
    $filters = ['name' => 'mario'];

    $filter = Filter::ilike('name');
    $filter->setValueFromRequest();

    expect($filter->getValue())->toBe('%mario%');
});

it('detects database drivers correctly', function (): void {
    $filter = Filter::ilike('name');

    $filter->setDatabaseDriver('pgsql');

    expect($filter->isUsingPostgreSQL())->toBeTrue();
    expect($filter->isUsingMySQL())->toBeFalse();
    expect($filter->isUsingSQLite())->toBeFalse();

    $filter->setDatabaseDriver('mysql');
    expect($filter->isUsingMySQL())->toBeTrue();
    expect($filter->isUsingPostgreSQL())->toBeFalse();
    expect($filter->isUsingSQLite())->toBeFalse();

    $filter->setDatabaseDriver('sqlite');
    expect($filter->isUsingSQLite())->toBeTrue();
    expect($filter->isUsingPostgreSQL())->toBeFalse();
    expect($filter->isUsingMySQL())->toBeFalse();
});

it('applies not equals filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('name', '!=', 'John')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::notEquals('name')->setValue('John')];
    $model->scopeFilterable($builder, $filters);
});

it('applies not in filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereNotIn')
        ->once()
        ->with('status', ['active', 'pending'])
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::notIn('status')->setValue(['active', 'pending'])];
    $model->scopeFilterable($builder, $filters);
});

it('applies not like filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('description', 'NOT LIKE', '%test%')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::notLike('description')->setValue('%test%')];
    $model->scopeFilterable($builder, $filters);
});

it('applies is null filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereNull')
        ->once()
        ->with('deleted_at')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::isNull('deleted_at')->setValue('1')];
    $model->scopeFilterable($builder, $filters);
});

it('applies is not null filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('whereNotNull')
        ->once()
        ->with('email_verified_at')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::isNotNull('email_verified_at')->setValue('1')];
    $model->scopeFilterable($builder, $filters);
});

it('applies starts with filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('name', 'LIKE', 'John%')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::startsWith('name')->setValue('John%')];
    $model->scopeFilterable($builder, $filters);
});

it('applies ends with filter', function (): void {
    global $builder, $model;

    $builder->shouldReceive('where')
        ->once()
        ->with('email', 'LIKE', '%@example.com')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [Filter::endsWith('email')->setValue('%@example.com')];
    $model->scopeFilterable($builder, $filters);
});

it('creates new filter types with correct operators', function (): void {
    expect(Filter::notEquals('name')->getOperator())->toBe('!=');
    expect(Filter::notIn('status')->getOperator())->toBe('NOT IN');
    expect(Filter::notLike('description')->getOperator())->toBe('NOT LIKE');
    expect(Filter::isNull('deleted_at')->getOperator())->toBe('IS NULL');
    expect(Filter::isNotNull('email_verified_at')->getOperator())->toBe('IS NOT NULL');
    expect(Filter::startsWith('name')->getOperator())->toBe('STARTS_WITH');
    expect(Filter::endsWith('email')->getOperator())->toBe('ENDS_WITH');
});
