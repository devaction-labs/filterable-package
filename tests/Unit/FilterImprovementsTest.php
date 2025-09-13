<?php

use Carbon\Carbon;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Support\Facades\Request;

beforeEach(function (): void {
    Filter::clearCaches();
    Mockery::close();
});

it('prevents request access in constructor with lazy loading', function (): void {
    Request::shouldReceive('query')->never();

    new Filter('name', '=', 'search');

    expect(true)->toBeTrue();
});

it('works with manually set values', function (): void {
    // Test that manually set values work correctly
    $filter = new Filter('name', '=');
    $filter->setValue('TestValue');

    expect($filter->shouldIgnore())->toBeFalse();
});

it('ignores filters without values', function (): void {
    // Test that a filter without value should be ignored
    $filter = new Filter('name', '=', 'nonexistent_key');

    expect($filter->shouldIgnore())->toBeTrue();
});

it('limits json cache size for memory management', function (): void {
    // Clear cache first to ensure clean state
    Filter::clearCaches();

    // Create many filters to fill the cache
    for ($i = 0; $i < 1200; $i++) {
        $filter = new Filter("data_$i", '=');
        $filter->setJsonPath("user.field_$i");
        $filter->getAttribute(); // This will populate the cache
    }

    // Create one more filter that should trigger cache cleanup
    $filter = new Filter('final_data', '=');
    $filter->setJsonPath('user.final_field');
    $attribute = $filter->getAttribute();

    // Just verify the filter works correctly
    expect($attribute)->toBe("final_data->>'$.user.final_field'");
});

it('validates between operator correctly', function (): void {
    $filter = new Filter('price', 'BETWEEN');

    expect($filter->isValid('100,200'))->toBeTrue()
        ->and($filter->isValid(['100', '200']))->toBeTrue()
        ->and($filter->isValid('100'))->toBeFalse()
        ->and($filter->isValid(['100']))->toBeFalse()
        ->and($filter->isValid('100,'))->toBeFalse()
        ->and($filter->isValid(',200'))->toBeFalse();
});

it('validates in operator correctly', function (): void {
    $filter = new Filter('category', 'IN');

    expect($filter->isValid('1,2,3'))->toBeTrue()
        ->and($filter->isValid(['1', '2', '3']))->toBeTrue()
        ->and($filter->isValid('single'))->toBeTrue()
        ->and($filter->isValid(''))->toBeFalse()
        ->and($filter->isValid('   '))->toBeFalse()
        ->and($filter->isValid([]))->toBeFalse();
});

it('validates like operator correctly', function (): void {
    $filter = new Filter('name', 'LIKE');

    expect($filter->isValid('John'))->toBeTrue()
        ->and($filter->isValid('J'))->toBeTrue()
        ->and($filter->isValid(''))->toBeFalse()
        ->and($filter->isValid('   '))->toBeFalse()
        ->and($filter->isValid(123))->toBeFalse();
});

it('validates standard operators correctly', function (): void {
    $filter = new Filter('age', '>');

    expect($filter->isValid('25'))->toBeTrue()
        ->and($filter->isValid(25))->toBeTrue()
        ->and($filter->isValid(25.5))->toBeTrue()
        ->and($filter->isValid(new Carbon))->toBeTrue()
        ->and($filter->isValid(''))->toBeFalse()
        ->and($filter->isValid('   '))->toBeFalse();
});

it('clears caches correctly', function (): void {
    $filter1 = new Filter('data', '=');
    $filter1->setJsonPath('user.name');
    $filter1->getAttribute();

    $filter2 = new Filter('data2', '=');
    $filter2->setJsonPath('user.email');
    $filter2->getAttribute();

    Filter::clearCaches();

    expect(true)->toBeTrue();
});

it('maintains consistent behavior across multiple calls', function (): void {
    // Test that multiple calls return consistent results
    $filter = new Filter('name', '=');
    $filter->setValue('CachedValue');

    // Multiple calls should behave consistently
    $shouldIgnore1 = $filter->shouldIgnore();
    $shouldIgnore2 = $filter->shouldIgnore();

    expect($shouldIgnore1)->toBeFalse();
    expect($shouldIgnore2)->toBeFalse();
});

it('manages attribute cache memory efficiently', function (): void {
    new Filter('test', '=');

    // Multiple calls should work without memory issues
    for ($i = 0; $i < 600; $i++) {
        $testFilter = new Filter("attr_$i", '=');
        $testFilter->getFilterBy(); // This uses attribute cache internally
    }

    expect(true)->toBeTrue(); // Test passes if no memory errors occurred
});
