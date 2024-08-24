# Filterable Package for Laravel

**Filterable Package** is a Laravel package designed to simplify filtering Eloquent models in your application. It provides an easy-to-use interface to apply various types of filters to your Eloquent queries, such as exact matches, partial matches (LIKE), date ranges, and more, directly from incoming requests. This package is especially useful for building APIs where dynamic filtering is often required.

## Features
- **Easy Integration:** Apply the `Filterable` trait to your Eloquent models to start using it.
- **Multiple Filters:** Supports a variety of filters such as exact matches, LIKE searches, greater than, less than, IN clauses, and date ranges.
- **Custom Filter Mapping:** Easily map request parameters to different column names in the database.
- **Flexible Sorting:** Allows for dynamic sorting of results based on request parameters.

## Installation

To install the package, use Composer:

```bash
composer require devaction-labs/filterable-package


## Usage

### Step 1: Apply the `Filterable` Trait to Your Model

Apply the `Filterable` trait to any Eloquent model you want to filter.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use DevactionLabs\FilterablePackage\Traits\Filterable;

class Expense extends Model
{
    use Filterable;

    // Define any custom filter map or allowed sorts if necessary
    protected array $filterMap = [
        'name' => 'user_name', // Example: map 'name' request parameter to 'user_name' column
    ];

    protected array $allowedSorts = ['expense_date', 'amount']; // Example: allow sorting by these columns
}
```

### Step 2: Apply Filters in Your Controller

Apply filters in your controller methods using the `filtrable` scope provided by the `Filterable` trait.

```php
namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseCollection;
use App\Models\Expense;
use DevactionLabs\FilterablePackage\Filter;

class ExpenseController extends Controller
{
    public function index(): ExpenseCollection
    {
        $expenses = Expense::query()
            ->with(['category', 'period'])
            ->filtrable([
                Filter::like('description', 'description'),
                Filter::exact('expense_date', 'expense_date'),
            ])
            ->orderBy('expense_date', 'desc')
            ->get();

        return new ExpenseCollection($expenses);
    }
}
```

## Available Filters

### `Filter::exact($attribute, $filterBy = null)`
- **Description:** Filters records where the column value matches exactly with the provided value.
- **Example:** `Filter::exact('status', 'status')` filters records where the `status` column matches the value of the `status` parameter in the request.

### `Filter::like($attribute, $filterBy = null)`
- **Description:** Filters records where the column value is similar to the provided value (uses SQL `LIKE`).
- **Example:** `Filter::like('name', 'search')` filters records where the `name` column contains the value of the `search` parameter in the request.

### `Filter::in($attribute, $filterBy = null)`
- **Description:** Filters records where the column value is within a specified list of values.
- **Example:** `Filter::in('category_id', 'categories')` filters records where the `category_id` column matches any value provided in the `categories` parameter in the request.

### `Filter::gte($attribute, $filterBy = null)`
- **Description:** Filters records where the column value is greater than or equal to the provided value.
- **Example:** `Filter::gte('amount', 'min_amount')` filters records where the `amount` column is greater than or equal to the value of the `min_amount` parameter in the request.

### `Filter::lte($attribute, $filterBy = null)`
- **Description:** Filters records where the column value is less than or equal to the provided value.
- **Example:** `Filter::lte('amount', 'max_amount')` filters records where the `amount` column is less than or equal to the value of the `max_amount` parameter in the request.

## Custom Filter Mapping
You can map request parameters to different column names in your database. For example:

```php
protected array $filterMap = [
    'search' => 'description',
    'date' => 'expense_date',
];
```

Now, if the request contains `filter[search]=Pizza`, the query will filter the `description` column for the value `Pizza`.

## Benefits and Differentiators

- **Enhanced Code Readability:** Filters are applied in a clean, readable manner without cluttering your controllers or models with complex query logic.
- **Dynamic Filtering:** Easily handle multiple filters from a single request without manually parsing input.
- **Reusability:** Filters can be reused across different queries and models, making your code DRY (Don't Repeat Yourself).
- **Flexible Sorting:** Allows sorting results dynamically based on request parameters, ensuring that your API remains flexible to client needs.

## Example Usage in API Controller

```php
public function index(): ExpenseCollection
{
    $expenses = Expense::query()
        ->with(['category', 'period'])
        ->filtrable([
            Filter::like('description', 'description'),
            Filter::exact('expense_date', 'expense_date'),
        ])
        ->orderBy('expense_date', 'desc')
        ->get();

    return new ExpenseCollection($expenses);
}
```

## Conclusion

The **Filterable Package** simplifies the process of filtering Eloquent models in Laravel. By leveraging the power of this package, you can create highly flexible and dynamic filtering solutions in your application, improving code quality and maintainability.
```
