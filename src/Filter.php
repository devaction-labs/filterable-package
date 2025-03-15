<?php

namespace DevactionLabs\FilterablePackage;

use AllowDynamicProperties;
use Carbon\Carbon;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use JsonException;

class Filter
{
    protected string $attribute;
    protected string $filterBy;
    protected string|array|Carbon|int|null $value = null;
    protected string $likePattern = '%{{value}}%';
    protected bool $endOfDay = false;
    protected bool $startOfDay = false;
    protected bool $isDate = false;
    protected ?string $jsonPath = null;
    protected ?string $relationship = null;
    protected string|int|null $default = null;
    protected ?string $databaseDriver = null;
    protected static ?string $cachedDatabaseDriver = null;
    protected bool $withRelationship = false;

    public function __construct(string $attribute, protected string $operator, ?string $filterBy = null)
    {
        $this->filterBy = $filterBy ?? $attribute;
        $this->attribute = $attribute;
        $this->setValueFromRequest();
    }

    public function setValueFromRequest(): void
    {
        $filters = Request::query('filter', []);
        if (!isset($filters[$this->filterBy]) || !$this->isValid($filters[$this->filterBy])) {
            return;
        }

        $value = $filters[$this->filterBy];

        if ($this->operator === 'BETWEEN' && is_string($value) && str_contains($value, ',')) {
            $value = explode(',', $value);
        }

        if ($this->jsonPath !== null && $this->jsonPath !== '' && $this->jsonPath !== '0') {
            $value = $this->extractJsonValue($value);
        }

        if ($this->operator === 'LIKE') {
            $value = str_replace('{{value}}', $value, $this->likePattern);
        }

        if ($this->operator === 'IN' && is_string($value) && str_contains($value, ',')) {
            $value = explode(',', $value);
        }

        $this->value = $value;
    }

    protected function extractJsonValue(mixed $value): mixed
    {
        if (!is_string($value) || ($this->jsonPath === null || $this->jsonPath === '' || $this->jsonPath === '0')) {
            return $value;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $value;
            }

            $keys = explode('.', $this->jsonPath);
            foreach ($keys as $key) {
                if (isset($decoded[$key])) {
                    $decoded = $decoded[$key];
                } else {
                    return null;
                }
            }
            return $decoded;
        } catch (JsonException) {
            return null;
        }
    }

    public function isValid(mixed $value): bool
    {
        if ($value === []) {
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

    public static function between(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, 'BETWEEN', $filterBy);
    }

    public static function relationship(string $relationship, string $attribute, string $operator = '=', ?string $filterBy = null): self
    {
        $filter = new self("{$relationship}.{$attribute}", $operator, $filterBy);
        $filter->relationship = $relationship;
        $filter->attribute = $attribute;
        return $filter;
    }

    public static function json(string $attribute, string $path, string $operator = '=', ?string $filterBy = null): self
    {
        $filter = new self($attribute, $operator, $filterBy);
        $filter->setJsonPath($path);
        $filter->setValueFromRequest();
        return $filter;
    }

    public function setJsonPath(string $path): self
    {
        $this->jsonPath = $path;
        return $this;
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

    public function getValue(): string|array|Carbon|int|null
    {
        if (!$this->isValid($this->value) && $this->isValid($this->default)) {
            $this->value = $this->default;
        }

        if (!$this->isValid($this->value)) {
            return $this->value;
        }

        if ($this->isDate || $this->endOfDay || $this->startOfDay) {
            $this->value = $this->convertToCarbon($this->value);
            if ($this->endOfDay) {
                $this->value->endOfDay();
            }
            if ($this->startOfDay) {
                $this->value->startOfDay();
            }
        }

        return $this->value;
    }

    public function setValue(string|int|array|Carbon|null $value): self
    {
        if ($this->operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
            }
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new InvalidArgumentException('The elements in the BETWEEN value array must be of type string or int.');
                }
            }
        }

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

    public function with(): self
    {
        if ($this->relationship === null || $this->relationship === '' || $this->relationship === '0') {
            throw new InvalidArgumentException('The with() method can only be used with relationship filters.');
        }
        $this->withRelationship = true;
        return $this;
    }

    public function shouldWith(): bool
    {
        return $this->withRelationship;
    }

    public function getAttribute(): string
    {
        if ($this->jsonPath !== null && $this->jsonPath !== '' && $this->jsonPath !== '0') {
            if ($this->isUsingMySQL()) {
                return "{$this->attribute}->>'$.{$this->jsonPath}'";
            }
            if ($this->isUsingSQLite()) {
                return "json_extract({$this->attribute}, '$.{$this->jsonPath}')";
            }
            if ($this->isUsingPostgreSQL()) {
                return "{$this->attribute}->>'{$this->jsonPath}'";
            }
        }
        return $this->attribute;
    }

    public function shouldIgnore(): bool
    {
        return !$this->isValid($this->value) && !$this->isValid($this->default);
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function isDate(): bool
    {
        return $this->isDate;
    }

    protected function isUsingMySQL(): bool
    {
        return $this->getDatabaseDriver() === 'mysql';
    }

    protected function isUsingPostgreSQL(): bool
    {
        return $this->getDatabaseDriver() === 'pgsql';
    }

    protected function isUsingSQLite(): bool
    {
        return $this->getDatabaseDriver() === 'sqlite';
    }

    public function setDatabaseDriver(string $driver): self
    {
        $this->databaseDriver = $driver;
        return $this;
    }

    protected function getDatabaseDriver(): string|bool
    {
        if (self::$cachedDatabaseDriver === null) {
            self::$cachedDatabaseDriver = $this->databaseDriver ?? (function_exists('config') ? config('database.default') : getenv('DATABASE_DRIVER'));
        }
        return self::$cachedDatabaseDriver;
    }

    public function getJsonPath(): ?string
    {
        return $this->jsonPath;
    }

    public function getFilterBy(): string
    {
        return $this->filterBy;
    }

    public function getRelationship(): ?string
    {
        return $this->relationship;
    }
}
