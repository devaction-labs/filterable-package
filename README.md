# Filterable Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/devaction-labs/filterable-package.svg?style=flat-square)](https://packagist.org/packages/devaction-labs/filterable-package)
[![Total Downloads](https://img.shields.io/packagist/dt/devaction-labs/filterable-package.svg?style=flat-square)](https://packagist.org/packages/devaction-labs/filterable-package)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/devaction-labs/filterable-package.svg?style=flat-square)](composer.json)
[![Build Status](https://img.shields.io/github/actions/workflow/status/devaction-labs/filterable-package/tests.yml?branch=main&style=flat-square)](https://github.com/devaction-labs/filterable-package/actions)
[![Quality Score](https://img.shields.io/scrutinizer/g/devaction-labs/filterable-package.svg?style=flat-square)](https://scrutinizer-ci.com/g/devaction-labs/filterable-package)

A Laravel package for filterable traits and classes. This package provides powerful, dynamic query filtering capabilities directly from incoming requests, especially useful when developing flexible and dynamic APIs.

## Features

- **Easy Integration:** Apply the `Filterable` trait to your Eloquent models.
- **Comprehensive Filters:** Support for 15+ filter types including exact, like, ilike, in, between, greater/less than, negation filters (notEquals, notIn, notLike), null checks (isNull, isNotNull), and text pattern filters (startsWith, endsWith).
- **Database Compatibility:** Database-specific optimizations for PostgreSQL, MySQL, and SQLite.
- **Dynamic Sorting:** Customize sorting behavior directly from requests.
- **Relationship Filters:** Use advanced conditional logic like `whereAny`, `whereAll`, and `whereNone` for relational queries.
- **JSON Support:** Directly filter JSON columns with dot-notation.
- **Performance Optimizations:** Built-in caching and efficient query construction.
- **Date Handling:** Smart handling of date fields with Carbon integration.

## Installation

```bash
composer require devaction-labs/filterable-package
```

## Usage

### Step 1: Add the `Filterable` Trait

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use DevactionLabs\FilterablePackage\Traits\Filterable;

class Expense extends Model
{
    use Filterable;

    protected array $filterMap = [
        'search' => 'description',
        'date'   => 'expense_date',
    ];

    protected array $allowedSorts = ['expense_date', 'amount'];
}
```

### Step 2: Applying Filters in Controllers

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use DevactionLabs\FilterablePackage\Filter;
use App\Models\Expense;

class ExpenseController extends Controller
{
    public function index()
    {
        $expenses = Expense::query()
            ->filtrable([
                Filter::like('description', 'search'),
                Filter::exact('expense_date', 'date'),
                Filter::between('expense_date', 'date_range'),
                Filter::json('attributes', 'user.name', 'LIKE', 'user_name'),
                Filter::json('attributes', 'user.age', '>', 'user_age'),
                Filter::relationship('user', 'name')->setValue('John')->with(),
                Filter::relationship('user', 'name')->whereAny([
                    ['name', '=', 'John'],
                    ['email', '=', 'john@example.com'],
                ])->with(),
            ])
            ->customPaginate(false, ['per_page' => 10, 'sort' => '-created_at']);

        return response()->json($expenses);
    }
}
```

## Available Filters

### Direct Filters

#### Basic Comparison Filters
- **Exact Match:** `Filter::exact('status', 'status')`
- **Not Equals:** `Filter::notEquals('status', 'exclude_status')`
- **Greater Than:** `Filter::gt('amount', 'min_amount')`
- **Greater Than or Equal:** `Filter::gte('amount', 'min_amount')`
- **Less Than:** `Filter::lt('amount', 'max_amount')`
- **Less Than or Equal:** `Filter::lte('amount', 'max_amount')`
- **Between:** `Filter::between('created_at', 'date_range')`

#### Text Search Filters
- **LIKE Match:** `Filter::like('description', 'search')`
- **Case-Insensitive LIKE:** `Filter::ilike('description', 'search')` **(Database-specific)**
- **NOT LIKE:** `Filter::notLike('description', 'exclude_text')`
- **Starts With:** `Filter::startsWith('name', 'name_prefix')`
- **Ends With:** `Filter::endsWith('email', 'email_suffix')`

#### List and Array Filters  
- **IN Clause:** `Filter::in('category_id', 'categories')`
- **NOT IN Clause:** `Filter::notIn('status', 'exclude_statuses')`

#### Null Value Filters
- **Is Null:** `Filter::isNull('deleted_at', 'show_deleted')`
- **Is Not Null:** `Filter::isNotNull('email_verified_at', 'verified_only')`

#### Database-Specific Behavior
The `ilike()` filter automatically adapts to your database:
- **PostgreSQL:** Uses native `ILIKE` operator
- **SQLite:** Falls back to `LIKE` (case-sensitive)  
- **MySQL:** Uses `LOWER()` function for case-insensitive comparison

```php
// Example usage for case-insensitive search
$filters = [
    Filter::ilike('name', 'search'), // Works across all databases
];
```

### JSON Filters
- **Exact Match:** `Filter::json('data', 'user.name', '=', 'user_name')`
- **LIKE Match:** `Filter::json('data', 'user.name', 'LIKE', 'user_name')`

### Relationship Filters
- **Simple Relationship:**
  ```php
  Filter::relationship('user', 'name')->setValue('John')->with()
  ```

- **Conditional Logic (`whereAny`, `whereAll`, `whereNone`):**
  ```php
  Filter::relationship('user', 'name')
      ->whereAny([
          ['name', '=', 'John'],
          ['email', '=', 'john@example.com'],
      ])
      ->setValue('John')
      ->with();
  ```

## Customizing Pagination and Sorting

Use the provided methods to paginate and sort easily:

```php
$results = Expense::query()
    ->filtrable([...])
    ->customPaginate(false, ['per_page' => 10, 'sort' => '-created_at']);
```

- `-` (minus) prefix indicates descending sorting (e.g., `-amount`).

### Defining Default Sorting and Allowed Sorts in Model:

```php
protected string $defaultSort = 'amount';
protected array $allowedSorts = ['amount', 'expense_date'];
```

## Custom Filter Mapping

Easily map request parameters to database columns:

```php
protected array $filterMap = [
    'display_name' => 'name',
    'date' => 'expense_date',
];
```

Now, using the parameter `filter[display_name]=John` will filter on the `name` column.

## Advanced Features

### Date Handling

The `Filterable` package provides sophisticated date handling capabilities:

```php
// Create a date filter that will convert string dates to Carbon instances
$dateFilter = Filter::exact('created_at')->castDate();

// Apply to a query
$model->filtrable([$dateFilter]);
```

You can also specify if you want to compare with the start or end of the day:

```php
// Filter by date with time set to 23:59:59
$dateFilter = Filter::exact('created_at')->castDate()->endOfDay();

// Filter by date with time set to 00:00:00
$dateFilter = Filter::exact('created_at')->castDate()->startOfDay();
```

### Custom LIKE Patterns

Customize the pattern used for LIKE filters to match your search requirements:

```php
// Default (contains): '%value%'
$filter = Filter::like('description', 'search');

// Starts with: 'value%'
$filter = Filter::like('description', 'search')->setLikePattern('{{value}}%');

// Ends with: '%value'
$filter = Filter::like('description', 'search')->setLikePattern('%{{value}}');
```

### JSON Field Filtering with Database-Specific Optimizations

The package automatically applies the correct JSON extraction syntax based on your database:

```php
// The query will use the appropriate syntax for your database
$filter = Filter::json('attributes', 'user.age', '>', 'min_age');

// Manually specify database driver if needed
$filter = Filter::json('attributes', 'user.age', '>', 'min_age')->setDatabaseDriver('mysql');
```

### Advanced Relationship Filtering with Conditional Logic

Apply complex conditions to your relationship filters:

```php
// Match if ANY condition is true (OR logic)
$filter = Filter::relationship('user', 'name')
    ->whereAny([
        ['name', '=', 'John'],
        ['email', '=', 'john@example.com'],
    ])
    ->with();

// Match if ALL conditions are true (AND logic)
$filter = Filter::relationship('user', 'name')
    ->whereAll([
        ['name', '=', 'John'],
        ['active', '=', true],
    ])
    ->with();

// Match if NONE of the conditions are true (NOT logic)
$filter = Filter::relationship('user', 'name')
    ->whereNone([
        ['banned', '=', true],
        ['deleted', '=', true],
    ])
    ->with();
```

### Performance Optimizations

The `Filterable` trait includes several performance optimizations:

- Efficient caching of attribute and relationship validations
- Optimized handling of relationship filters
- Smart deduplication of eager-loaded relationships
- Specialized handling for simple equality relationship filters

These optimizations are automatically applied when you use the trait, ensuring your filterable queries remain performant even with complex filter combinations.

## Complete Usage Example

Here's a comprehensive example showing how to use multiple features together:

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Build a complex query using the Filterable trait
        $products = Product::query()
            // Define allowed sort fields and default sort
            ->allowedSorts(['name', 'price', 'created_at'], '-created_at')
            // Define filter field mappings
            ->filterMap([
                'search' => 'name',
                'price_range' => 'price',
                'date' => 'created_at',
                'status_code' => 'status',
            ])
            // Apply filters
            ->filtrable([
                // Basic filters
                Filter::like('name', 'search')
                    ->setLikePattern('{{value}}%'), // Custom LIKE pattern (starts with)
                
                // Numeric range filter
                Filter::between('price', 'price_range'),
                
                // Date filter with Carbon conversion
                Filter::exact('created_at', 'date')
                    ->castDate()
                    ->endOfDay(), // Automatically set time to end of day
                
                // JSON field filtering
                Filter::json('attributes', 'specs.color', 'LIKE', 'color')
                    ->setDatabaseDriver('mysql'),
                
                Filter::json('attributes', 'specs.weight', '>', 'min_weight')
                    ->setDatabaseDriver('mysql'),
                
                // Relationship filter with eager loading
                Filter::relationship('category', 'slug', '=', 'category')
                    ->with(), // Eager load this relationship
                
                // Complex relationship filter with conditional logic
                Filter::relationship('tags', 'name')
                    ->whereAny([
                        ['name', '=', 'featured'],
                        ['name', '=', 'sale'],
                    ])
                    ->with()
                    ->setValue('has_special_tag'), // Custom value for this filter
                
                // Multiple criteria for user permissions
                Filter::relationship('user', 'id')
                    ->whereAll([
                        ['active', '=', true],
                        ['role', '=', 'admin'],
                    ])
                    ->setValue(auth()->id()),
            ])
            // Apply pagination with custom parameters
            ->customPaginate(false, [
                'per_page' => $request->input('per_page', 15),
                'sort' => $request->input('sort', '-created_at'),
            ]);

        return response()->json($products);
    }
}
```

## Supported Databases for JSON Filters
- MySQL
- PostgreSQL
- SQLite

The package automatically detects the database driver from your configuration.

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email alex@devaction.com.br instead of using the issue tracker.

## Credits

- [DevAction Labs](https://github.com/devaction-labs)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
