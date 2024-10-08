<?php

namespace DevactionLabs\FilterablePackage;

use Carbon\Carbon;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;
use JsonException;

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
    protected ?string $jsonPath = null;
    protected string|int|null $default = null;
    protected ?string $databaseDriver = null;


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

            if ($this->jsonPath) {
                $decoded = null;
                if (is_string($value)) {
                    try {
                        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $e) {
                        $decoded = null;
                    }
                }

                if (is_array($decoded)) {
                    $keys = explode('.', $this->jsonPath);
                    foreach ($keys as $key) {
                        if (isset($decoded[$key])) {
                            $decoded = $decoded[$key];
                        } else {
                            $decoded = null;
                            break;
                        }
                    }
                    $value = $decoded;
                }
            }

            if ($this->operator === 'LIKE') {
                $value = str_replace('{{value}}', $value, $this->likePattern);
            }

            if ($this->operator === 'IN' && is_string($value)) {
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

    public static function relationship(string $relationship, string $attribute, string $operator = '=', ?string $filterBy = null): self
    {
        return new self("{$relationship}.{$attribute}", $operator, $filterBy);
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
        if ($this->jsonPath) {
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

    /**
     * Define o driver do banco de dados a ser utilizado.
     *
     * @param string $driver
     * @return self
     */
    public function setDatabaseDriver(string $driver): self
    {
        $this->databaseDriver = $driver;
        return $this;
    }

    protected function getDatabaseDriver(): string|bool
    {
        if ($this->databaseDriver !== null) {
            return $this->databaseDriver;
        }

        if (function_exists('config')) {
            return config('database.default');
        }

        return getenv('DATABASE_DRIVER');
    }

    public function getJsonPath(): ?string
    {
        return $this->jsonPath;
    }
}
