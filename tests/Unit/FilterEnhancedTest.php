<?php

namespace Tests\Unit;

use Carbon\Carbon;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use ReflectionClass;

beforeEach(function (): void {
    global $filters;
    $filters = [];

    Request::shouldReceive('query')
        ->andReturnUsing(function ($key = null, $default = []) use (&$filters) {
            return $key === 'filter' ? $filters : $default;
        });
});

it('validates date values correctly', function (): void {
    $filter = Filter::exact('created_at')->castDate();

    $filter->setValue('2023-01-01');

    expect($filter->getValue())->toBeInstanceOf(Carbon::class);

    $reflector = new ReflectionClass($filter);
    $method = $reflector->getMethod('convertToCarbon');

    expect($method->invokeArgs($filter, ['2023-01-01']))->toBeInstanceOf(Carbon::class);

    expect(fn (): mixed => $method->invokeArgs($filter, ['not-a-valid-date-format']))
        ->toThrow(InvalidArgumentException::class);
});

it('uses isEmptyOrZero helper method correctly', function (): void {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['name' => 'John']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.name')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->>'$.user.name'");

    expect(fn () => Filter::json('data', ''))->toThrow(InvalidArgumentException::class);
    expect(fn () => Filter::json('data', "user'; DROP TABLE users; --"))->toThrow(InvalidArgumentException::class);

    $filter = Filter::exact('data')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe('data');
});

it('handles database drivers correctly', function (): void {
    $filter = Filter::json('data', 'user.name');

    $reflector = new ReflectionClass($filter);
    $mysqlMethod = $reflector->getMethod('isUsingMySQL');
    $sqliteMethod = $reflector->getMethod('isUsingSQLite');
    $pgsqlMethod = $reflector->getMethod('isUsingPostgreSQL');

    $filter->setDatabaseDriver('mysql');
    expect($mysqlMethod->invoke($filter))->toBeTrue();
    expect($sqliteMethod->invoke($filter))->toBeFalse();
    expect($pgsqlMethod->invoke($filter))->toBeFalse();

    $filter->setDatabaseDriver('sqlite');
    expect($mysqlMethod->invoke($filter))->toBeFalse();
    expect($sqliteMethod->invoke($filter))->toBeTrue();
    expect($pgsqlMethod->invoke($filter))->toBeFalse();

    $filter->setDatabaseDriver('pgsql');
    expect($mysqlMethod->invoke($filter))->toBeFalse();
    expect($sqliteMethod->invoke($filter))->toBeFalse();
    expect($pgsqlMethod->invoke($filter))->toBeTrue();
});

it('validates array values correctly', function (): void {
    $filter = Filter::in('tags');

    $filter->setValue(['tag1', 'tag2']);
    expect($filter->getValue())->toBe(['tag1', 'tag2']);

    $filter->setValue(['tag1', 2]);
    expect($filter->getValue())->toBe(['tag1', 2]);

    expect(fn (): Filter => $filter->setValue(['tag1', []]))->toThrow(InvalidArgumentException::class);
});

it('uses match expressions for value transformation', function (): void {
    $jsonStr = json_encode(['user' => ['status' => 'active']], JSON_THROW_ON_ERROR);

    $filter = Filter::json('data', 'user.status', Filter::OPERATOR_EQUALS);
    $filter->setValue($jsonStr);

    $reflector = new ReflectionClass($filter);
    $method = $reflector->getMethod('applyOperatorToValue');

    expect($method->invokeArgs($filter, ['active']))->toBe('active');

    $filter = Filter::json('data', 'user.status', Filter::OPERATOR_LIKE);
    expect($method->invokeArgs($filter, ['active']))->toBe('%active%');

    $filter = Filter::json('data', 'user.status', Filter::OPERATOR_IN);
    expect($method->invokeArgs($filter, ['admin,user']))->toBe(['admin', 'user']);
});

it('applies date modifiers correctly', function (): void {
    $now = Carbon::now();
    $filter = Filter::exact('created_at')->castDate()->setValue($now->format('Y-m-d'));

    $date = $filter->getValue();
    expect($date->format('H:i:s'))->toBe('00:00:00');

    $filter = Filter::exact('created_at')->castDate()->endOfDay()->setValue($now->format('Y-m-d'));
    $date = $filter->getValue();
    expect($date->format('H:i:s'))->toBe('23:59:59');

    $filter = Filter::exact('created_at')->castDate()->startOfDay()->setValue($now->format('Y-m-d'));
    $date = $filter->getValue();
    expect($date->format('H:i:s'))->toBe('00:00:00');
});

it('prepares values correctly based on operator', function (): void {
    global $filters;

    $filters = ['date_range' => '2023-01-01,2023-12-31'];
    $filter = Filter::between('created_at', 'date_range');
    expect($filter->getValue())->toBe(['2023-01-01', '2023-12-31']);

    $filters = ['roles' => 'admin,user,guest'];
    $filter = Filter::in('role', 'roles');
    expect($filter->getValue())->toBe(['admin', 'user', 'guest']);

    $filters = ['search' => 'test'];
    $filter = Filter::like('name', 'search');
    expect($filter->getValue())->toBe('%test%');
});
