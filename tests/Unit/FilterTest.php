<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

beforeEach(function (): void {
    global $filters;
    $filters = [];

    Request::shouldReceive('query')
        ->andReturnUsing(function ($key = null, $default = []) use (&$filters) {
            return $key === 'filter' ? $filters : $default;
        });
});

it('can create an exact filter', function (): void {
    global $filters;
    $filters = ['name' => 'John'];

    $filter = Filter::exact('name');
    expect($filter->getAttribute())->toBe('name')
        ->and($filter->getOperator())->toBe('=')
        ->and($filter->getValue())->toBe('John');
});

it('can create a like filter', function (): void {
    global $filters;
    $filters = ['name' => 'Doe'];

    $filter = Filter::like('name');
    expect($filter->getAttribute())->toBe('name')
        ->and($filter->getOperator())->toBe('LIKE')
        ->and($filter->getValue())->toBe('%Doe%');
});

it('can set and get a filter value', function (): void {
    $filter = Filter::exact('name');
    $filter->setValue('John');

    expect($filter->getValue())->toBe('John');
});

it('throws exception for invalid array value in filter', function (): void {
    $filter = Filter::exact('tags');
    $filter->setValue(['tag1', []]);
})->throws(InvalidArgumentException::class);

it('can create a json filter com exact match', function (): void {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['name' => 'John']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.name')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->>'$.user.name'")
        ->and($filter->getOperator())->toBe('=')
        ->and($filter->getValue())->toBe('John');
});

it('can create a json filter com like match', function (): void {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['name' => 'Doe']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.name', 'LIKE')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->>'$.user.name'")
        ->and($filter->getOperator())->toBe('LIKE')
        ->and($filter->getValue())->toBe('%'.$filters['data'].'%');
});

it('can create a json filter com greater than match', function (): void {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['age' => 30]], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.age', '>')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->>'$.user.age'")
        ->and($filter->getOperator())->toBe('>')
        ->and($filter->getValue())->toBe(30);
});

it('can create a json filter com in match', function (): void {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['roles' => 'admin,user']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.roles', 'IN')->setDatabaseDriver('mysql')->setValue($filters['data']);
    expect($filter->getAttribute())->toBe("data->>'$.user.roles'")
        ->and($filter->getOperator())->toBe('IN');
});

it('can create a between filter', function (): void {
    global $filters;
    $filters = ['created_at' => ['2023-01-01', '2023-12-31']];

    $filter = Filter::between('created_at');
    expect($filter->getAttribute())->toBe('created_at')
        ->and($filter->getOperator())->toBe('BETWEEN')
        ->and($filter->getValue())->toBe(['2023-01-01', '2023-12-31']);
});

it('throws exception for invalid between filter value', function (): void {
    $filter = Filter::between('created_at');
    $filter->setValue(['2023-01-01']);
})->throws(InvalidArgumentException::class);
