<?php

namespace DevactionLabs\FilterablePackage\Traits;

use Carbon\Carbon;
use DevactionLabs\FilterablePackage\Enums\PaginationType;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ValueError;

trait Filterable
{
    protected string $defaultSort = '';

    /** @var array<int, string> */
    protected array $allowedSorts = [];

    /** @var array<string, string> */
    protected array $filterMap = [];

    /** @var array<string, string> */
    private array $attributeCache = [];

    /**
     * Custom pagination with support for paginate, simplePaginate, and cursorPaginate
     *
     * @param  string|PaginationType  $paginationType  Type of pagination: 'paginate', 'simple', 'cursor' or PaginationType enum
     * @param  int|null  $perPage  Items per page (default: 15)
     * @param  array|null  $data  Additional data to append to pagination links
     *
     * @throws InvalidArgumentException
     */
    public function scopeCustomPaginate(
        Builder $builder,
        string|PaginationType $paginationType = 'paginate',
        ?int $perPage = null,
        ?array $data = null
    ): Paginator|LengthAwarePaginator|CursorPaginator {
        $data ??= request()->only('per_page', 'sort');
        $order = 'ASC';
        $perPage ??= (int) ($data['per_page'] ?? 15);

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
                throw new InvalidArgumentException(sprintf('The sort value [%s] is not acceptable', $orderBy));
            }

            if (! empty($this->filterMap[$orderBy])) {
                $orderBy = $this->filterMap[$orderBy];
            }

            $builder->orderBy($orderBy, $order);
        }

        // Convert string to enum if needed
        try {
            $type = is_string($paginationType) ? PaginationType::from($paginationType) : $paginationType;
        } catch (ValueError $valueError) {
            throw new InvalidArgumentException(sprintf("Invalid pagination type [%s]. Use 'paginate', 'simple', or 'cursor'.", $paginationType), 0, $valueError);
        }

        return match ($type) {
            PaginationType::SIMPLE => $builder->simplePaginate($perPage)->appends($data),
            PaginationType::CURSOR => $builder->cursorPaginate($perPage)->appends($data),
            PaginationType::PAGINATE => $builder->paginate($perPage)->appends($data),
        };
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

        if (! empty($relationshipsToLoad)) {
            $builder->with(array_keys($relationshipsToLoad));
        }

        return $builder;
    }

    /**
     * Categorize filters into relationship and direct filters
     *
     * @param  array<int, Filter>  $filters
     * @return array{0: array<int, Filter>, 1: array<int, Filter>, 2: array<string, bool>}
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
     */
    private function isValidRelationship(?string $relationship): bool
    {
        return ! in_array($relationship, [null, '', '0'], true);
    }

    /**
     * Add a filter to the relationship filters and track relationships to load
     */
    private function addRelationshipFilter(array &$relationshipFilters, array &$relationshipsToLoad, Filter $filter, string $relationship): void
    {
        $relationshipFilters[] = $filter;

        if ($filter->shouldWith()) {
            $relationshipsToLoad[$relationship] = true;
        }
    }

    /**
     * Apply direct filters to the builder
     *
     * @param  array<int, Filter>  $directFilters
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
     * @param  array<int, Filter>  $relationshipFilters
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

                continue;
            }

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
            if (! in_array($logic, [null, '', '0'], true)) {
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
     */
    private function collectConditions(array $filters): array
    {
        $conditions = [];

        foreach ($filters as $filter) {
            if ($this->hasFilterConditionalLogic($filter)) {
                $this->addConditionalConditions($conditions, $filter->getConditionalConditions());

                continue;
            }

            $this->addStandardCondition($conditions, $filter);
        }

        return $conditions;
    }

    /**
     * Check if a filter has conditional logic
     */
    private function hasFilterConditionalLogic(Filter $filter): bool
    {
        return ! in_array($filter->getConditionalLogic(), [null, '', '0'], true);
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

        if ($attribute instanceof Expression) {
            $builder->whereRaw('LOWER('.$attribute->getValue().') LIKE LOWER(?)', [$value]);

            return;
        }

        $sanitizedAttribute = preg_replace('/[^a-zA-Z0-9_.]/', '', $attribute);
        $builder->whereRaw('LOWER(`'.$sanitizedAttribute.'`) LIKE LOWER(?)', [$value]);
    }

    /**
     * Apply full-text search filter
     */
    private function applyFullTextSearch(Builder $builder, Filter $filter, mixed $searchTerm): void
    {
        if (! is_string($searchTerm) || trim($searchTerm) === '') {
            return;
        }

        $columns = $filter->getFullTextColumns() ?? [$filter->getAttribute()];
        $language = $filter->getFullTextLanguage();
        $prefixMatch = $filter->getFullTextPrefixMatch();
        $isTsVector = $filter->isTsVector();

        if ($filter->isUsingPostgreSQL()) {
            $this->applyPostgreSQLFullTextSearch($builder, $searchTerm, $columns, $language, $prefixMatch, $isTsVector);

            return;
        }

        $this->applyGenericFullTextSearch($builder, $searchTerm, $columns, $prefixMatch);
    }

    /**
     * Validate that a column name is safe to interpolate into raw SQL.
     * Allows: letters, digits, underscores, and a single dot (schema.table or table.column).
     *
     * @throws InvalidArgumentException
     */
    private function assertSafeColumnName(string $column): void
    {
        if (! preg_match('/^\w+(\.\w+)?$/', $column)) {
            throw new InvalidArgumentException(
                sprintf('Invalid column name [%s] for full-text search. Only alphanumeric characters, underscores, and a single dot are allowed.', $column)
            );
        }
    }

    /**
     * Apply PostgreSQL full-text search
     *
     * @param  array<int, string>  $columns
     */
    private function applyPostgreSQLFullTextSearch(Builder $builder, string $searchTerm, array $columns, ?string $language, bool $prefixMatch, bool $isTsVector = false): void
    {
        $lang = $language ?? Config::get('app.fulltext_language', 'simple');

        if (! preg_match('/^[a-zA-Z_]\w*$/', (string) $lang)) {
            throw new InvalidArgumentException(
                sprintf('Invalid full-text search language [%s]. Only alphanumeric characters and underscores are allowed.', $lang)
            );
        }

        if ($isTsVector && count($columns) === 1) {
            $column = $columns[0];
            $this->assertSafeColumnName($column);
            $builder->whereRaw(sprintf("%s @@ websearch_to_tsquery('%s', ?)", $column, $lang), [$searchTerm]);

            return;
        }

        array_walk($columns, fn (string $column) => $this->assertSafeColumnName($column));

        $tsVector = implode(' || ', array_map(
            static fn (string $column): string => sprintf("to_tsvector('%s', COALESCE(%s, ''))", $lang, $column),
            $columns
        ));

        $words = preg_split('/\s+/', trim($searchTerm));
        $processedWords = array_filter(
            array_map(
                static fn ($word): ?string => preg_replace('/[^\w\s\-]/u', '', $word),
                $words
            ),
            static fn (?string $word): bool => $word !== ''
        );

        $tsquery = implode(' & ', array_map(
            static fn ($word): string => $prefixMatch ? $word.':*' : $word,
            $processedWords
        ));

        if ($tsquery === '' || $tsquery === '0') {
            return;
        }

        $builder->whereRaw(
            sprintf("(%s) @@ to_tsquery('%s', ?)", $tsVector, $lang),
            [$tsquery]
        );
    }

    /**
     * Apply generic full-text search (fallback for non-PostgreSQL databases)
     *
     * @param  array<int, string>  $columns
     */
    private function applyGenericFullTextSearch(Builder $builder, string $searchTerm, array $columns, bool $prefixMatch): void
    {
        $likePattern = $prefixMatch ? '%'.$searchTerm.'%' : $searchTerm;

        $builder->where(function ($query) use ($columns, $likePattern): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', $likePattern);
            }
        });
    }

    /**
     * Check if a filter has a JSON path
     */
    private function hasJsonPath(Filter $filter): bool
    {
        $path = $filter->getJsonPath();

        return ! in_array($path, [null, '', '0'], true);
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

        if ($filter->getOperator() === 'FULL_TEXT') {
            $this->applyFullTextSearch($builder, $filter, $value);

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
