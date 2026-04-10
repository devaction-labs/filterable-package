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
        $filter1 = $this->createRelationshipFilter('user', 'name', 'John', true);
        $filter2 = $this->createRelationshipFilter('user', 'email', 'john@example.com', true);
        $filter3 = $this->createRelationshipFilter('user', 'age', 30, true);

        $this->query->shouldReceive('with')
            ->once()
            ->with(['user'])
            ->andReturnSelf();

        $this->query->shouldReceive('whereHas')
            ->once()
            ->andReturnSelf();

        $result = $this->model->scopeFilterable($this->query, [$filter1, $filter2, $filter3]);

        $this->assertSame($this->query, $result);
    }

    public function test_direct_filter_optimization(): void
    {
        $filter1 = $this->createDirectFilter('name', 'John');
        $filter2 = $this->createDirectFilter('email', 'john@example.com');

        $this->query->shouldReceive('where')
            ->times(4)
            ->andReturnSelf();

        $result = $this->model->scopeFilterable($this->query, [$filter1, $filter2]);

        $result = $this->model->scopeFilterable($this->query, [$filter1, $filter2]);

        $this->assertSame($this->query, $result);
    }

    public function test_simple_relationship_optimization(): void
    {
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

        for ($i = 0; $i < 50; $i++) {
            if ($i % 3 === 0) {
                $filters[] = $this->createRelationshipFilter('user', 'field'.$i, 'value'.$i, $i % 2 === 0);
                $relationshipFiltersCount++;
            } else {
                $filters[] = $this->createDirectFilter('field'.$i, 'value'.$i);
                $directFiltersCount++;
            }
        }

        $this->query->shouldReceive('where')
            ->times($directFiltersCount)
            ->andReturnSelf();

        $this->query->shouldReceive('whereHas')
            ->once()
            ->andReturnSelf();

        $this->query->shouldReceive('with')
            ->zeroOrMoreTimes()
            ->andReturnSelf();

        $start = microtime(true);

        $this->model->scopeFilterable($this->query, $filters);

        $end = microtime(true);
        $executionTime = ($end - $start) * 1000;

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
