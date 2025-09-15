<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    $model->scopeCustomPaginate($builder, false, $data);
});



it('applies ilike filter on PostgreSQL', function (): void {
    global $builder, $model;

    $filter = Filter::ilike('name')->setValue('%john%');
    $filter->setDatabaseDriver('pgsql');

    $builder->shouldReceive('where')
        ->once()
        ->with('name', 'ILIKE', '%john%')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [$filter];
    $model->scopeFilterable($builder, $filters);
});

it('applies ilike filter on SQLite using LIKE', function (): void {
    global $builder, $model;

    $filter = Filter::ilike('name')->setValue('%john%');
    $filter->setDatabaseDriver('sqlite');

    $builder->shouldReceive('where')
        ->once()
        ->with('name', 'LIKE', '%john%')
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [$filter];
    $model->scopeFilterable($builder, $filters);
});

it('applies ilike filter on MySQL using LOWER', function (): void {
    global $builder, $model;

    $filter = Filter::ilike('name')->setValue('%john%');
    $filter->setDatabaseDriver('mysql');

    $builder->shouldReceive('whereRaw')
        ->once()
        ->with('LOWER(?) LIKE LOWER(?)', ['name', '%john%'])
        ->andReturnSelf();

    $builder->shouldReceive('with')
        ->zeroOrMoreTimes()
        ->andReturnSelf();

    $filters = [$filter];
    $model->scopeFilterable($builder, $filters);
});


it('creates ilike filter with correct operator', function (): void {
    $filter = Filter::ilike('name');
    expect($filter->getOperator())->toBe('ILIKE');
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
