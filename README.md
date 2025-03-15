# Filterable Package

A Laravel package for filterable traits and classes. This package provides powerful, dynamic query filtering capabilities directly from incoming requests, especially useful when developing flexible and dynamic APIs.

## Features

- **Easy Integration:** Apply the `Filterable` trait to your Eloquent models.
- **Flexible Filters:** Exact, like, in, between, greater than (gte, gt), less than (lte, lt), JSON, and relationship filters.
- **Dynamic Sorting:** Customize sorting behavior directly from requests.
- **Relationship Filters:** Use advanced conditional logic like `whereAny`, `whereAll`, and `whereNone` for relational queries.
- **JSON Support:** Directly filter JSON columns with dot-notation.

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
            ->customPaginate(false, ['per_page' => 10, 'sort' => '-created_at']);

        return response()->json($expenses);
    }
}
```

## Available Filters

### Direct Filters
- **Exact Match:** `Filter::exact('status', 'status')`
- **LIKE Match:** `Filter::like('description', 'search')`
- **IN Clause:** `Filter::in('category_id', 'categories')`
- **Greater Than or Equal:** `Filter::gte('amount', 'min_amount')`
- **Less Than or Equal:** `Filter::lte('amount', 'max_amount')`
- **Between:** `Filter::between('created_at', 'date_range')`

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

## Supported Databases for JSON Filters
- MySQL
- PostgreSQL
- SQLite

The package automatically detects the database driver from your configuration.

## License

This package is open-sourced under the MIT license.
