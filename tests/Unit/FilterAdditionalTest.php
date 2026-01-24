<?php

use Carbon\Carbon;
use DevactionLabs\FilterablePackage\Enums\FilterOperator;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Support\Facades\Request;

beforeEach(function (): void {
    global $filters;
    $filters = [];

    Request::shouldReceive('query')
        ->andReturnUsing(function ($key = null, $default = []) use (&$filters) {
            return $key === 'filter' ? $filters : $default;
        });
});

it('can use generic filter creation', function (): void {
    $filter = Filter::generic('status', FilterOperator::NOT_EQUALS->value);

    expect($filter->getAttribute())->toBe('status')
        ->and($filter->getOperator())->toBe(FilterOperator::NOT_EQUALS->value);
});

it('can get operator as enum', function (): void {
    $filter = Filter::exact('name');

    expect($filter->getOperatorEnum())->toBeInstanceOf(FilterOperator::class)
        ->and($filter->getOperatorEnum())->toBe(FilterOperator::EQUALS);
});

it('handles custom like pattern', function (): void {
    global $filters;
    $filters = ['name' => 'test'];

    $filter = Filter::like('name')->setLikePattern('{{value}}%');
    $filter->setValueFromRequest();

    expect($filter->getValue())->toBe('test%');
});

it('handles date with start of day', function (): void {
    $filter = Filter::exact('created_at')->castDate()->startOfDay();
    $filter->setValue('2024-01-15');

    $value = $filter->getValue();
    expect($value)->toBeInstanceOf(Carbon::class)
        ->and($value->format('H:i:s'))->toBe('00:00:00');
});

it('handles date with end of day', function (): void {
    $filter = Filter::exact('created_at')->castDate()->endOfDay();
    $filter->setValue('2024-01-15');

    $value = $filter->getValue();
    expect($value)->toBeInstanceOf(Carbon::class)
        ->and($value->format('H:i:s'))->toBe('23:59:59');
});

it('can check if filter should be ignored', function (): void {
    $filter = Filter::exact('name');

    expect($filter->shouldIgnore())->toBeTrue();

    $filter->setValue('John');
    expect($filter->shouldIgnore())->toBeFalse();
});

it('handles relationship with conditional logic whereAny', function (): void {
    $filter = Filter::relationship('user', 'name')
        ->with()
        ->whereAny([['age', '>', 18], ['verified', '=', true]]);

    expect($filter->shouldWith())->toBeTrue()
        ->and($filter->getConditionalLogic())->toBe('any')
        ->and($filter->getConditionalConditions())->toHaveCount(2);
});

it('handles relationship with conditional logic whereAll', function (): void {
    $filter = Filter::relationship('user', 'name')
        ->whereAll([['age', '>', 18], ['verified', '=', true]]);

    expect($filter->getConditionalLogic())->toBe('all');
});

it('handles relationship with conditional logic whereNone', function (): void {
    $filter = Filter::relationship('user', 'name')
        ->whereNone([['banned', '=', true]]);

    expect($filter->getConditionalLogic())->toBe('none');
});

it('throws exception when using with() on non-relationship filter', function (): void {
    $filter = Filter::exact('name');

    $filter->with();
})->throws(InvalidArgumentException::class, 'The with() method can only be used with relationship filters.');

it('throws exception when using whereAny() on non-relationship filter', function (): void {
    $filter = Filter::exact('name');

    $filter->whereAny([]);
})->throws(InvalidArgumentException::class, 'The whereAny() method can only be used with relationship filters.');

it('throws exception when using whereAll() on non-relationship filter', function (): void {
    $filter = Filter::exact('name');

    $filter->whereAll([]);
})->throws(InvalidArgumentException::class, 'The whereAll() method can only be used with relationship filters.');

it('throws exception when using whereNone() on non-relationship filter', function (): void {
    $filter = Filter::exact('name');

    $filter->whereNone([]);
})->throws(InvalidArgumentException::class, 'The whereNone() method can only be used with relationship filters.');

it('can set database driver', function (): void {
    $filter = Filter::exact('name');
    $filter->setDatabaseDriver('pgsql');

    expect($filter->isUsingPostgreSQL())->toBeTrue()
        ->and($filter->isUsingMySQL())->toBeFalse()
        ->and($filter->isUsingSQLite())->toBeFalse();
});

it('handles integer values', function (): void {
    $filter = Filter::exact('age');
    $filter->setValue(25);

    expect($filter->getValue())->toBe(25);
});

it('handles null values', function (): void {
    $filter = Filter::isNull('deleted_at');

    expect($filter->getOperator())->toBe(FilterOperator::IS_NULL->value);
});

it('handles not null values', function (): void {
    $filter = Filter::isNotNull('deleted_at');

    expect($filter->getOperator())->toBe(FilterOperator::IS_NOT_NULL->value);
});

it('handles starts with filter', function (): void {
    global $filters;
    $filters = ['name' => 'John'];

    $filter = Filter::startsWith('name');

    expect($filter->getValue())->toBe('John%');
});

it('handles ends with filter', function (): void {
    global $filters;
    $filters = ['name' => 'Doe'];

    $filter = Filter::endsWith('name');

    expect($filter->getValue())->toBe('%Doe');
});

it('handles IN operator with comma-separated string', function (): void {
    global $filters;
    $filters = ['status' => 'active,pending,inactive'];

    $filter = Filter::in('status');

    expect($filter->getValue())->toBe(['active', 'pending', 'inactive']);
});

it('handles NOT IN operator', function (): void {
    global $filters;
    $filters = ['status' => 'banned,suspended'];

    $filter = Filter::notIn('status');

    expect($filter->getValue())->toBeArray()
        ->and($filter->getValue())->toHaveCount(2);
});

it('handles NOT LIKE operator', function (): void {
    $filter = Filter::notLike('email');
    $filter->setLikePattern('{{value}}');
    $filter->setValue('spam');

    expect($filter->getValue())->toBe('spam');
});

it('handles comparison operators', function (): void {
    $gte = Filter::gte('age');
    $gt = Filter::gt('age');
    $lte = Filter::lte('age');
    $lt = Filter::lt('age');

    expect($gte->getOperator())->toBe(FilterOperator::GTE->value)
        ->and($gt->getOperator())->toBe(FilterOperator::GT->value)
        ->and($lte->getOperator())->toBe(FilterOperator::LTE->value)
        ->and($lt->getOperator())->toBe(FilterOperator::LT->value);
});

it('can check if filter is date type', function (): void {
    $filter = Filter::exact('created_at')->castDate();

    expect($filter->isDate())->toBeTrue();
});

it('handles ILIKE filter', function (): void {
    $filter = Filter::ilike('name');

    expect($filter->getOperator())->toBe(FilterOperator::ILIKE->value);
});

it('handles NOT EQUALS filter', function (): void {
    $filter = Filter::notEquals('status');

    expect($filter->getOperator())->toBe(FilterOperator::NOT_EQUALS->value);
});
