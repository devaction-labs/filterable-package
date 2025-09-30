<?php

namespace DevactionLabs\FilterablePackage;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use JsonException;

/**
 * Class Filter
 *
 * Provides flexible filtering functionality for Laravel models
 * with support for JSON fields, relationships, and various comparison operators.
 */
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

    public const OPERATOR_ILIKE = 'ILIKE';

    public const OPERATOR_NOT_EQUALS = '!=';

    public const OPERATOR_NOT_IN = 'NOT IN';

    public const OPERATOR_NOT_LIKE = 'NOT LIKE';

    public const OPERATOR_IS_NULL = 'IS NULL';

    public const OPERATOR_IS_NOT_NULL = 'IS NOT NULL';

    public const OPERATOR_STARTS_WITH = 'STARTS_WITH';

    public const OPERATOR_ENDS_WITH = 'ENDS_WITH';

    protected string $attribute;

    protected string $filterBy;

    /**
     * @var string|array<int|string>|Carbon|int|null
     */
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

    /**
     * @var array<int, array<int, mixed>>
     */
    protected array $conditionalConditions = [];

    /**
     * Create a new filter instance
     *
     * @param  string  $attribute  The database column to filter
     * @param  string  $operator  SQL comparison operator
     * @param  string|null  $filterBy  The request parameter to use for filtering (defaults to $attribute)
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
        if (! isset($filters[$this->filterBy]) || ! $this->isValid($filters[$this->filterBy])) {
            return;
        }

        $value = $this->sanitizeInput($filters[$this->filterBy]);
        $processedValue = $this->prepareValue($value);

        if (is_string($processedValue) || is_int($processedValue) || is_array($processedValue) ||
            $processedValue instanceof Carbon || $processedValue === null) {
            $this->value = $processedValue;
        }
    }

    /**
     * Sanitize input value to prevent malicious data
     *
     * @param  mixed  $value  The value to sanitize
     * @return mixed The sanitized value
     */
    protected function sanitizeInput(mixed $value): mixed
    {
        if (is_string($value)) {
            $sanitized = str_replace("\0", '', $value);
            $sanitized = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $sanitized);

            if (strlen($sanitized) > 1000) {
                $sanitized = substr($sanitized, 0, 1000);
            }

            return trim($sanitized);
        }

        if (is_array($value)) {
            return array_map(fn ($item): mixed => $this->sanitizeInput($item), $value);
        }

        return $value;
    }

    /**
     * Prepare the value based on the operator
     *
     * @param  mixed  $value  The raw value from the request
     * @return mixed The processed value
     */
    protected function prepareValue(mixed $value): mixed
    {
        if (is_string($value)) {
            if ($this->operator === self::OPERATOR_BETWEEN && str_contains($value, ',')) {
                return explode(',', $value);
            }

            if ($this->operator === self::OPERATOR_LIKE) {
                return str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === self::OPERATOR_NOT_LIKE) {
                return str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === self::OPERATOR_STARTS_WITH) {
                return $value.'%';
            }

            if ($this->operator === self::OPERATOR_ENDS_WITH) {
                return '%'.$value;
            }

            if (($this->operator === self::OPERATOR_IN || $this->operator === self::OPERATOR_NOT_IN) && str_contains($value, ',')) {
                return explode(',', $value);
            }
        }

        return $value;
    }

    /**
     * Extract a value from a JSON string using the specified path
     *
     * @param  mixed  $value  The JSON string to extract from
     * @return mixed The extracted value or the original value if extraction fails
     */
    protected function extractJsonValue(mixed $value): mixed
    {
        if (! is_string($value) || $this->isEmptyOrZero($this->jsonPath)) {
            return $value;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return $value;
            }

            $keys = explode('.', (string) $this->jsonPath);
            $current = $decoded;

            foreach ($keys as $key) {
                if (! isset($current[$key])) {
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
     * @param  mixed  $value  The raw value to process
     * @return mixed The processed value
     */
    protected function applyOperatorToValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return match ($this->operator) {
                self::OPERATOR_LIKE => '%'.$value.'%',
                self::OPERATOR_IN => str_contains($value, ',') ? explode(',', $value) : $value,
                default => $value
            };
        }

        return $value;
    }

    /**
     * Check if a value should be considered valid for filtering
     *
     * @param  mixed  $value  The value to check
     * @return bool Whether the value is valid
     */
    public function isValid(mixed $value): bool
    {
        if ($value === []) {
            $this->logInvalidFilter('Empty array provided', $value);

            return false;
        }

        if ($value === '' || $value === null) {
            $this->logInvalidFilter('Empty or null value provided', $value);

            return false;
        }

        return true;
    }

    /**
     * Log invalid filter attempts for debugging
     *
     * @param  string  $reason  The reason the filter is invalid
     * @param  mixed  $value  The invalid value
     */
    protected function logInvalidFilter(string $reason, mixed $value): void
    {
        // Only log if Laravel's logger is available and app is in debug mode
        if (! function_exists('logger') || ! function_exists('config')) {
            return;
        }

        if (! config('app.debug', false)) {
            return;
        }

        logger()->debug('Invalid filter value detected', [
            'attribute' => $this->attribute,
            'filter_by' => $this->filterBy,
            'operator' => $this->operator,
            'reason' => $reason,
            'value_type' => get_debug_type($value),
        ]);
    }

    /**
     * Check if a string is empty, null, or "0"
     *
     * @param  string|null  $value  The string to check
     * @return bool Whether the string is empty
     */
    protected function isEmptyOrZero(?string $value): bool
    {
        return $value === null || $value === '' || $value === '0';
    }

    /**
     * Create a new exact match (=) filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function exact(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_EQUALS, $filterBy);
    }

    /**
     * Create a new LIKE filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function like(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_LIKE, $filterBy);
    }

    /**
     * Create a new filter with a custom operator
     *
     * @param  string  $attribute  The database column to filter
     * @param  string  $operator  SQL comparison operator
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function generic(string $attribute, string $operator, ?string $filterBy = null): self
    {
        return new self($attribute, $operator, $filterBy);
    }

    /**
     * Create a new IN filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function in(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_IN, $filterBy);
    }

    /**
     * Create a new >= filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function gte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_GTE, $filterBy);
    }

    /**
     * Create a new > filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function gt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_GT, $filterBy);
    }

    /**
     * Create a new <= filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function lte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_LTE, $filterBy);
    }

    /**
     * Create a new < filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function lt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_LT, $filterBy);
    }

    /**
     * Create a new BETWEEN filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function between(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_BETWEEN, $filterBy);
    }

    /**
     * Create a new ILIKE filter (case-insensitive LIKE for PostgreSQL)
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function ilike(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_ILIKE, $filterBy);
    }

    /**
     * Create a new NOT EQUALS (!=) filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function notEquals(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_NOT_EQUALS, $filterBy);
    }

    /**
     * Create a new NOT IN filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function notIn(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_NOT_IN, $filterBy);
    }

    /**
     * Create a new NOT LIKE filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function notLike(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_NOT_LIKE, $filterBy);
    }

    /**
     * Create a new IS NULL filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function isNull(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_IS_NULL, $filterBy);
    }

    /**
     * Create a new IS NOT NULL filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function isNotNull(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_IS_NOT_NULL, $filterBy);
    }

    /**
     * Create a new STARTS WITH filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function startsWith(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_STARTS_WITH, $filterBy);
    }

    /**
     * Create a new ENDS WITH filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function endsWith(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, self::OPERATOR_ENDS_WITH, $filterBy);
    }

    /**
     * Create a new relationship filter
     *
     * @param  string  $relationship  The relationship name
     * @param  string  $attribute  The attribute to filter on the related model
     * @param  string  $operator  SQL comparison operator
     * @param  string|null  $filterBy  The request parameter to use for filtering
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
     * @param  string  $attribute  The JSON column name
     * @param  string  $path  The path to the nested property
     * @param  string  $operator  SQL comparison operator
     * @param  string|null  $filterBy  The request parameter to use for filtering
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
     * @param  string  $path  The path to the nested JSON property
     */
    public function setJsonPath(string $path): self
    {
        $this->jsonPath = $path;

        return $this;
    }

    /**
     * Mark this filter as a date type
     */
    public function castDate(): self
    {
        $this->isDate = true;

        return $this;
    }

    /**
     * Set the pattern for LIKE filters
     *
     * @param  string  $pattern  The pattern with {{value}} placeholder
     */
    public function setLikePattern(string $pattern): self
    {
        $this->likePattern = $pattern;

        return $this;
    }

    /**
     * Set a default value for this filter
     *
     * @param  string|int|null  $default  The default value
     */
    public function setDefault(string|int|null $default): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Set the request parameter name
     *
     * @param  string  $filterBy  The request parameter name
     */
    public function setFilterBy(string $filterBy): self
    {
        $this->filterBy = $filterBy;

        return $this;
    }

    /**
     * Get the processed value for this filter
     *
     * @return string|array<int|string>|Carbon|int|null
     */
    public function getValue(): string|array|Carbon|int|null
    {
        if (! $this->isValid($this->value) && $this->isValid($this->default)) {
            $this->value = $this->default;
        }

        if (! $this->isValid($this->value)) {
            return $this->value;
        }

        if ($this->jsonPath !== null && is_string($this->value)) {
            $jsonValue = $this->extractJsonValue($this->value);

            // Ensure we're returning a compatible type
            if (is_string($jsonValue) || is_int($jsonValue) || is_array($jsonValue) || $jsonValue instanceof Carbon || $jsonValue === null) {
                return $jsonValue;
            }

            // Fallback to original value if type is not compatible
            return $this->value;
        }

        if ($this->isDate || $this->endOfDay || $this->startOfDay) {
            if ($this->value instanceof Carbon) {
                return $this->applyDateModifiers($this->value);
            }

            if (is_string($this->value) || is_int($this->value)) {
                try {
                    $carbonDate = $this->convertToCarbon($this->value);

                    return $this->applyDateModifiers($carbonDate);
                } catch (InvalidArgumentException) {
                    // If conversion fails, return original value
                    return $this->value;
                }
            }
        }

        return $this->value;
    }

    /**
     * Apply date modifiers (startOfDay/endOfDay) to a Carbon instance
     *
     * @param  Carbon  $date  The Carbon instance to modify
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
     * @param  string|int|array<int|string>|Carbon|null  $value  The value to set
     *
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
     * @param  mixed  $value  The value to validate
     *
     * @throws InvalidArgumentException If the value is invalid for BETWEEN
     */
    protected function validateBetweenValue(mixed $value): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException('The value for BETWEEN must be an array with exactly two elements.');
        }

        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item)) {
                throw new InvalidArgumentException('The elements in the BETWEEN value array must be of type string or int.');
            }
        }
    }

    /**
     * Validate that all values in an array are strings or integers
     *
     * @param  array<int|string>  $value  The array to validate
     *
     * @throws InvalidArgumentException If any value is not a string or integer
     */
    protected function validateArrayValue(array $value): void
    {
        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item)) {
                throw new InvalidArgumentException('Array values must be of type string or int');
            }
        }
    }

    /**
     * Convert a value to a Carbon instance
     *
     * @param  string|int|array<int|string>|Carbon|null  $value  The value to convert
     * @return Carbon The converted Carbon instance
     *
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
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid date value: {$value}", 0, $e);
        }
    }

    /**
     * Set the filter to use end of day for date comparison
     */
    public function endOfDay(): self
    {
        $this->endOfDay = true;

        return $this;
    }

    /**
     * Set the filter to use start of day for date comparison
     */
    public function startOfDay(): self
    {
        $this->startOfDay = true;

        return $this;
    }

    /**
     * Include the relationship in the query
     *
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
     */
    public function shouldWith(): bool
    {
        return $this->withRelationship;
    }

    /**
     * Add "any" conditional logic to a relationship filter
     *
     * @param  array<int, array<int, mixed>>  $conditions  The conditions to check
     *
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
     * @param  array<int, array<int, mixed>>  $conditions  The conditions to check
     *
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
     * @param  array<int, array<int, mixed>>  $conditions  The conditions to check
     *
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
     */
    public function getConditionalLogic(): ?string
    {
        return $this->conditionalLogic;
    }

    /**
     * Get the conditional conditions
     *
     * @return array<int, array<int, mixed>>
     */
    public function getConditionalConditions(): array
    {
        return $this->conditionalConditions;
    }

    /**
     * Get the formatted attribute for SQL queries
     */
    public function getAttribute(): string
    {
        if (! $this->isEmptyOrZero($this->jsonPath)) {
            return $this->getJsonAttributeExpression();
        }

        return $this->attribute;
    }

    /**
     * Get the database-specific JSON attribute expression
     */
    protected function getJsonAttributeExpression(): string
    {
        return match ($this->getDatabaseDriver()) {
            'mysql' => "{$this->attribute}->>'$.{$this->jsonPath}'",
            'sqlite' => "json_extract({$this->attribute}, '$.{$this->jsonPath}')",
            'pgsql' => "{$this->attribute}->>'{$this->jsonPath}'",
            default => $this->attribute
        };
    }

    /**
     * Check if this filter should be ignored (has no value)
     */
    public function shouldIgnore(): bool
    {
        return ! $this->isValid($this->value) && ! $this->isValid($this->default);
    }

    /**
     * Get the SQL operator
     */
    public function getOperator(): string
    {
        return $this->operator;
    }

    /**
     * Check if this filter is for a date field
     */
    public function isDate(): bool
    {
        return $this->isDate;
    }

    /**
     * Check if using MySQL database
     */
    public function isUsingMySQL(): bool
    {
        return $this->getDatabaseDriver() === 'mysql';
    }

    /**
     * Check if using PostgreSQL database
     */
    public function isUsingPostgreSQL(): bool
    {
        return $this->getDatabaseDriver() === 'pgsql';
    }

    /**
     * Check if using SQLite database
     */
    public function isUsingSQLite(): bool
    {
        return $this->getDatabaseDriver() === 'sqlite';
    }

    /**
     * Set the database driver
     *
     * @param  string  $driver  The database driver name
     */
    public function setDatabaseDriver(string $driver): self
    {
        $this->databaseDriver = $driver;

        return $this;
    }

    /**
     * Get the current database driver with optimized caching
     */
    protected function getDatabaseDriver(): string
    {
        if ($this->databaseDriver !== null) {
            return $this->databaseDriver;
        }

        if (self::$cachedDatabaseDriver !== null) {
            return self::$cachedDatabaseDriver;
        }

        $configResult = function_exists('config') ? config('database.default') : null;
        $envResult = getenv('DATABASE_DRIVER') ?: null;
        self::$cachedDatabaseDriver = $configResult ?? $envResult ?? 'mysql';

        return self::$cachedDatabaseDriver;
    }

    /**
     * Get the JSON path
     */
    public function getJsonPath(): ?string
    {
        return $this->jsonPath;
    }

    /**
     * Get the request parameter name
     */
    public function getFilterBy(): string
    {
        return $this->filterBy;
    }

    /**
     * Get the relationship name
     */
    public function getRelationship(): ?string
    {
        return $this->relationship;
    }
}
