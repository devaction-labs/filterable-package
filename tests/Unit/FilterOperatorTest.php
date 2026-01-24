<?php

use DevactionLabs\FilterablePackage\Enums\FilterOperator;

it('has correct values for all operators', function (): void {
    expect(FilterOperator::EQUALS->value)->toBe('=');
    expect(FilterOperator::LIKE->value)->toBe('LIKE');
    expect(FilterOperator::IN->value)->toBe('IN');
    expect(FilterOperator::GT->value)->toBe('>');
    expect(FilterOperator::GTE->value)->toBe('>=');
    expect(FilterOperator::LT->value)->toBe('<');
    expect(FilterOperator::LTE->value)->toBe('<=');
    expect(FilterOperator::BETWEEN->value)->toBe('BETWEEN');
    expect(FilterOperator::ILIKE->value)->toBe('ILIKE');
    expect(FilterOperator::NOT_EQUALS->value)->toBe('!=');
    expect(FilterOperator::NOT_IN->value)->toBe('NOT IN');
    expect(FilterOperator::NOT_LIKE->value)->toBe('NOT LIKE');
    expect(FilterOperator::IS_NULL->value)->toBe('IS NULL');
    expect(FilterOperator::IS_NOT_NULL->value)->toBe('IS NOT NULL');
    expect(FilterOperator::STARTS_WITH->value)->toBe('STARTS_WITH');
    expect(FilterOperator::ENDS_WITH->value)->toBe('ENDS_WITH');
});

it('checks if operator requires value', function (): void {
    expect(FilterOperator::EQUALS->requiresValue())->toBeTrue();
    expect(FilterOperator::LIKE->requiresValue())->toBeTrue();
    expect(FilterOperator::IS_NULL->requiresValue())->toBeFalse();
    expect(FilterOperator::IS_NOT_NULL->requiresValue())->toBeFalse();
});

it('checks if operator expects array', function (): void {
    expect(FilterOperator::IN->expectsArray())->toBeTrue();
    expect(FilterOperator::NOT_IN->expectsArray())->toBeTrue();
    expect(FilterOperator::BETWEEN->expectsArray())->toBeTrue();
    expect(FilterOperator::EQUALS->expectsArray())->toBeFalse();
    expect(FilterOperator::LIKE->expectsArray())->toBeFalse();
});

it('checks if operator is string comparison', function (): void {
    expect(FilterOperator::LIKE->isStringComparison())->toBeTrue();
    expect(FilterOperator::NOT_LIKE->isStringComparison())->toBeTrue();
    expect(FilterOperator::ILIKE->isStringComparison())->toBeTrue();
    expect(FilterOperator::STARTS_WITH->isStringComparison())->toBeTrue();
    expect(FilterOperator::ENDS_WITH->isStringComparison())->toBeTrue();
    expect(FilterOperator::EQUALS->isStringComparison())->toBeFalse();
    expect(FilterOperator::IN->isStringComparison())->toBeFalse();
});
