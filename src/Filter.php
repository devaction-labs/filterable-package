<?php

namespace DevactionLabs\FilterablePackage;

use Carbon\Carbon;
use DevactionLabs\FilterablePackage\Enums\FilterOperator;
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
     *
     * @deprecated Use FilterOperator enum instead
     */
    public const OPERATOR_EQUALS = '=';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_LIKE = 'LIKE';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_IN = 'IN';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_GT = '>';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_GTE = '>=';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_LT = '<';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_LTE = '<=';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_BETWEEN = 'BETWEEN';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_ILIKE = 'ILIKE';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_NOT_EQUALS = '!=';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_NOT_IN = 'NOT IN';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_NOT_LIKE = 'NOT LIKE';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_IS_NULL = 'IS NULL';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_IS_NOT_NULL = 'IS NOT NULL';

    /** @deprecated Use FilterOperator enum instead */
    public const OPERATOR_STARTS_WITH = 'STARTS_WITH';

    /** @deprecated Use FilterOperator enum instead */
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
     * @var array<int, string>|null
     */
    protected ?array $fullTextColumns = null;

    protected ?string $fullTextLanguage = null;

    protected bool $fullTextPrefixMatch = true;

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

        if (is_array($processedValue)) {
            /** @var array<int|string> $processedValue */
            $this->value = $processedValue;

            return;
        }

        if (is_string($processedValue) || is_int($processedValue) || $processedValue instanceof Carbon || $processedValue === null) {
            $this->value = $processedValue;
        }
    }

    /**
     * Sanitize input value to prevent malicious data
     *
     * @param  mixed  $value  The value to sanitize
     * @return string|array<int|string>|int|null The sanitized value
     */
    protected function sanitizeInput(mixed $value): string|array|int|null
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
            /** @var array<int|string> */
            return array_map($this->sanitizeInput(...), $value);
        }

        return is_int($value) ? $value : null;
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
            if ($this->operator === FilterOperator::BETWEEN->value && str_contains($value, ',')) {
                return explode(',', $value);
            }

            if ($this->operator === FilterOperator::LIKE->value) {
                return str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === FilterOperator::NOT_LIKE->value) {
                return str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === FilterOperator::STARTS_WITH->value) {
                return $value.'%';
            }

            if ($this->operator === FilterOperator::ENDS_WITH->value) {
                return '%'.$value;
            }

            if (($this->operator === FilterOperator::IN->value || $this->operator === FilterOperator::NOT_IN->value) && str_contains($value, ',')) {
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
                if (! is_array($current) || ! isset($current[$key])) {
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
                FilterOperator::LIKE->value => '%'.$value.'%',
                FilterOperator::IN->value => str_contains($value, ',') ? explode(',', $value) : $value,
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
            return false;
        }

        return $value !== '' && $value !== null;
    }

    /**
     * Check if a string is empty, null, or "0"
     *
     * @param  string|null  $value  The string to check
     * @return bool Whether the string is empty
     */
    protected function isEmptyOrZero(?string $value): bool
    {
        return in_array($value, [null, '', '0'], true);
    }

    /**
     * Create a new exact match (=) filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function exact(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::EQUALS->value, $filterBy);
    }

    /**
     * Create a new LIKE filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function like(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::LIKE->value, $filterBy);
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
        return new self($attribute, FilterOperator::IN->value, $filterBy);
    }

    /**
     * Create a new >= filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function gte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::GTE->value, $filterBy);
    }

    /**
     * Create a new > filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function gt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::GT->value, $filterBy);
    }

    /**
     * Create a new <= filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function lte(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::LTE->value, $filterBy);
    }

    /**
     * Create a new < filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function lt(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::LT->value, $filterBy);
    }

    /**
     * Create a new BETWEEN filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function between(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::BETWEEN->value, $filterBy);
    }

    /**
     * Create a new ILIKE filter (case-insensitive LIKE for PostgreSQL)
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function ilike(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::ILIKE->value, $filterBy);
    }

    /**
     * Create a new NOT EQUALS (!=) filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function notEquals(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::NOT_EQUALS->value, $filterBy);
    }

    /**
     * Create a new NOT IN filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function notIn(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::NOT_IN->value, $filterBy);
    }

    /**
     * Create a new NOT LIKE filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function notLike(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::NOT_LIKE->value, $filterBy);
    }

    /**
     * Create a new IS NULL filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function isNull(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::IS_NULL->value, $filterBy);
    }

    /**
     * Create a new IS NOT NULL filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function isNotNull(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::IS_NOT_NULL->value, $filterBy);
    }

    /**
     * Create a new STARTS WITH filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function startsWith(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::STARTS_WITH->value, $filterBy);
    }

    /**
     * Create a new ENDS WITH filter
     *
     * @param  string  $attribute  The database column to filter
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function endsWith(string $attribute, ?string $filterBy = null): self
    {
        return new self($attribute, FilterOperator::ENDS_WITH->value, $filterBy);
    }

    /**
     * Create a new full-text search filter
     *
     * @param  array<int, string>|string  $columns  Columns to search (string for single column or array for multiple)
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function fullText(array|string $columns, ?string $filterBy = null): self
    {
        $columnsArray = is_array($columns) ? $columns : [$columns];
        $firstColumn = $columnsArray[0];

        $filter = new self($firstColumn, FilterOperator::FULL_TEXT->value, $filterBy ?? 'search');
        $filter->fullTextColumns = $columnsArray;

        return $filter;
    }

    /**
     * Create a new relationship filter
     *
     * @param  string  $relationship  The relationship name
     * @param  string  $attribute  The attribute to filter on the related model
     * @param  string  $operator  SQL comparison operator
     * @param  string|null  $filterBy  The request parameter to use for filtering
     */
    public static function relationship(string $relationship, string $attribute, string $operator = FilterOperator::EQUALS->value, ?string $filterBy = null): self
    {
        $filter = new self(sprintf('%s.%s', $relationship, $attribute), $operator, $filterBy);
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
    public static function json(string $attribute, string $path, string $operator = FilterOperator::EQUALS->value, ?string $filterBy = null): self
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
     * Set the full-text search language
     *
     * @param  string|null  $language  The language for full-text search (e.g., 'portuguese', 'english', 'simple')
     */
    public function setFullTextLanguage(?string $language): self
    {
        $this->fullTextLanguage = $language;

        return $this;
    }

    /**
     * Set whether to use prefix matching in full-text search
     *
     * @param  bool  $prefixMatch  If true, adds :* for prefix matching. If false, exact match.
     */
    public function setFullTextPrefixMatch(bool $prefixMatch): self
    {
        $this->fullTextPrefixMatch = $prefixMatch;

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

            if (is_string($jsonValue) || is_int($jsonValue) || $jsonValue instanceof Carbon || $jsonValue === null) {
                return $jsonValue;
            }

            if (is_array($jsonValue)) {
                /** @phpstan-var array<int|string> $jsonValue */
                return $jsonValue;
            }

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
        if ($this->operator === FilterOperator::BETWEEN->value) {
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
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Invalid date value: '.$value, 0, $exception);
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
     * Get the full-text search columns
     *
     * @return array<int, string>|null
     */
    public function getFullTextColumns(): ?array
    {
        return $this->fullTextColumns;
    }

    /**
     * Get the full-text search language
     */
    public function getFullTextLanguage(): ?string
    {
        return $this->fullTextLanguage;
    }

    /**
     * Check if full-text search should use prefix matching
     */
    public function getFullTextPrefixMatch(): bool
    {
        return $this->fullTextPrefixMatch;
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
            'mysql' => sprintf("%s->>'\$.%s'", $this->attribute, $this->jsonPath),
            'sqlite' => sprintf("json_extract(%s, '\$.%s')", $this->attribute, $this->jsonPath),
            'pgsql' => sprintf("%s->>'%s'", $this->attribute, $this->jsonPath),
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
     * Get the filter operator as an enum
     */
    public function getOperatorEnum(): FilterOperator
    {
        return FilterOperator::from($this->operator);
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

        if (self::$cachedDatabaseDriver === null) {
            $configResult = function_exists('config') ? config('database.default') : null;
            $envResult = getenv('DATABASE_DRIVER') ?: null;
            $driver = $configResult ?? $envResult ?? 'mysql';
            self::$cachedDatabaseDriver = is_string($driver) ? $driver : 'mysql';
        }

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
