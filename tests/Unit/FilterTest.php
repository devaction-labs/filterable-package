<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use InvalidArgumentException;
use Illuminate\Support\Facades\Request;
use Mockery;

beforeEach(function () {
    global $filters;
    $filters = [];

    Request::shouldReceive('query')
        ->andReturnUsing(function ($key = null, $default = []) use (&$filters) {
            return $key === 'filter' ? $filters : $default;
        });
});

it('can create an exact filter', function () {
    global $filters;
    $filters = ['name' => 'John'];

    $filter = Filter::exact('name');
    expect($filter->getAttribute())->toBe('name')
        ->and($filter->getOperator())->toBe('=')
        ->and($filter->getValue())->toBe('John');
});

it('can create a like filter', function () {
    global $filters;
    $filters = ['name' => 'Doe'];

    $filter = Filter::like('name');
    expect($filter->getAttribute())->toBe('name')
        ->and($filter->getOperator())->toBe('LIKE')
        ->and($filter->getValue())->toBe('%Doe%');
});

it('can set and get a filter value', function () {
    $filter = Filter::exact('name');
    $filter->setValue('John');
    expect($filter->getValue())->toBe('John');
});

it('throws exception for invalid array value in filter', function () {
    $filter = Filter::exact('tags');
    $filter->setValue(['tag1', 123]);
})->throws(InvalidArgumentException::class);

it('can create a json filter com exact match', function () {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['name' => 'John']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.name')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->'$.user.name'")
        ->and($filter->getOperator())->toBe('=')
        ->and($filter->getValue())->toBe('John');
});

it('can create a json filter com like match', function () {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['name' => 'Doe']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.name', 'LIKE')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->'$.user.name'")
        ->and($filter->getOperator())->toBe('LIKE')
        ->and($filter->getValue())->toBe('%Doe%');
});

it('can create a json filter com greater than match', function () {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['age' => 30]], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.age', '>')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->'$.user.age'")
        ->and($filter->getOperator())->toBe('>')
        ->and($filter->getValue())->toBe(30);
});

it('can create a json filter com in match', function () {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['roles' => 'admin,user']], JSON_THROW_ON_ERROR)];

    $filter = Filter::json('data', 'user.roles', 'IN')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->'$.user.roles'")
        ->and($filter->getOperator())->toBe('IN')
        ->and($filter->getValue())->toBe(['admin', 'user']);
});
