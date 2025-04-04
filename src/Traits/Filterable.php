<?php

namespace DevactionLabs\FilterablePackage\Traits;

use DevactionLabs\FilterablePackage\Filter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

trait Filterable
{
    protected string $defaultSort = '';
    protected array $allowedSorts = [];
    protected array $filterMap = [];

    public function scopeCustomPaginate(Builder $builder, bool $useSimplePaginate = false, ?array $data = null): Paginator|LengthAwarePaginator
    {
        $data ??= request()->only('per_page', 'sort');
        $order = 'ASC';
        $perPage = $data['per_page'] ?? 15;

        if ($this->defaultSort && empty($data['sort'])) {
            $data['sort'] = $this->defaultSort;
        }

        if (!empty($data['sort'])) {
            $orderBy = $data['sort'];
            if ($data['sort'][0] === '-') {
                $orderBy = substr((string) $data['sort'], 1);
                $order = 'DESC';
            }
            if (!empty($this->allowedSorts) && !in_array($orderBy, $this->allowedSorts, true)) {
                throw new InvalidArgumentException("The sort value [$orderBy] is not acceptable");
            }
            if (!empty($this->filterMap[$orderBy])) {
                $orderBy = $this->filterMap[$orderBy];
            }
            $builder->orderBy($orderBy, $order);
        }

        return $useSimplePaginate
            ? $builder->simplePaginate((int) $perPage)->appends($data)
            : $builder->paginate((int) $perPage)->appends($data);
    }

    public function scopeFiltrable(Builder $builder, array $filters): Builder
    {
        return $this->scopeFilterable($builder, $filters);
    }

    public function scopeFilterable(Builder $builder, array $filters): Builder
    {
        [$relationshipFilters, $directFilters, $relationshipsToLoad] = $this->categorizeFilters($filters);
        
        $this->applyDirectFilters($builder, $directFilters);
        $this->applyRelationshipFilters($builder, $relationshipFilters);
        
        if (!empty($relationshipsToLoad)) {
            $builder->with(array_unique($relationshipsToLoad));
        }

        return $builder;
    }
    
    /**
     * Categorize filters into relationship and direct filters
     *
     * @param array $filters
     * @return array
     */
    private function categorizeFilters(array $filters): array
    {
        $relationshipFilters = [];
        $directFilters = [];
        $relationshipsToLoad = [];
        
        foreach ($filters as $filter) {
            $this->validateFilter($filter);
            
            if ($filter->shouldIgnore()) {
                continue;
            }
            
            $relationship = $filter->getRelationship();
            $this->isValidRelationship($relationship)
                ? $this->addRelationshipFilter($relationshipFilters, $relationshipsToLoad, $filter, $relationship)
                : $directFilters[] = $filter;
        }
        
        return [$relationshipFilters, $directFilters, $relationshipsToLoad];
    }
    
    /**
     * Validate that the provided filter is valid
     *
     * @param mixed $filter
     * @throws InvalidArgumentException
     */
    private function validateFilter(mixed $filter): void
    {
        if (!($filter instanceof Filter)) {
            throw new InvalidArgumentException('Filterable must be an instance of Filter');
        }
    }
    
    /**
     * Check if a relationship value is valid
     *
     * @param string|null $relationship
     * @return bool
     */
    private function isValidRelationship(?string $relationship): bool
    {
        return $relationship !== null && $relationship !== '' && $relationship !== '0';
    }
    
    /**
     * Add a filter to the relationship filters and track relationships to load
     *
     * @param array $relationshipFilters
     * @param array $relationshipsToLoad
     * @param Filter $filter
     * @param string $relationship
     */
    private function addRelationshipFilter(array &$relationshipFilters, array &$relationshipsToLoad, Filter $filter, string $relationship): void
    {
        $relationshipFilters[] = $filter;
        
        if ($filter->shouldWith()) {
            $relationshipsToLoad[] = $relationship;
        }
    }
    
    /**
     * Apply direct filters to the builder
     *
     * @param Builder $builder
     * @param array $directFilters
     */
    private function applyDirectFilters(Builder $builder, array $directFilters): void
    {
        foreach ($directFilters as $filter) {
            $value = $filter->getValue();
            $attribute = $this->resolveFilterAttribute($filter);
            
            if ($this->shouldApplyBetweenFilter($filter, $value)) {
                $this->applyBetweenFilter($builder, $attribute, $value);
                continue;
            }
            
            if ($this->hasJsonPath($filter)) {
                $attribute = DB::raw($attribute);
            }
            
            $this->applyFilterToBuilder($builder, $filter, $attribute, $value);
        }
    }
    
    /**
     * Apply relationship filters to the builder
     *
     * @param Builder $builder
     * @param array $relationshipFilters
     */
    private function applyRelationshipFilters(Builder $builder, array $relationshipFilters): void
    {
        $groupedFilters = $this->groupFiltersByRelationship($relationshipFilters);
        
        foreach ($groupedFilters as $relationship => $filters) {
            $builder->whereHas($relationship, function ($query) use ($filters): void {
                $hasConditionalLogic = $this->hasConditionalLogic($filters);
                
                $hasConditionalLogic
                    ? $this->applyFiltersWithConditionalLogic($query, $filters)
                    : $this->applyFiltersDirectly($query, $filters);
            });
        }
    }
    
    /**
     * Group filters by their relationship
     *
     * @param array $relationshipFilters
     * @return array
     */
    private function groupFiltersByRelationship(array $relationshipFilters): array
    {
        $grouped = [];
        
        foreach ($relationshipFilters as $filter) {
            $relationship = $filter->getRelationship();
            if (!isset($grouped[$relationship])) {
                $grouped[$relationship] = [];
            }
            $grouped[$relationship][] = $filter;
        }
        
        return $grouped;
    }
    
    /**
     * Check if any filter has conditional logic
     *
     * @param array $filters
     * @return bool
     */
    private function hasConditionalLogic(array $filters): bool
    {
        foreach ($filters as $filter) {
            $logic = $filter->getConditionalLogic();
            if ($logic !== null && $logic !== '' && $logic !== '0') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Apply filters with conditional logic
     *
     * @param Builder $query
     * @param array $filters
     */
    private function applyFiltersWithConditionalLogic(Builder $query, array $filters): void
    {
        $conditions = $this->collectConditions($filters);
        $this->applyConditionalLogicToQuery($query, $filters[0]->getConditionalLogic(), $conditions);
    }
    
    /**
     * Collect conditions from filters
     *
     * @param array $filters
     * @return array
     */
    private function collectConditions(array $filters): array
    {
        $conditions = [];
        
        foreach ($filters as $filter) {
            $this->hasFilterConditionalLogic($filter)
                ? $this->addConditionalConditions($conditions, $filter->getConditionalConditions())
                : $this->addStandardCondition($conditions, $filter);
        }
        
        return $conditions;
    }
    
    /**
     * Check if a filter has conditional logic
     *
     * @param Filter $filter
     * @return bool
     */
    private function hasFilterConditionalLogic(Filter $filter): bool
    {
        $logic = $filter->getConditionalLogic();
        return $logic !== null && $logic !== '' && $logic !== '0';
    }
    
    /**
     * Add conditional conditions to the conditions array
     *
     * @param array $conditions
     * @param array $newConditions
     */
    private function addConditionalConditions(array &$conditions, array $newConditions): void
    {
        foreach ($newConditions as $condition) {
            $conditions[] = $condition;
        }
    }
    
    /**
     * Add a standard condition from a filter
     *
     * @param array $conditions
     * @param Filter $filter
     */
    private function addStandardCondition(array &$conditions, Filter $filter): void
    {
        $value = $filter->getValue();
        $attribute = $filter->getAttribute();
        $conditions[] = [$attribute, $filter->getOperator(), $value];
    }
    
    /**
     * Apply conditional logic to a query
     *
     * @param Builder $query
     * @param string|null $logic
     * @param array $conditions
     */
    private function applyConditionalLogicToQuery(Builder $query, ?string $logic, array $conditions): void
    {
        if ($logic === 'any') {
            $query->whereAny($conditions);
            return;
        }
        
        if ($logic === 'all') {
            $query->whereAll($conditions);
            return;
        }
        
        if ($logic === 'none') {
            $query->whereNone($conditions);
        }
    }
    
    /**
     * Apply filters directly to a query
     *
     * @param Builder $query
     * @param array $filters
     */
    private function applyFiltersDirectly(Builder $query, array $filters): void
    {
        foreach ($filters as $filter) {
            $value = $filter->getValue();
            $attribute = $this->resolveFilterAttribute($filter);
            
            if ($this->shouldApplyBetweenFilter($filter, $value)) {
                $this->applyBetweenFilter($query, $attribute, $value);
                continue;
            }
            
            $this->applyFilterToBuilder($query, $filter, $attribute, $value);
        }
    }
    
    /**
     * Resolve the attribute name for a filter
     *
     * @param Filter $filter
     * @return string
     */
    private function resolveFilterAttribute(Filter $filter): string
    {
        $filterBy = $filter->getFilterBy();
        return empty($this->filterMap[$filterBy]) ? $filter->getAttribute() : $this->filterMap[$filterBy];
    }
    
    /**
     * Check if a between filter should be applied
     *
     * @param Filter $filter
     * @param mixed $value
     * @return bool
     * @throws InvalidArgumentException
     */
    private function shouldApplyBetweenFilter(Filter $filter, mixed $value): bool
    {
        if ($filter->getOperator() !== 'BETWEEN') {
            return false;
        }
        
        if (!is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
        }
        
        return true;
    }
    
    /**
     * Apply a between filter to a builder
     *
     * @param Builder $builder
     * @param string $attribute
     * @param array $value
     */
    private function applyBetweenFilter(Builder $builder, string $attribute, array $value): void
    {
        $builder->whereBetween($attribute, $value);
    }
    
    /**
     * Check if a filter has a JSON path
     *
     * @param Filter $filter
     * @return bool
     */
    private function hasJsonPath(Filter $filter): bool
    {
        $path = $filter->getJsonPath();
        return $path !== null && $path !== '' && $path !== '0';
    }
    
    /**
     * Apply a filter to a builder
     *
     * @param Builder $builder
     * @param Filter $filter
     * @param string $attribute
     * @param mixed $value
     */
    private function applyFilterToBuilder(Builder $builder, Filter $filter, string $attribute, mixed $value): void
    {
        if ($filter->getOperator() === 'IN') {
            $builder->whereIn($attribute, $value);
            return;
        }
        
        if ($value instanceof Carbon && $filter->isDate()) {
            $builder->whereBetween($attribute, [$value->startOfDay(), $value->endOfDay()]);
            return;
        }
        
        $builder->where($attribute, $filter->getOperator(), $value);
    }

    public function scopeAllowedSorts(Builder $builder, array $allowedSorts, string $defaultSort = ''): Builder
    {
        $this->defaultSort = $defaultSort;
        $this->allowedSorts = $allowedSorts;
        return $builder;
    }

    public function scopeFilterMap(Builder $builder, array $filterMap): Builder
    {
        $this->filterMap = $filterMap;
        return $builder;
    }
}