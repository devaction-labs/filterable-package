<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use InvalidArgumentException;

it('can create an exact filter', function () {
    $filter = Filter::exact('name');
    expect($filter->getAttribute())->toBe('name')
        ->and($filter->getOperator())->toBe('=');
});

it('can create a like filter', function () {
    $filter = Filter::like('name');
    expect($filter->getAttribute())->toBe('name')
        ->and($filter->getOperator())->toBe('LIKE')
        ->and($filter->getValue())->toBeNull();
});

it('can set and get a filter value', function () {
    $filter = Filter::exact('name');
    $filter->setValue('John');
    expect($filter->getValue())->toBe('John');
});

it('throws exception for invalid array value in filter', function () {
    $filter = Filter::exact('tags');
    $filter->setValue(['tag1', 123]); // Invalid because one element is not a string
})->throws(InvalidArgumentException::class);
