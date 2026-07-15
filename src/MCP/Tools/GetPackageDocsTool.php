<?php

namespace DevactionLabs\FilterablePackage\MCP\Tools;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use stdClass;

class GetPackageDocsTool implements Tool
{
    public function name(): string
    {
        return 'get_package_docs';
    }

    public function description(): string
    {
        return 'Returns complete documentation and usage examples for devaction-labs/filterable-package. Call this first to understand how to use the package before generating or reviewing filter code.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new stdClass,
            'required' => [],
        ];
    }

    public function execute(array $args): string
    {
        return <<<'DOCS'
# devaction-labs/filterable-package

A Laravel package for building Eloquent query filters from HTTP request parameters.

## Setup

Add the `Filterable` trait to any Eloquent model:

```php
use DevactionLabs\FilterablePackage\Traits\Filterable;

class User extends Model
{
    use Filterable;
}
```

## Usage in controllers

```php
use DevactionLabs\FilterablePackage\Filter;

$users = User::filterable([
    Filter::ilike('name'),
    Filter::exact('status'),
    Filter::between('created_at')->castDate(),
    Filter::in('role'),
    Filter::relationship('posts', 'status')->with(),
])->customPaginate('paginate', 15);
```

Request: `GET /users?filter[name]=john&filter[status]=active&filter[created_at]=2024-01-01,2024-12-31`

---

## All filter factory methods

| Method | SQL produced | When to use |
|---|---|---|
| `Filter::exact('col')` | `col = ?` | IDs, status, boolean, exact match |
| `Filter::like('col')` | `col LIKE %?%` | Text search (case-sensitive) |
| `Filter::ilike('col')` | `col ILIKE ?` | Text search (case-insensitive). Uses native whereLike (Laravel 11.17+): ilike on PostgreSQL, collation like on MySQL |
| `Filter::notEquals('col')` | `col != ?` | Exclusions |
| `Filter::notLike('col')` | `col NOT LIKE %?%` | Text exclusion |
| `Filter::in('col')` | `col IN (?)` | Multiple values. Request: `val1,val2,val3` |
| `Filter::notIn('col')` | `col NOT IN (?)` | Multiple exclusions |
| `Filter::between('col')` | `col BETWEEN ? AND ?` | Ranges. Request: `start,end` |
| `Filter::gt('col')` | `col > ?` | Greater than |
| `Filter::gte('col')` | `col >= ?` | Greater than or equal |
| `Filter::lt('col')` | `col < ?` | Less than |
| `Filter::lte('col')` | `col <= ?` | Less than or equal |
| `Filter::isNull('col')` | `col IS NULL` | Check null. No request value needed |
| `Filter::isNotNull('col')` | `col IS NOT NULL` | Check not null. No request value needed |
| `Filter::startsWith('col')` | `col LIKE ?%` | Prefix search |
| `Filter::endsWith('col')` | `col LIKE %?` | Suffix search |
| `Filter::fullText(['col1','col2'])` | tsvector / LIKE | Full-text search |
| `Filter::relationship('rel','col')` | `EXISTS (...)` | Filter via Eloquent relation |
| `Filter::json('col','path')` | `col->>'path'` | Filter JSON column field |
| `Filter::anyOf([...], 'key')` | `( a OR b OR EXISTS(...) )` | OR group across columns AND relationships (single search box) |

---

## OR groups (own column OR relationship)

Every filter in `filterable([...])` is AND-ed. Use `Filter::anyOf()` to OR several
filters — including relationship filters — inside one parenthesised group. Pass a
shared request key as the second argument for a single search box:

```php
User::filterable([
    Filter::exact('status'),
    Filter::anyOf([
        Filter::like('name'),
        Filter::like('email'),
        Filter::relationship('company', 'name', 'ILIKE'),
    ], 'search'),   // ?filter[search]=acme feeds all three
]);
// WHERE status = ? AND ( name LIKE ? OR email LIKE ? OR EXISTS(...company.name...) )
```

Omit the second argument to OR filters that each read their own request key. The
group adds no SQL when every child is empty. This is the ONLY correct way to OR a
direct column with a relationship — do NOT hand-write where()->orWhere()->orWhereHas(),
which leaks rows past the other (AND-ed) filters.

---

## Chainable modifiers

```php
->castDate()                    // treat value as date (whereBetween startOfDay..endOfDay)
->startOfDay()                  // date modifier: set time to 00:00:00
->endOfDay()                    // date modifier: set time to 23:59:59
->setDefault($value)            // apply filter even when request has no value
->setFilterBy('param_name')     // override request parameter name
->setLikePattern('%{{value}}%') // custom LIKE pattern
->with()                        // eager-load the relationship (relationship filters only)
->whereAny([['col','=','val']]) // OR conditions inside relationship
->whereAll([...])               // AND conditions inside relationship
->whereNone([...])              // NOT conditions inside relationship
->useTsVector()                 // use pre-computed tsvector column
->setFullTextLanguage('portuguese') // PostgreSQL full-text language
```

---

## Pagination

```php
->customPaginate('paginate', 15)   // full pagination with total count
->customPaginate('simple', 15)     // simplified, no total count
->customPaginate('cursor', 15)     // cursor-based, best for large datasets
```

## Sorting

```php
User::allowedSorts(['name', 'email', 'created_at'], '-created_at')
    ->filterable([...])
    ->customPaginate('paginate');

// Request: ?sort=name (ASC) or ?sort=-name (DESC)
```

Always configure allowedSorts(): with no allow-list, any column may be used as a
sort key (column-enumeration risk). Request per_page is clamped to [1, 100]
(config filterable.max_per_page). Invalid sort/per_page throws InvalidArgumentException.

## Column mapping

```php
User::filterMap(['full_name' => 'users.name'])
    ->filterable([Filter::ilike('full_name')])
    ->paginate();
```

---

## Common patterns

### Date range
```php
Filter::between('created_at')->castDate()
// Request: ?filter[created_at]=2024-01-01,2024-12-31
```

### Search across multiple fields (full-text)
```php
Filter::fullText(['name', 'email', 'bio'])->setFullTextLanguage('portuguese')
// Request: ?filter[search]=john
```

### Relationship filter with eager loading
```php
Filter::relationship('category', 'slug')->with()
// Request: ?filter[slug]=technology
```

### Optional default value
```php
Filter::exact('status')->setDefault('active')
// Applied even when ?filter[status] is absent
```

### JSON column
```php
Filter::json('settings', 'theme', '=', 'theme')
// Request: ?filter[theme]=dark
```
DOCS;
    }
}
