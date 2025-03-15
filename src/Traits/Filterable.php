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
        $relationshipFilters = [];
        $directFilters = [];
        $relationshipsToLoad = [];

        foreach ($filters as $filter) {
            if (!($filter instanceof Filter)) {
                throw new InvalidArgumentException('Filterable must be an instance of Filter');
            }
            if ($filter->shouldIgnore()) {
                continue;
            }
            $relationship = $filter->getRelationship();
            if ($relationship !== null && $relationship !== '' && $relationship !== '0') {
                $relationshipFilters[] = $filter;
                if ($filter->shouldWith()) {
                    $relationshipsToLoad[] = $relationship;
                }
            } else {
                $directFilters[] = $filter;
            }
        }

        foreach ($directFilters as $filter) {
            $value = $filter->getValue();
            $attribute = empty($this->filterMap[$filter->getFilterBy()]) ? $filter->getAttribute() : $this->filterMap[$filter->getFilterBy()];

            if ($filter->getOperator() === 'BETWEEN') {
                if (is_array($value) && count($value) === 2) {
                    $builder->whereBetween($attribute, $value);
                    continue;
                }
                throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
            }

            if ($filter->getJsonPath() !== null && $filter->getJsonPath() !== '' && $filter->getJsonPath() !== '0') {
                $attribute = DB::raw($attribute);
            }

            if ($filter->getOperator() === 'IN') {
                $builder->whereIn($attribute, $value);
            } elseif ($value instanceof Carbon && $filter->isDate()) {
                $builder->whereBetween($attribute, [$value->startOfDay(), $value->endOfDay()]);
            } else {
                $builder->where($attribute, $filter->getOperator(), $value);
            }
        }

        $groupedByRelationship = [];
        foreach ($relationshipFilters as $filter) {
            $relationship = $filter->getRelationship();
            if (!isset($groupedByRelationship[$relationship])) {
                $groupedByRelationship[$relationship] = [];
            }
            $groupedByRelationship[$relationship][] = $filter;
        }

        foreach ($groupedByRelationship as $relationship => $relationshipFilters) {
            $builder->whereHas($relationship, function ($query) use ($relationshipFilters): void {
                $hasConditionalLogic = false;
                foreach ($relationshipFilters as $filter) {
                    if ($filter->getConditionalLogic() !== null && $filter->getConditionalLogic() !== '' && $filter->getConditionalLogic() !== '0') {
                        $hasConditionalLogic = true;
                        break;
                    }
                }

                if ($hasConditionalLogic) {
                    $conditions = [];
                    foreach ($relationshipFilters as $filter) {
                        if ($filter->getConditionalLogic() !== null && $filter->getConditionalLogic() !== '' && $filter->getConditionalLogic() !== '0') {
                            $conditions = array_merge($conditions, $filter->getConditionalConditions());
                        } else {
                            $value = $filter->getValue();
                            $attribute = $filter->getAttribute();
                            $conditions[] = [$attribute, $filter->getOperator(), $value];
                        }
                    }

                    $logic = $relationshipFilters[0]->getConditionalLogic();
                    if ($logic === 'any') {
                        $query->whereAny($conditions);
                    } elseif ($logic === 'all') {
                        $query->whereAll($conditions);
                    } elseif ($logic === 'none') {
                        $query->whereNone($conditions);
                    }
                } else {
                    foreach ($relationshipFilters as $filter) {
                        $value = $filter->getValue();
                        $attribute = empty($this->filterMap[$filter->getFilterBy()]) ? $filter->getAttribute() : $this->filterMap[$filter->getFilterBy()];

                        if ($filter->getOperator() === 'BETWEEN') {
                            if (is_array($value) && count($value) === 2) {
                                $query->whereBetween($attribute, $value);
                                continue;
                            }
                            throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
                        }

                        if ($filter->getOperator() === 'IN') {
                            $query->whereIn($attribute, $value);
                        } elseif ($value instanceof Carbon && $filter->isDate()) {
                            $query->whereBetween($attribute, [$value->startOfDay(), $value->endOfDay()]);
                        } else {
                            $query->where($attribute, $filter->getOperator(), $value);
                        }
                    }
                }
            });
        }

        if ($relationshipsToLoad !== []) {
            $builder->with(array_unique($relationshipsToLoad));
        }

        return $builder;
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
