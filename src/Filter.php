<?php

namespace DevactionLabs\FilterablePackage;

use AllowDynamicProperties;
use Carbon\Carbon;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use JsonException;

/**
 * Class Filter
 *
 * Provides flexible filtering functionality for Laravel models
 * with support for JSON fields, relationships, and various comparison operators.
 */
#[AllowDynamicProperties]
class Filter
{
    /**
     * SQL Comparison operators available for filtering
     */
    public const OPERATOR_EQUALS = '=';
    public const OPERATOR_LIKE = 'LIKE';
    public const OPERATOR_IN = 'IN';
    public const OPERATOR_GT = '>';
    public const OPERATOR_GTE = '>=';
    public const OPERATOR_LT = '<';
    public const OPERATOR_LTE = '<=';
    public const OPERATOR_BETWEEN = 'BETWEEN';

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
    protected ?string $conditionalLogic = null;
    protected array $conditionalConditions = [];

    /**
     * Create a new filter instance
     *
     * @param string $attribute The database column to filter
     * @param string $operator SQL comparison operator
     * @param string|null $filterBy The request parameter to use for filtering (defaults to $attribute)
     */
    public function __construct(string $attribute, protected string $operator, ?string $filterBy = null)
    {
        $this->filterBy = $filterBy ?? $attribute;
        $this->attribute = $attribute;
        $this->setValueFromRequest();
    }

    /**
     * Set the filter value from the current request
     */
    public function setValueFromRequest(): void
    {
        $filters = Request::query('filter', []);
        if (!isset($filters[$this->filterBy]) || !$this->isValid($filters[$this->filterBy])) {
            return;
        }

        $value = $filters[$this->filterBy];
        $this->value = $this->prepareValue($value);
    }

    /**
     * Prepare the value based on the operator
     *
     * @param mixed $value The raw value from the request
     * @return mixed The processed value
     */
    protected function prepareValue(mixed $value): mixed
    {
        // Split comma-separated strings into arrays for BETWEEN and IN operators
        if (is_string($value)) {
            if ($this->operator === self::OPERATOR_BETWEEN && str_contains($value, ',')) {
                return explode(',', $value);
            }

            if ($this->operator === self::OPERATOR_LIKE) {
                return str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === self::OPERATOR_IN && str_contains($value, ',')) {
                return explode(',', $value);
            }
        }

        return $value;
    }

    /**
     * Extract a value from a JSON string using the specified path
     *
     * @param mixed $value The JSON string to extract from
     * @return mixed The extracted value or the original value if extraction fails
     */
    protected function extractJsonValue(mixed $value): mixed
    {
        if (!is_string($value) || $this->isEmptyOrZero($this->jsonPath)) {
            return $value;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return $value;
            }

            $keys = explode('.', $this->jsonPath);
            $current = $decoded;

            foreach ($keys as $key) {
                if (!isset($current[$key])) {
                    return $value;
                }
                $current = $current[$key];
            }

            return $this->applyOperatorToValue($current);
        } catch (JsonException) {
            return $value;
        }
    }

    /**
     * Apply operator-specific formatting to a value
     *
     * @param mixed $value The raw value to process
     * @return mixed The processed value
     */
    protected function applyOperatorToValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return match($this->operator) {
                self::OPERATOR_LIKE => '%' . $value . '%',
                self::OPERATOR_IN => str_contains($value, ',') ? explode(',', $value) : $value,
                default => $value
            };
        }

        return $value;
    }

    /**
     * Check if a value should be considered valid for filtering
     *
     * @param mixed $value The value to check
     * @return bool Whether the value is valid
     */
    public function isValid(mixed $value): bool
    {
        if ($value === []) {
            return false;
        }
        return $value !== '' && $value !== null;
    }

    /**
     * Check if a string is empty, null, or "0"
     *
     * @param string|null $value The string to check
     * @return bool Whether the string is empty
     */
    protected function isEmptyOrZero(?string $value): bool
    {
        return $value === null || $value === '' || $value === '0';
    }

    /**
     * Create a new exact match (=) filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function exact(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_EQUALS, $filterBy);
    }

    /**
     * Create a new LIKE filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function like(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_LIKE, $filterBy);
    }

    /**
     * Create a new filter with a custom operator
     *
     * @param string $attribute The database column to filter
     * @param string $operator SQL comparison operator
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function generic(string $attribute, string $operator, ?string $filterBy = null): self
    {
        return new self($attribute, $operator, $filterBy);
    }

    /**
     * Create a new IN filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function in(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_IN, $filterBy);
    }

    /**
     * Create a new >= filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function gte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_GTE, $filterBy);
    }

    /**
     * Create a new > filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function gt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_GT, $filterBy);
    }

    /**
     * Create a new <= filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function lte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_LTE, $filterBy);
    }

    /**
     * Create a new < filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function lt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_LT, $filterBy);
    }

    /**
     * Create a new BETWEEN filter
     *
     * @param string $attribute The database column to filter
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function between(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_BETWEEN, $filterBy);
    }

    /**
     * Create a new relationship filter
     *
     * @param string $relationship The relationship name
     * @param string $attribute The attribute to filter on the related model
     * @param string $operator SQL comparison operator
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function relationship(string $relationship, string $attribute, string $operator = self::OPERATOR_EQUALS, ?string $filterBy = null): self
    {
        $filter = new self("{$relationship}.{$attribute}", $operator, $filterBy);
        $filter->relationship = $relationship;
        $filter->attribute = $attribute;
        return $filter;
    }

    /**
     * Create a new JSON field filter
     *
     * @param string $attribute The JSON column name
     * @param string $path The path to the nested property
     * @param string $operator SQL comparison operator
     * @param string|null $filterBy The request parameter to use for filtering
     * @return self
     */
    public static function json(string $attribute, string $path, string $operator = self::OPERATOR_EQUALS, ?string $filterBy = null): self
    {
        $filter = new self($attribute, $operator, $filterBy);
        $filter->setJsonPath($path);
        $filter->setValueFromRequest();
        return $filter;
    }

    /**
     * Set the JSON path for this filter
     *
     * @param string $path The path to the nested JSON property
     * @return self
     */
    public function setJsonPath(string $path): self
    {
        $this->jsonPath = $path;
        return $this;
    }

    /**
     * Mark this filter as a date type
     *
     * @return self
     */
    public function castDate(): self
    {
        $this->isDate = true;
        return $this;
    }

    /**
     * Set the pattern for LIKE filters
     *
     * @param string $pattern The pattern with {{value}} placeholder
     * @return self
     */
    public function setLikePattern(string $pattern): self
    {
        $this->likePattern = $pattern;
        return $this;
    }

    /**
     * Set a default value for this filter
     *
     * @param string|int|null $default The default value
     * @return self
     */
    public function setDefault(string|int|null $default): self
    {
        $this->default = $default;
        return $this;
    }

    /**
     * Set the request parameter name
     *
     * @param string $filterBy The request parameter name
     * @return self
     */
    public function setFilterBy(string $filterBy): self
    {
        $this->filterBy = $filterBy;
        return $this;
    }

    /**
     * Get the processed value for this filter
     *
     * @return string|array|Carbon|int|null
     */
    public function getValue(): string|array|Carbon|int|null
    {
        // Use default value if current value is invalid
        if (!$this->isValid($this->value) && $this->isValid($this->default)) {
            $this->value = $this->default;
        }

        if (!$this->isValid($this->value)) {
            return $this->value;
        }

        // Extract JSON value if it's a JSON path and value is a string
        if ($this->jsonPath !== null && is_string($this->value)) {
            return $this->extractJsonValue($this->value);
        }

        // Handle date conversion if needed
        if ($this->isDate || $this->endOfDay || $this->startOfDay) {
            $this->value = $this->convertToCarbon($this->value);
            $this->value = $this->applyDateModifiers($this->value);
        }

        return $this->value;
    }

    /**
     * Apply date modifiers (startOfDay/endOfDay) to a Carbon instance
     *
     * @param Carbon $date The Carbon instance to modify
     * @return Carbon The modified Carbon instance
     */
    protected function applyDateModifiers(Carbon $date): Carbon
    {
        if ($this->endOfDay) {
            $date->endOfDay();
        }

        if ($this->startOfDay) {
            $date->startOfDay();
        }

        return $date;
    }

    /**
     * Set the value for this filter with validation
     *
     * @param string|int|array|Carbon|null $value The value to set
     * @return self
     * @throws InvalidArgumentException If the value is invalid
     */
    public function setValue(string|int|array|Carbon|null $value): self
    {
        if ($this->operator === self::OPERATOR_BETWEEN) {
            $this->validateBetweenValue($value);
        }

        if (is_array($value)) {
            $this->validateArrayValue($value);
        }

        $this->value = $value;
        return $this;
    }

    /**
     * Validate that a value is suitable for a BETWEEN operator
     *
     * @param mixed $value The value to validate
     * @throws InvalidArgumentException If the value is invalid for BETWEEN
     */
    protected function validateBetweenValue(mixed $value): void
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
        }

        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                throw new InvalidArgumentException('The elements in the BETWEEN value array must be of type string or int.');
            }
        }
    }

    /**
     * Validate that all values in an array are strings
     *
     * @param array $value The array to validate
     * @throws InvalidArgumentException If any value is not a string
     */
    protected function validateArrayValue(array $value): void
    {
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                throw new InvalidArgumentException('Array values must be of type string or int');
            }
        }
    }

    /**
     * Convert a value to a Carbon instance
     *
     * @param string|int|array|Carbon|null $value The value to convert
     * @return Carbon The converted Carbon instance
     * @throws InvalidArgumentException If the value cannot be converted to Carbon
     */
    private function convertToCarbon(string|int|array|Carbon|null $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException('Array values cannot be converted to Carbon instances');
        }

        try {
            return new Carbon($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid date value: {$value}", 0, $e);
        }
    }

    /**
     * Set the filter to use end of day for date comparison
     *
     * @return self
     */
    public function endOfDay(): self
    {
        $this->endOfDay = true;
        return $this;
    }

    /**
     * Set the filter to use start of day for date comparison
     *
     * @return self
     */
    public function startOfDay(): self
    {
        $this->startOfDay = true;
        return $this;
    }

    /**
     * Include the relationship in the query
     *
     * @return self
     * @throws InvalidArgumentException If this is not a relationship filter
     */
    public function with(): self
    {
        if ($this->isEmptyOrZero($this->relationship)) {
            throw new InvalidArgumentException('The with() method can only be used with relationship filters.');
        }
        $this->withRelationship = true;
        return $this;
    }

    /**
     * Check if the relationship should be included
     *
     * @return bool
     */
    public function shouldWith(): bool
    {
        return $this->withRelationship;
    }

    /**
     * Add "any" conditional logic to a relationship filter
     *
     * @param array $conditions The conditions to check
     * @return self
     * @throws InvalidArgumentException If this is not a relationship filter
     */
    public function whereAny(array $conditions): self
    {
        if ($this->isEmptyOrZero($this->relationship)) {
            throw new InvalidArgumentException('The whereAny() method can only be used with relationship filters.');
        }
        $this->conditionalLogic = 'any';
        $this->conditionalConditions = $conditions;
        return $this;
    }

    /**
     * Add "all" conditional logic to a relationship filter
     *
     * @param array $conditions The conditions to check
     * @return self
     * @throws InvalidArgumentException If this is not a relationship filter
     */
    public function whereAll(array $conditions): self
    {
        if ($this->isEmptyOrZero($this->relationship)) {
            throw new InvalidArgumentException('The whereAll() method can only be used with relationship filters.');
        }
        $this->conditionalLogic = 'all';
        $this->conditionalConditions = $conditions;
        return $this;
    }

    /**
     * Add "none" conditional logic to a relationship filter
     *
     * @param array $conditions The conditions to check
     * @return self
     * @throws InvalidArgumentException If this is not a relationship filter
     */
    public function whereNone(array $conditions): self
    {
        if ($this->isEmptyOrZero($this->relationship)) {
            throw new InvalidArgumentException('The whereNone() method can only be used with relationship filters.');
        }
        $this->conditionalLogic = 'none';
        $this->conditionalConditions = $conditions;
        return $this;
    }

    /**
     * Get the conditional logic type
     *
     * @return string|null
     */
    public function getConditionalLogic(): ?string
    {
        return $this->conditionalLogic;
    }

    /**
     * Get the conditional conditions
     *
     * @return array
     */
    public function getConditionalConditions(): array
    {
        return $this->conditionalConditions;
    }

    /**
     * Get the formatted attribute for SQL queries
     *
     * @return string
     */
    public function getAttribute(): string
    {
        if (!$this->isEmptyOrZero($this->jsonPath)) {
            return $this->getJsonAttributeExpression();
        }

        return $this->attribute;
    }

    /**
     * Get the database-specific JSON attribute expression
     *
     * @return string
     */
    protected function getJsonAttributeExpression(): string
    {
        return match($this->getDatabaseDriver()) {
            'mysql' => "{$this->attribute}->>'$.{$this->jsonPath}'",
            'sqlite' => "json_extract({$this->attribute}, '$.{$this->jsonPath}')",
            'pgsql' => "{$this->attribute}->>'{$this->jsonPath}'",
            default => $this->attribute
        };
    }

    /**
     * Check if this filter should be ignored (has no value)
     *
     * @return bool
     */
    public function shouldIgnore(): bool
    {
        return !$this->isValid($this->value) && !$this->isValid($this->default);
    }

    /**
     * Get the SQL operator
     *
     * @return string
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * Check if this filter is for a date field
     *
     * @return bool
     */
    public function isDate(): bool
    {
        return $this->isDate;
    }

    /**
     * Check if using MySQL database
     *
     * @return bool
     */
    protected function isUsingMySQL(): bool
    {
        return $this->getDatabaseDriver() === 'mysql';
    }

    /**
     * Check if using PostgreSQL database
     *
     * @return bool
     */
    protected function isUsingPostgreSQL(): bool
    {
        return $this->getDatabaseDriver() === 'pgsql';
    }

    /**
     * Check if using SQLite database
     *
     * @return bool
     */
    protected function isUsingSQLite(): bool
    {
        return $this->getDatabaseDriver() === 'sqlite';
    }

    /**
     * Set the database driver
     *
     * @param string $driver The database driver name
     * @return self
     */
    public function setDatabaseDriver(string $driver): self
    {
        $this->databaseDriver = $driver;
        return $this;
    }

    /**
     * Get the current database driver
     *
     * @return string
     */
    protected function getDatabaseDriver(): string
    {
        // Clear cached driver when explicitly set to ensure tests can swap drivers
        if ($this->databaseDriver !== null) {
            self::$cachedDatabaseDriver = $this->databaseDriver;
        } else if (self::$cachedDatabaseDriver === null) {
            $configResult = function_exists('config') ? config('database.default') : null;
            $envResult = getenv('DATABASE_DRIVER');
            self::$cachedDatabaseDriver = $configResult ?? $envResult ?? 'mysql';
        }

        return self::$cachedDatabaseDriver;
    }

    /**
     * Get the JSON path
     *
     * @return string|null
     */
    public function getJsonPath(): ?string
    {
        return $this->jsonPath;
    }

    /**
     * Get the request parameter name
     *
     * @return string
     */
    public function getFilterBy(): string
    {
        return $this->filterBy;
    }

    /**
     * Get the relationship name
     *
     * @return string|null
     */
    public function getRelationship(): ?string
    {
        return $this->relationship;
    }
}
