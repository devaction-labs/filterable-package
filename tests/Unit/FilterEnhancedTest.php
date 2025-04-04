<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use InvalidArgumentException;
use Illuminate\Support\Facades\Request;
use Mockery;
use Carbon\Carbon;

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
    
    // Valid date
    $filter->setValue('2023-01-01');
    expect($filter->getValue())->toBeInstanceOf(Carbon::class);
    
    // Test the convertToCarbon method directly using reflection
    $reflector = new \ReflectionClass($filter);
    $method = $reflector->getMethod('convertToCarbon');
    $method->setAccessible(true);
    
    // Valid date
    expect($method->invokeArgs($filter, ['2023-01-01']))->toBeInstanceOf(Carbon::class);
    
    // Invalid date
    expect(fn () => $method->invokeArgs($filter, ['not-a-valid-date-format']))
        ->toThrow(InvalidArgumentException::class);
});

it('uses isEmptyOrZero helper method correctly', function (): void {
    global $filters;
    $filters = ['data' => json_encode(['user' => ['name' => 'John']], JSON_THROW_ON_ERROR)];

    // Test with valid json path
    $filter = Filter::json('data', 'user.name')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe("data->>'$.user.name'");
    
    // Test with empty json path
    $filter = Filter::json('data', '')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe('data');
    
    // Test with null json path
    $filter = Filter::exact('data')->setDatabaseDriver('mysql');
    expect($filter->getAttribute())->toBe('data');
});

it('handles database drivers correctly', function (): void {
    // Test if the correct database driver methods are called
    $filter = Filter::json('data', 'user.name');
    
    $reflector = new \ReflectionClass($filter);
    $mysqlMethod = $reflector->getMethod('isUsingMySQL');
    $sqliteMethod = $reflector->getMethod('isUsingSQLite');
    $pgsqlMethod = $reflector->getMethod('isUsingPostgreSQL');
    
    $mysqlMethod->setAccessible(true);
    $sqliteMethod->setAccessible(true);
    $pgsqlMethod->setAccessible(true);
    
    // Test MySQL
    $filter->setDatabaseDriver('mysql');
    expect($mysqlMethod->invoke($filter))->toBeTrue();
    expect($sqliteMethod->invoke($filter))->toBeFalse();
    expect($pgsqlMethod->invoke($filter))->toBeFalse();
    
    // Test SQLite
    $filter->setDatabaseDriver('sqlite');
    expect($mysqlMethod->invoke($filter))->toBeFalse();
    expect($sqliteMethod->invoke($filter))->toBeTrue();
    expect($pgsqlMethod->invoke($filter))->toBeFalse();
    
    // Test PostgreSQL
    $filter->setDatabaseDriver('pgsql');
    expect($mysqlMethod->invoke($filter))->toBeFalse();
    expect($sqliteMethod->invoke($filter))->toBeFalse();
    expect($pgsqlMethod->invoke($filter))->toBeTrue();
});

it('validates array values correctly', function (): void {
    $filter = Filter::in('tags');
    
    // Valid string array
    $filter->setValue(['tag1', 'tag2']);
    expect($filter->getValue())->toBe(['tag1', 'tag2']);
    
    // Valid mixed string/int array
    $filter->setValue(['tag1', 2]);
    expect($filter->getValue())->toBe(['tag1', 2]);
    
    // Invalid array
    expect(fn () => $filter->setValue(['tag1', []]))->toThrow(InvalidArgumentException::class);
});

it('uses match expressions for value transformation', function (): void {
    // Test with direct values rather than request values
    $jsonStr = json_encode(['user' => ['status' => 'active']], JSON_THROW_ON_ERROR);
    
    // Create a filter and manually set values to test the operators
    $filter = Filter::json('data', 'user.status', Filter::OPERATOR_EQUALS);
    $filter->setValue($jsonStr);
    
    // Extract a value using reflection to directly test applyOperatorToValue
    $reflector = new \ReflectionClass($filter);
    $method = $reflector->getMethod('applyOperatorToValue');
    $method->setAccessible(true);
    
    // Test EQUALS (default)
    expect($method->invokeArgs($filter, ['active']))->toBe('active');
    
    // Test LIKE
    $filter = Filter::json('data', 'user.status', Filter::OPERATOR_LIKE);
    expect($method->invokeArgs($filter, ['active']))->toBe('%active%');
    
    // Test IN with comma string
    $filter = Filter::json('data', 'user.status', Filter::OPERATOR_IN);
    expect($method->invokeArgs($filter, ['admin,user']))->toBe(['admin', 'user']);
});

it('applies date modifiers correctly', function (): void {
    $now = Carbon::now();
    $filter = Filter::exact('created_at')->castDate()->setValue($now->format('Y-m-d'));
    
    // Default (no modifiers)
    $date = $filter->getValue();
    expect($date->format('H:i:s'))->toBe('00:00:00');
    
    // With endOfDay
    $filter = Filter::exact('created_at')->castDate()->endOfDay()->setValue($now->format('Y-m-d'));
    $date = $filter->getValue();
    expect($date->format('H:i:s'))->toBe('23:59:59');
    
    // With startOfDay
    $filter = Filter::exact('created_at')->castDate()->startOfDay()->setValue($now->format('Y-m-d'));
    $date = $filter->getValue();
    expect($date->format('H:i:s'))->toBe('00:00:00');
});

it('prepares values correctly based on operator', function (): void {
    global $filters;
    
    // BETWEEN with comma string
    $filters = ['date_range' => '2023-01-01,2023-12-31'];
    $filter = Filter::between('created_at', 'date_range');
    expect($filter->getValue())->toBe(['2023-01-01', '2023-12-31']);
    
    // IN with comma string
    $filters = ['roles' => 'admin,user,guest'];
    $filter = Filter::in('role', 'roles');
    expect($filter->getValue())->toBe(['admin', 'user', 'guest']);
    
    // LIKE with pattern
    $filters = ['search' => 'test'];
    $filter = Filter::like('name', 'search');
    expect($filter->getValue())->toBe('%test%');
});