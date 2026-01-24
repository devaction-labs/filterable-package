<?php

namespace Tests\Unit;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use Tests\TestCase;

class FilterableTestModel
{
    use Filterable;
}

class FilterablePerformanceTest extends TestCase
{
    private FilterableTestModel $model;

    private $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new FilterableTestModel;
        $this->query = Mockery::mock(Builder::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_relationship_load_optimization(): void
    {
        // Create filters with same relationship to test deduplication
        $filter1 = $this->createRelationshipFilter('user', 'name', 'John', true);
        $filter2 = $this->createRelationshipFilter('user', 'email', 'john@example.com', true);
        $filter3 = $this->createRelationshipFilter('user', 'age', 30, true);

        // In non-optimized code, we'd expect array_unique to be called
        // In optimized code, we use array keys which avoids duplicates

        $this->query->shouldReceive('with')
            ->once()
            ->with(['user'])
            ->andReturnSelf();

        // The trait groups filters by relationship, so 3 filters for 'user' = 1 whereHas call
        $this->query->shouldReceive('whereHas')
            ->once()
            ->andReturnSelf();

        $result = $this->model->scopeFilterable($this->query, [$filter1, $filter2, $filter3]);

        $this->assertSame($this->query, $result);
    }

    public function test_direct_filter_optimization(): void
    {
        // Set up multiple direct filters
        $filter1 = $this->createDirectFilter('name', 'John');
        $filter2 = $this->createDirectFilter('email', 'john@example.com');

        // Test that attribute resolution cache works
        // We call scopeFilterable twice with 2 filters each = 4 where calls total
        $this->query->shouldReceive('where')
            ->times(4)
            ->andReturnSelf();

        $result = $this->model->scopeFilterable($this->query, [$filter1, $filter2]);

        // Apply the same filters again - attributes should be cached
        $result = $this->model->scopeFilterable($this->query, [$filter1, $filter2]);

        $this->assertSame($this->query, $result);
    }

    public function test_simple_relationship_optimization(): void
    {
        // This test checks if the optimized version uses a simple where for basic equality filters
        $filter = $this->createRelationshipFilter('user', 'name', 'John', false);

        $this->query->shouldReceive('whereHas')
            ->once()
            ->with('user', Mockery::on(function ($callback): true {
                $query = Mockery::mock(Builder::class);
                $query->shouldReceive('where')
                    ->once()
                    ->with('name', 'John')
                    ->andReturnSelf();

                $callback($query);

                return true;
            }))
            ->andReturnSelf();

        $result = $this->model->scopeFilterable($this->query, [$filter]);

        $this->assertSame($this->query, $result);
    }

    public function test_conditional_logic_optimization(): void
    {
        // Test the optimized conditional logic handler
        $filter = $this->createConditionalFilter('user', 'any', [
            ['name', '=', 'John'],
            ['email', '=', 'john@example.com'],
        ]);

        $this->query->shouldReceive('whereHas')
            ->once()
            ->with('user', Mockery::on(function ($callback): true {
                $query = Mockery::mock(Builder::class);
                $query->shouldReceive('whereAny')->once()->andReturnSelf();

                $callback($query);

                return true;
            }))
            ->andReturnSelf();

        $result = $this->model->scopeFilterable($this->query, [$filter]);

        $this->assertSame($this->query, $result);
    }

    public function test_performance_with_many_filters(): void
    {
        $filters = [];
        $relationshipFiltersCount = 0;
        $directFiltersCount = 0;

        // Create 50 filters to test performance with larger datasets
        for ($i = 0; $i < 50; $i++) {
            if ($i % 3 === 0) {
                $filters[] = $this->createRelationshipFilter('user', 'field'.$i, 'value'.$i, $i % 2 === 0);
                $relationshipFiltersCount++;
            } else {
                $filters[] = $this->createDirectFilter('field'.$i, 'value'.$i);
                $directFiltersCount++;
            }
        }

        // Set up expectations for the filters
        $this->query->shouldReceive('where')
            ->times($directFiltersCount)
            ->andReturnSelf();

        // All relationship filters use 'user', so 1 whereHas call
        $this->query->shouldReceive('whereHas')
            ->once()
            ->andReturnSelf();

        // Some relationship filters have shouldWith = true
        $this->query->shouldReceive('with')
            ->zeroOrMoreTimes()
            ->andReturnSelf();

        $start = microtime(true);

        $this->model->scopeFilterable($this->query, $filters);

        $end = microtime(true);
        $executionTime = ($end - $start) * 1000; // Convert to milliseconds

        // Just verify execution completes in a reasonable time
        $this->assertLessThan(500, $executionTime, 'Filtering should complete in less than 500ms');
    }

    private function createDirectFilter(string $attribute, string $value): Filter
    {
        $filter = Mockery::mock(Filter::class);
        $filter->shouldReceive('shouldIgnore')->andReturn(false);
        $filter->shouldReceive('getRelationship')->andReturn(null);
        $filter->shouldReceive('getAttribute')->andReturn($attribute);
        $filter->shouldReceive('getValue')->andReturn($value);
        $filter->shouldReceive('getFilterBy')->andReturn($attribute);
        $filter->shouldReceive('getOperator')->andReturn('=');
        $filter->shouldReceive('getJsonPath')->andReturn(null);
        $filter->shouldReceive('isDate')->andReturn(false);

        return $filter;
    }

    private function createRelationshipFilter(string $relationship, string $attribute, string|int $value, bool $withRelation): Filter
    {
        $filter = Mockery::mock(Filter::class);
        $filter->shouldReceive('shouldIgnore')->andReturn(false);
        $filter->shouldReceive('getRelationship')->andReturn($relationship);
        $filter->shouldReceive('getAttribute')->andReturn($attribute);
        $filter->shouldReceive('getValue')->andReturn($value);
        $filter->shouldReceive('getFilterBy')->andReturn(sprintf('%s.%s', $relationship, $attribute));
        $filter->shouldReceive('getOperator')->andReturn('=');
        $filter->shouldReceive('shouldWith')->andReturn($withRelation);
        $filter->shouldReceive('getJsonPath')->andReturn(null);
        $filter->shouldReceive('isDate')->andReturn(false);
        $filter->shouldReceive('getConditionalLogic')->andReturn(null);

        return $filter;
    }

    private function createConditionalFilter(string $relationship, string $logic, array $conditions): Filter
    {
        $filter = Mockery::mock(Filter::class);
        $filter->shouldReceive('shouldIgnore')->andReturn(false);
        $filter->shouldReceive('getRelationship')->andReturn($relationship);
        $filter->shouldReceive('getAttribute')->andReturn('*');
        $filter->shouldReceive('getValue')->andReturn(null);
        $filter->shouldReceive('getFilterBy')->andReturn($relationship.'.*');
        $filter->shouldReceive('getOperator')->andReturn('=');
        $filter->shouldReceive('shouldWith')->andReturn(false);
        $filter->shouldReceive('getJsonPath')->andReturn(null);
        $filter->shouldReceive('isDate')->andReturn(false);
        $filter->shouldReceive('getConditionalLogic')->andReturn($logic);
        $filter->shouldReceive('getConditionalConditions')->andReturn($conditions);

        return $filter;
    }
}
