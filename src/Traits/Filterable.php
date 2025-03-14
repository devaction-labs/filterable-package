<?php

namespace DevactionLabs\FilterablePackage\Traits;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use JsonException;

trait Filterable
{
    protected string $defaultSort = '';
    protected array $allowedSorts = [];
    protected array $filterMap = [];
    protected array $withRelations = [];

    /**
     * @throws JsonException
     */
    public function scopeCustomPaginate(
        Builder $builder,
        bool $useSimplePaginate = false,
        ?array $data = null
    ): Paginator|LengthAwarePaginator {
        $data ??= Request::only('per_page');

        $cacheKey = CacheManager::getPrefix() . 'paginate_' . md5(json_encode($data, JSON_THROW_ON_ERROR));

        $order = 'ASC';
        $perPage = (int) ($data['per_page'] ?? 15);

        if ($this->defaultSort && empty($data['sort'])) {
            $data['sort'] = $this->defaultSort;
        }

        if (!empty($data['sort'])) {
            $orderBy = ltrim((string) $data['sort'], '-');
            $order = str_starts_with((string) $data['sort'], '-') ? 'DESC' : 'ASC';

            if ($this->allowedSorts && !in_array($orderBy, $this->allowedSorts, true)) {
                throw new InvalidArgumentException("Invalid sort [$orderBy]");
            }

            $orderBy = $this->filterMap[$orderBy] ?? $orderBy;

            $builder->orderBy($orderBy, $order);
        }

        if (!empty($this->withRelations)) {
            $builder->with($this->withRelations);
        }

        $result = $useSimplePaginate
            ? $builder->simplePaginate($perPage)->appends($data)
            : $builder->paginate($perPage)->appends($data);

        return CacheManager::remember($cacheKey, CacheManager::getTtl(), function () use ($result) {
            return $result;
        });
    }

    /**
     * @throws JsonException
     */
    public function scopeFilterable(Builder $builder, array $filters): Builder
    {
        $relations = [];

        foreach ($filters as $filter) {
            if (!$filter->isValid($filter->getValue())) {
                continue;
            }

            $attribute = $this->filterMap[$filter->getFilterBy()] ?? $filter->getAttribute();
            $operator = $filter->getOperator();
            $value = $filter->getValue();

            if ($filter->getRelationship()) {
                $relations[] = $filter->getRelationship();

                $builder->whereHas($filter->getRelationship(), function ($query) use ($attribute, $operator, $value): void {
                    $query->where($attribute, $operator, $value);
                });
                continue;
            }

            if ($filter->getNestedRelationships()) {
                $relations[] = implode('.', $filter->getNestedRelationships());

                $nestedRelations = $filter->getNestedRelationships();
                $firstRelation = array_shift($nestedRelations);

                $builder->whereHas($firstRelation, function ($query) use ($nestedRelations, $attribute, $operator, $value): void {
                    $this->applyNestedWhereHas($query, $nestedRelations, $attribute, $operator, $value);
                });
                continue;
            }

            match ($operator) {
                'BETWEEN' => $builder->whereBetween($attribute, $value),
                'IN'      => $builder->whereIn($attribute, $value),
                default   => $builder->where($attribute, $operator, $value),
            };
        }

        if (!empty($relations)) {
            $this->withRelations = array_unique(array_merge($this->withRelations ?? [], $relations));
            $builder->with($this->withRelations);
        }

        $cacheKey = CacheManager::getPrefix() . 'filters_' . md5(json_encode(Request::query('filter', []), JSON_THROW_ON_ERROR));

        CacheManager::remember($cacheKey, CacheManager::getTtl(), function () {
            return true;
        });

        return $builder;
    }

    private function applyNestedWhereHas($query, array $relationships, string $attribute, string $operator, $value): void
    {
        if (empty($relationships)) {
            $query->where($attribute, $operator, $value);
            return;
        }

        $relation = array_shift($relationships);
        $query->whereHas($relation, function ($q) use ($relationships, $attribute, $operator, $value) {
            $this->applyNestedWhereHas($q, $relationships, $attribute, $operator, $value);
        });
    }

    public function setWithRelations(array $relations): self
    {
        $this->withRelations = $relations;
        return $this;
    }

    public function addWithRelation(string $relation): self
    {
        $this->withRelations[] = $relation;
        return $this;
    }
}
