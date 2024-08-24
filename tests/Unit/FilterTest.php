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
            return $filters;
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
