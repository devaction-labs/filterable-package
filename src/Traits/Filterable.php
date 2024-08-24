<?php

namespace DevactionLabs\FilterablePackage\Traits;

use DevactionLabs\FilterablePackage\Filter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

trait Filterable
{
    protected string $defaultSort = '';

    protected array $allowedSorts = [];

    protected array $filterMap = [];

    /**
     * @param Builder $builder
     * @param bool $useSimplePaginate
     * @param array<string, mixed>|null $data
     * @return Paginator|LengthAwarePaginator
     */
    public function scopeCustomPaginate(Builder $builder, bool $useSimplePaginate = false, ?array $data = null): Paginator|LengthAwarePaginator
    {
        $data = $data ?? [];

        $order   = 'ASC';
        $perPage = $data['per_page'] ?? 15;

        if ($this->defaultSort && empty($data['sort'])) {
            $data['sort'] = $this->defaultSort;
        }

        if (!empty($data['sort'])) {
            $orderBy = $data['sort'];

            if ($data['sort'][0] === '-') {
                $orderBy = substr($data['sort'], 1);
                $order   = 'DESC';
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
        foreach ($filters as $filter) {
            if (!($filter instanceof Filter)) {
                throw new InvalidArgumentException('Filterable must be an instance of Filter');
            }

            $value = $filter->getValue();

            if (!$filter->isValid($value)) {
                continue;
            }

            $attribute = $filter->getAttribute();

            if (!empty($this->filterMap[$attribute])) {
                $attribute = $this->filterMap[$attribute];
            }

            if ($filter->getOperator() === 'IN') {
                $builder->whereIn($attribute, $value);
            } elseif ($value instanceof Carbon && $filter->isDate()) {
                $startDate = $value->clone()->startOfDay();
                $endDate   = $value->clone()->endOfDay();

                $builder->whereBetween($attribute, [$startDate, $endDate]);
            } else {
                $builder->where($attribute, $filter->getOperator(), $value);
            }
        }

        return $builder;
    }

    public function scopeAllowedSorts(Builder $builder, array $allowedSorts, string $defaultSort = ''): Builder
    {
        $this->defaultSort  = $defaultSort;
        $this->allowedSorts = $allowedSorts;

        return $builder;
    }

    public function scopeFilterMap(Builder $builder, array $filterMap): Builder
    {
        $this->filterMap = $filterMap;

        return $builder;
    }
}