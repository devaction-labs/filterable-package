<?php

namespace DevactionLabs\FilterablePackage;

use Carbon\Carbon;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

class Filter
{
    protected string $attribute;

    protected string $filterBy;

    /**
     * @var string|array<int, string>|Carbon|int|null
     */
    protected string|array|Carbon|int|null $value = null;

    protected string $operator;

    protected string $likePattern = '%{{value}}%';

    protected bool $endOfDay = false;

    protected bool $startOfDay = false;

    protected bool $isDate = false;

    protected string|int|null $default = null;

    public function __construct(string $attribute, string $operator, ?string $filterBy = null)
    {
        $this->filterBy = $filterBy ?? $attribute;
        $this->attribute = $attribute;
        $this->operator = $operator;

        $this->setValueFromRequest();
    }

    public function setValueFromRequest(): void
    {
        $filters = Request::query('filter', []);

        if (isset($filters[$this->filterBy]) && $this->isValid($filters[$this->filterBy])) {
            $value = $filters[$this->filterBy];

            if ($this->operator === 'LIKE') {
                $value = str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === 'IN') {
                $value = explode(',', $value);
            }

            $this->value = $value;
        }
    }


    public function isValid(mixed $value): bool
    {
        if (is_array($value) && empty($value)) {
            return false;
        }

        return $value !== '' && $value !== null;
    }

    public static function exact(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, '=', $filterBy);
    }

    public static function like(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, 'LIKE', $filterBy);
    }

    public static function generic(string $attribute, string $operator, ?string $filterBy = null): self
    {
        return new self($attribute, $operator, $filterBy);
    }

    public static function in(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, 'IN', $filterBy);
    }

    public static function gte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, '>=', $filterBy);
    }

    public static function gt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, '>', $filterBy);
    }

    public static function lte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, '<=', $filterBy);
    }

    public static function lt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, '<', $filterBy);
    }

    public function castDate(): self
    {
        $this->isDate = true;

        return $this;
    }

    public function setLikePattern(string $pattern): self
    {
        $this->likePattern = $pattern;

        return $this;
    }

    public function setDefault(string|int|null $default): self
    {
        $this->default = $default;

        return $this;
    }

    public function setFilterBy(string $filterBy): self
    {
        $this->filterBy = $filterBy;

        return $this;
    }

    /**
     * @return string|array<int, string>|Carbon|int|null
     */
    public function getValue(): string|array|Carbon|int|null
    {
        if (!$this->isValid($this->value) && $this->isValid($this->default)) {
            $this->value = $this->default;
        }

        if ($this->endOfDay && $this->value) {
            $this->value = $this->convertToCarbon($this->value);
            $this->value->endOfDay();
        }

        if ($this->startOfDay && $this->value) {
            $this->value = $this->convertToCarbon($this->value);
            $this->value->startOfDay();
        }

        if ($this->isDate && $this->value) {
            $this->value = $this->convertToCarbon($this->value);
        }

        return $this->value;
    }

    /**
     * @param string|int|array<int, string>|Carbon|null $value
     * @return self
     */
    public function setValue(string|int|array|Carbon|null $value): self
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new InvalidArgumentException('Array values must be of type string');
                }
            }
        }

        $this->value = $value;

        return $this;
    }

    /**
     * @param string|int|array<int, string>|Carbon|null $value
     * @return Carbon
     */
    private function convertToCarbon(string|int|array|Carbon|null $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException('Array values cannot be converted to Carbon instances');
        }

        return new Carbon($value);
    }

    public function endOfDay(): self
    {
        $this->endOfDay = true;

        return $this;
    }

    public function startOfDay(): self
    {
        $this->startOfDay = true;

        return $this;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function shouldIgnore(): bool
    {
        return empty($this->value);
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function isDate(): bool
    {
        return $this->isDate;
    }
}
