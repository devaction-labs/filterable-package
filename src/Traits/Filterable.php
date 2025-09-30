<?php

namespace DevactionLabs\FilterablePackage\Traits;

use Carbon\Carbon;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

trait Filterable
{
    protected string $defaultSort = '';

    protected array $allowedSorts = [];

    protected array $filterMap = [];

    private array $validationCache = [];

    private array $attributeCache = [];

    public function scopeCustomPaginate(Builder $builder, bool $useSimplePaginate = false, ?array $data = null): Paginator|LengthAwarePaginator
    {
        $data ??= request()->only('per_page', 'sort');
        $order = 'ASC';
        $perPage = $data['per_page'] ?? 15;

        if ($this->defaultSort && empty($data['sort'])) {
            $data['sort'] = $this->defaultSort;
        }

        if (! empty($data['sort'])) {
            $orderBy = $data['sort'];
            if ($data['sort'][0] === '-') {
                $orderBy = substr((string) $data['sort'], 1);
                $order = 'DESC';
            }
            if (! empty($this->allowedSorts) && ! in_array($orderBy, $this->allowedSorts, true)) {
                throw new InvalidArgumentException("The sort value [$orderBy] is not acceptable");
            }
            if (! empty($this->filterMap[$orderBy])) {
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

    /**
     * @throws JsonException
     */
    public function scopeFilterable(Builder $builder, array $filters): Builder
    {
        [$relationshipFilters, $directFilters, $relationshipsToLoad] = $this->categorizeFilters($filters);

        $this->applyDirectFilters($builder, $directFilters);
        $this->applyRelationshipFilters($builder, $relationshipFilters);

        if (! empty($relationshipsToLoad)) {
            $builder->with(array_keys($relationshipsToLoad));
        }

        return $builder;
    }

    /**
     * Categorize filters into relationship and direct filters
     *
     * @throws JsonException
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
     * @throws InvalidArgumentException
     */
    private function validateFilter(mixed $filter): void
    {
        if (! ($filter instanceof Filter)) {
            throw new InvalidArgumentException('Filterable must be an instance of Filter');
        }
    }

    /**
     * Check if a relationship value is valid
     *
     * @throws JsonException
     */
    private function isValidRelationship(?string $relationship): bool
    {
        $cacheKey = md5(json_encode($relationship ?? 'null', JSON_THROW_ON_ERROR));

        if (! isset($this->validationCache[$cacheKey])) {
            $this->validationCache[$cacheKey] = $relationship !== null && $relationship !== '' && $relationship !== '0';
        }

        return $this->validationCache[$cacheKey];
    }

    /**
     * Add a filter to the relationship filters and track relationships to load
     */
    private function addRelationshipFilter(array &$relationshipFilters, array &$relationshipsToLoad, Filter $filter, string $relationship): void
    {
        $relationshipFilters[] = $filter;

        if ($filter->shouldWith()) {
            // Usar array associativo como um "Set" (conjunto) para evitar duplicatas
            $relationshipsToLoad[$relationship] = true;
        }
    }

    /**
     * Apply direct filters to the builder
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
     */
    private function applyRelationshipFilters(Builder $builder, array $relationshipFilters): void
    {
        $groupedFilters = $this->groupFiltersByRelationship($relationshipFilters);

        foreach ($groupedFilters as $relationship => $filters) {
            if (count($filters) === 1
                && ! $this->hasConditionalLogic($filters)
                && $filters[0]->getOperator() === '='
                && ! $this->hasJsonPath($filters[0])) {

                $filter = $filters[0];
                $attribute = $filter->getAttribute();
                $value = $filter->getValue();

                $builder->whereHas($relationship, function ($query) use ($attribute, $value): void {
                    $query->where($attribute, $value);
                });
            } else {
                $builder->whereHas($relationship, function ($query) use ($filters): void {
                    $hasConditionalLogic = $this->hasConditionalLogic($filters);

                    $hasConditionalLogic
                        ? $this->applyFiltersWithConditionalLogic($query, $filters)
                        : $this->applyFiltersDirectly($query, $filters);
                });
            }
        }
    }

    /**
     * Group filters by their relationship
     */
    private function groupFiltersByRelationship(array $relationshipFilters): array
    {
        $grouped = [];

        foreach ($relationshipFilters as $filter) {
            $relationship = $filter->getRelationship();
            if (! isset($grouped[$relationship])) {
                $grouped[$relationship] = [];
            }
            $grouped[$relationship][] = $filter;
        }

        return $grouped;
    }

    /**
     * Check if any filter has conditional logic
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
     */
    private function applyFiltersWithConditionalLogic(Builder $query, array $filters): void
    {
        $conditions = $this->collectConditions($filters);
        $this->applyConditionalLogicToQuery($query, $filters[0]->getConditionalLogic(), $conditions);
    }

    /**
     * Collect conditions from filters
     *
     * @throws JsonException
     */
    private function collectConditions(array $filters): array
    {
        $estimatedSize = 0;
        foreach ($filters as $filter) {
            if ($this->hasFilterConditionalLogic($filter)) {
                $estimatedSize += count($filter->getConditionalConditions());
            } else {
                $estimatedSize++;
            }
        }

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
     * @throws JsonException
     */
    private function hasFilterConditionalLogic(Filter $filter): bool
    {
        $logic = $filter->getConditionalLogic();
        $cacheKey = 'logic_'.md5(json_encode($logic ?? 'null', JSON_THROW_ON_ERROR));

        if (! isset($this->validationCache[$cacheKey])) {
            $this->validationCache[$cacheKey] = $logic !== null && $logic !== '' && $logic !== '0';
        }

        return $this->validationCache[$cacheKey];
    }

    /**
     * Add conditional conditions to the conditions array
     */
    private function addConditionalConditions(array &$conditions, array $newConditions): void
    {
        foreach ($newConditions as $condition) {
            $conditions[] = $condition;
        }
    }

    /**
     * Add a standard condition from a filter
     */
    private function addStandardCondition(array &$conditions, Filter $filter): void
    {
        $value = $filter->getValue();
        $attribute = $filter->getAttribute();
        $conditions[] = [$attribute, $filter->getOperator(), $value];
    }

    /**
     * Apply conditional logic to a query
     */
    private function applyConditionalLogicToQuery(Builder $query, ?string $logic, array $conditions): void
    {
        switch ($logic) {
            case 'any':
                $query->whereAny($conditions);
                break;
            case 'all':
                $query->whereAll($conditions);
                break;
            case 'none':
                $query->whereNone($conditions);
                break;
        }
    }

    /**
     * Apply filters directly to a query
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
     */
    private function resolveFilterAttribute(Filter $filter): string
    {
        $filterBy = $filter->getFilterBy();
        $cacheKey = md5($filterBy);

        if (! isset($this->attributeCache[$cacheKey])) {
            $this->attributeCache[$cacheKey] = empty($this->filterMap[$filterBy])
                ? $filter->getAttribute()
                : $this->filterMap[$filterBy];
        }

        return $this->attributeCache[$cacheKey];
    }

    /**
     * Check if a between filter should be applied
     *
     * @throws InvalidArgumentException
     */
    private function shouldApplyBetweenFilter(Filter $filter, mixed $value): bool
    {
        if ($filter->getOperator() !== 'BETWEEN') {
            return false;
        }

        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
        }

        return true;
    }

    /**
     * Apply a between filter to a builder
     */
    private function applyBetweenFilter(Builder $builder, string $attribute, array $value): void
    {
        $builder->whereBetween($attribute, $value);
    }

    /**
     * Apply an ILIKE filter to a builder with database-specific handling
     */
    private function applyIlikeFilter(Builder $builder, Filter $filter, string|Expression $attribute, mixed $value): void
    {
        if ($filter->isUsingPostgreSQL()) {
            $builder->where($attribute, 'ILIKE', $value);

            return;
        }

        if ($filter->isUsingSQLite()) {
            $builder->where($attribute, 'LIKE', $value);

            return;
        }

        // For MySQL and other databases, use LOWER() for case-insensitive comparison
        // Using whereRaw with bindings to prevent SQL injection
        if ($attribute instanceof Expression) {
            $builder->whereRaw('LOWER('.$attribute->getValue().') LIKE LOWER(?)', [$value]);
        } else {
            // Sanitize column name: only allow alphanumeric, underscore, and dot (for table.column)
            $sanitizedAttribute = preg_replace('/[^a-zA-Z0-9_.]/', '', $attribute);
            $builder->whereRaw('LOWER(`'.$sanitizedAttribute.'`) LIKE LOWER(?)', [$value]);
        }
    }

    /**
     * Check if a filter has a JSON path
     */
    private function hasJsonPath(Filter $filter): bool
    {
        $path = $filter->getJsonPath();

        return $path !== null && $path !== '' && $path !== '0';
    }

    /**
     * Apply a filter to a builder
     */
    private function applyFilterToBuilder(Builder $builder, Filter $filter, string|Expression $attribute, mixed $value): void
    {
        if ($filter->getOperator() === 'IN') {
            $builder->whereIn($attribute, $value);

            return;
        }

        if ($filter->getOperator() === 'NOT IN') {
            $builder->whereNotIn($attribute, $value);

            return;
        }

        if ($filter->getOperator() === 'IS NULL') {
            $builder->whereNull($attribute);

            return;
        }

        if ($filter->getOperator() === 'IS NOT NULL') {
            $builder->whereNotNull($attribute);

            return;
        }

        if ($filter->getOperator() === 'STARTS_WITH') {
            $builder->where($attribute, 'LIKE', $value);

            return;
        }

        if ($filter->getOperator() === 'ENDS_WITH') {
            $builder->where($attribute, 'LIKE', $value);

            return;
        }

        if ($filter->getOperator() === 'NOT LIKE') {
            $builder->where($attribute, 'NOT LIKE', $value);

            return;
        }

        if ($filter->getOperator() === 'ILIKE') {
            $this->applyIlikeFilter($builder, $filter, $attribute, $value);

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
