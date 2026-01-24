<?php

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

it('creates full-text search filter with single column', function (): void {
    $filter = Filter::fullText('name');

    expect($filter->getOperator())->toBe(FilterOperator::FULL_TEXT->value)
        ->and($filter->getFullTextColumns())->toBe(['name']);
});

it('creates full-text search filter with multiple columns', function (): void {
    $filter = Filter::fullText(['name', 'description', 'content']);

    expect($filter->getOperator())->toBe(FilterOperator::FULL_TEXT->value)
        ->and($filter->getFullTextColumns())->toBe(['name', 'description', 'content'])
        ->and($filter->getFullTextColumns())->toHaveCount(3);
});

it('can set full-text search language', function (): void {
    $filter = Filter::fullText('content')->setFullTextLanguage('portuguese');

    expect($filter->getFullTextLanguage())->toBe('portuguese');
});

it('can set full-text prefix match', function (): void {
    $filter = Filter::fullText('content')->setFullTextPrefixMatch(false);

    expect($filter->getFullTextPrefixMatch())->toBeFalse();
});

it('defaults to prefix match enabled', function (): void {
    $filter = Filter::fullText('content');

    expect($filter->getFullTextPrefixMatch())->toBeTrue();
});

it('can chain configuration methods', function (): void {
    $filter = Filter::fullText(['title', 'body'])
        ->setFullTextLanguage('english')
        ->setFullTextPrefixMatch(false);

    expect($filter->getFullTextLanguage())->toBe('english')
        ->and($filter->getFullTextPrefixMatch())->toBeFalse()
        ->and($filter->getFullTextColumns())->toBe(['title', 'body']);
});

it('uses custom filter by parameter', function (): void {
    global $filters;
    $filters = ['q' => 'laravel'];

    $filter = Filter::fullText('content', 'q');

    expect($filter->getValue())->toBe('laravel');
});

it('defaults to search parameter', function (): void {
    global $filters;
    $filters = ['search' => 'testing'];

    $filter = Filter::fullText('content');

    expect($filter->getValue())->toBe('testing');
});

it('handles search_vector column for PostgreSQL', function (): void {
    $filter = Filter::fullText('search_vector');

    expect($filter->getFullTextColumns())->toBe(['search_vector'])
        ->and($filter->getAttribute())->toBe('search_vector');
});

it('can configure language after creation', function (): void {
    $filter = Filter::fullText('content');

    expect($filter->getFullTextLanguage())->toBeNull();

    $filter->setFullTextLanguage('portuguese');

    expect($filter->getFullTextLanguage())->toBe('portuguese');
});

it('allows null language to use default', function (): void {
    $filter = Filter::fullText('content')->setFullTextLanguage(null);

    expect($filter->getFullTextLanguage())->toBeNull();
});

it('handles empty search term gracefully', function (): void {
    global $filters;
    $filters = ['search' => ''];

    $filter = Filter::fullText('content');

    expect($filter->shouldIgnore())->toBeTrue();
});

it('uses first column as attribute when multiple columns provided', function (): void {
    $filter = Filter::fullText(['title', 'content', 'tags']);

    expect($filter->getAttribute())->toBe('title')
        ->and($filter->getFullTextColumns())->toBe(['title', 'content', 'tags']);
});

it('has FULL_TEXT operator value', function (): void {
    expect(FilterOperator::FULL_TEXT->value)->toBe('FULL_TEXT');
});

it('FULL_TEXT operator requires value', function (): void {
    expect(FilterOperator::FULL_TEXT->requiresValue())->toBeTrue();
});

it('FULL_TEXT operator does not expect array', function (): void {
    expect(FilterOperator::FULL_TEXT->expectsArray())->toBeFalse();
});

it('FULL_TEXT operator is not string comparison', function (): void {
    expect(FilterOperator::FULL_TEXT->isStringComparison())->toBeFalse();
});
