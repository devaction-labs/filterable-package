<?php

namespace DevactionLabs\FilterablePackage\Traits;

use DevactionLabs\FilterablePackage\Filter;
use DevactionLabs\FilterablePackage\CacheManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

trait Filterable
{
    protected string $defaultSort = '';
    protected array $allowedSorts = [];
    protected array $filterMap = [];

    public function scopeCustomPaginate(
        Builder $builder,
        bool $useSimplePaginate = false,
        ?array $data = null
    ): Paginator|LengthAwarePaginator {
        $data ??= Request::only('per_page', 'sort');

        $cacheKey = CacheManager::getPrefix().'paginate_'.md5(json_encode($data));

        return CacheManager::remember($cacheKey, CacheManager::getTtl(), function () use ($builder, $useSimplePaginate, $data) {
            $order   = 'ASC';
            $perPage = (int) ($data['per_page'] ?? 15);

            if ($this->defaultSort && empty($data['sort'])) {
                $data['sort'] = $this->defaultSort;
            }

            if (!empty($data['sort'])) {
                $orderBy = ltrim($data['sort'], '-');
                $order = str_starts_with($data['sort'], '-') ? 'DESC' : 'ASC';

                if ($this->allowedSorts && !in_array($orderBy, $this->allowedSorts, true)) {
                    throw new InvalidArgumentException("Invalid sort [$orderBy]");
                }

                $orderBy = $this->filterMap[$orderBy] ?? $orderBy;

                $builder->orderBy($orderBy, $order);
            }

            return $useSimplePaginate
                ? $builder->simplePaginate($perPage)->appends($data)
                : $builder->paginate($perPage)->appends($data);
        });
    }

    public function scopeFilterable(Builder $builder, array $filters): Builder
    {
        $cacheKey = CacheManager::getPrefix().'filters_'.md5(json_encode(Request::query('filter', [])));

        return CacheManager::remember($cacheKey, CacheManager::getTtl(), function () use ($builder, $filters) {
            foreach ($filters as $filter) {
                if (!$filter->isValid($filter->getValue())) {
                    continue;
                }

                $attribute = $this->filterMap[$filter->getFilterBy()] ?? $filter->getAttribute();

                $operator = $filter->getOperator();
                $value = $filter->getValue();

                if ($filter->getRelationship()) {
                    $builder->whereHas($filter->getRelationship(), function ($query) use ($attribute, $operator, $value) {
                        $query->where($attribute, $operator, $value);
                    });
                    continue;
                }

                match ($operator) {
                    'BETWEEN' => $builder->whereBetween($attribute, $value),
                    'IN'      => $builder->whereIn($attribute, $value),
                    default   => $builder->where($attribute, $operator, $value),
                };
            }

            return $builder;
        });
    }
}
