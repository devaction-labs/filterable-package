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
- **Full-Text Search:** Intelligent full-text search with automatic database adapter (PostgreSQL native search with GIN indexes, MySQL/SQLite fallback).
- **Database Compatibility:** Database-specific optimizations for PostgreSQL, MySQL, and SQLite.
- **Dynamic Sorting:** Customize sorting behavior directly from requests.
- **Relationship Filters:** Use advanced conditional logic like `whereAny`, `whereAll`, and `whereNone` for relational queries.
- **JSON Support:** Directly filter JSON columns with dot-notation.
- **Performance Optimizations:** Built-in caching and efficient query construction.
- **Date Handling:** Smart handling of date fields with Carbon integration.

## 📖 Documentation

- **[Complete Filter Reference](FILTER_REFERENCE.md)** - Detailed explanation of every filter type with all parameters explained
- **[Practical Examples](EXAMPLES.md)** - Real-world use cases and code examples
- **[README](README.md)** - Quick start and overview (this file)

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
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->filtrable([
                // Text search with custom LIKE pattern
                Filter::like('name', 'search')
                    ->setLikePattern('{{value}}%'), // Starts with search

                // Price range filter
                Filter::between('price', 'price_range'),

                // Date filter with automatic time handling
                Filter::exact('created_at', 'date')
                    ->castDate()
                    ->endOfDay(),

                // JSON field filtering
                Filter::json('attributes', 'specs.color', 'LIKE', 'color')
                    ->setDatabaseDriver('mysql'),

                // Relationship with eager loading
                Filter::relationship('category', 'slug', '=', 'category')
                    ->with(),

                // Full-text search across multiple columns
                Filter::fullText(['name', 'description'], 'q')
                    ->setFullTextLanguage('portuguese'),
            ])
            ->customPaginate('paginate', $request->input('per_page', 15), [
                'per_page' => $request->input('per_page', 15),
                'sort' => $request->input('sort', '-created_at'),
            ]);

        return response()->json($products);
    }
}
```

**Example Request:**
```bash
GET /api/products?filter[search]=laptop&filter[price_range]=100,500&filter[category]=electronics&per_page=20&sort=-price
```

## Quick Filter Reference

> **📚 For detailed explanations of all parameters, see [FILTER_REFERENCE.md](FILTER_REFERENCE.md)**

### Understanding Parameters

Every filter follows this pattern:
```php
Filter::method($attribute, $requestParameter)
//               ↑              ↑
//        database column   URL param name
```

**Example:**
```php
Filter::exact('status', 'product_status')
```

**Request:**
```
GET /api/products?filter[product_status]=active
                         ↑ parameter      ↑ value
```

**SQL:**
```sql
WHERE status = 'active'
      ↑ column  ↑ value
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
- **Full-Text Search:** `Filter::fullText(['title', 'content'], 'search')` **(Database-specific)**

#### Full-Text Search

> **📚 [Complete Full-Text Search Documentation](FILTER_REFERENCE.md#full-text-search-detailed)** - Includes GIN index setup, performance tips, and all configuration options

The `fullText()` filter provides powerful text search capabilities that automatically adapt to your database.

**Syntax:**
```php
Filter::fullText($columns, $requestParameter)
//                ↑              ↑
//        array or string   URL param name
```

**Parameter 1: Columns**
- **Array:** Search multiple columns `['title', 'content', 'tags']`
- **String:** Single column `'name'` or pre-computed `'search_vector'`

**Parameter 2: Request Parameter**
- URL parameter name (e.g., `'search'`, `'q'`)
- Defaults to `'search'` if omitted

**Database Strategies:**
- **PostgreSQL:** Native full-text with `to_tsvector`, `to_tsquery`, GIN indexes (10-100x faster)
- **MySQL/SQLite:** Falls back to `LIKE` across multiple columns

**Basic Examples:**

```php
// Search across multiple columns
Filter::fullText(['title', 'content', 'tags'], 'search')
//                ↑       ↑          ↑         ↑
//            columns to search      request param
```

**Request:** `GET /api/posts?filter[search]=laravel framework`

**Configuration Methods:**

**1. Language (PostgreSQL only):**
```php
Filter::fullText(['title', 'content'], 'q')
    ->setFullTextLanguage('portuguese')  // Portuguese stemming
//                         ↑
//                  language name
```

**Available:** `'simple'`, `'english'`, `'portuguese'`, `'spanish'`, `'french'`, etc.

**2. Prefix Matching:**
```php
Filter::fullText(['name'], 'q')
    ->setFullTextPrefixMatch(false)  // Exact words only (no wildcards)
//                           ↑
//                        true = "test" matches "testing"
//                        false = "test" matches "test" only
```

**High-Performance Setup (PostgreSQL with GIN Index):**

```php
// Step 1: Migration - Create search_vector column with GIN index
Schema::table('products', function (Blueprint $table) {
    $table->tsvector('search_vector')->nullable();
});
DB::statement('CREATE INDEX products_search_idx ON products USING GIN(search_vector)');

// Step 2: Use in filter (10-100x faster than regular columns!)
Filter::fullText('search_vector', 'q')
//                ↑
//        pre-computed column (not array)
    ->setDatabaseDriver('pgsql')
```

**Request:** `GET /api/products?filter[q]=macbook pro`

**Performance:** 5ms vs 500ms on 1M rows (100x faster!)

> 💡 **See [FILTER_REFERENCE.md](FILTER_REFERENCE.md#using-search_vector-with-gin-index)** for complete GIN index setup with triggers

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

#### Simple Relationship Filter
```php
// Filter posts by user name from request parameter
Filter::relationship('user', 'name', '=', 'user_name')
    ->with(); // Eager load user relationship
```
**Request:** `?filter[user_name]=John`

#### Relationship with whereAny (OR logic)
```php
// Products with tags that are EITHER 'sale' OR 'featured'
Filter::relationship('tags', 'name')
    ->whereAny([
        ['name', '=', 'sale'],
        ['name', '=', 'featured'],
    ])
    ->with(); // ✅ Correct - no setValue() needed
```

#### Relationship with whereAll (AND logic)
```php
// Users who have BOTH conditions true
Filter::relationship('permissions', 'name')
    ->whereAll([
        ['name', '=', 'edit-posts'],
        ['is_active', '=', true],
    ])
    ->with(); // ✅ Correct - conditions are hardcoded
```

#### Relationship with whereNone (NOT logic)
```php
// Posts that have NO banned tags
Filter::relationship('tags', 'is_banned')
    ->whereNone([
        ['is_banned', '=', true],
    ])
    ->with();
```

#### Using Dynamic Values in Relationship Conditions
```php
// ✅ CORRECT way to use dynamic values
Filter::relationship('user', 'id')
    ->whereAll([
        ['id', '=', auth()->id()], // Dynamic value in conditions array
        ['active', '=', true],
        ['verified', '=', true],
    ])
    ->with();

// ❌ WRONG - setValue() doesn't work with whereAll/whereAny/whereNone
Filter::relationship('user', 'id')
    ->whereAll([...])
    ->setValue(auth()->id()); // This has no effect!
```

## Customizing Pagination and Sorting

The package provides flexible pagination options through the `customPaginate` method, supporting three pagination types:

### Standard Pagination (with total count)

```php
$results = Expense::query()
    ->filtrable([...])
    ->customPaginate('paginate', 15);

// Returns: total, last_page, current_page, per_page, etc.
```

### Simple Pagination (without total count - better performance)

```php
$results = Expense::query()
    ->filtrable([...])
    ->customPaginate('simple', 15);

// Returns: current_page, per_page, next_page_url, prev_page_url (no total)
```

### Cursor Pagination (most performant for large datasets)

```php
$results = Expense::query()
    ->filtrable([...])
    ->customPaginate('cursor', 15);

// Returns: cursor-based navigation (ideal for infinite scroll)
```

### Custom Parameters

You can pass custom data to append to pagination links:

```php
$results = Expense::query()
    ->filtrable([...])
    ->customPaginate('paginate', 15, [
        'per_page' => 15,
        'sort' => '-created_at'
    ]);
```

**Sorting:**
- `-` (minus) prefix indicates descending sorting (e.g., `-amount`)
- Ascending sort uses the field name directly (e.g., `amount`)

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
            ->customPaginate('paginate', $request->input('per_page', 15), [
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

## Practical Examples

### Example 1: E-commerce Product Filtering

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
        $products = Product::query()
            ->filtrable([
                // Search by name (starts with)
                Filter::like('name', 'search')
                    ->setLikePattern('{{value}}%'),

                // Price range
                Filter::between('price', 'price_range'),

                // Specific categories (multiple)
                Filter::in('category_id', 'categories'),

                // Exclude out of stock
                Filter::notEquals('stock_status', 'exclude_status'),

                // Featured products only
                Filter::exact('is_featured', 'featured'),

                // Filter by brand relationship
                Filter::relationship('brand', 'slug', '=', 'brand')
                    ->with(),

                // Products with ANY of these tags
                Filter::relationship('tags', 'name')
                    ->whereAny([
                        ['name', '=', 'sale'],
                        ['name', '=', 'new'],
                        ['name', '=', 'featured'],
                    ])
                    ->with(),

                // Full-text search across multiple fields
                Filter::fullText(['name', 'description', 'sku'], 'q')
                    ->setFullTextLanguage('portuguese'),
            ])
            ->customPaginate(
                $request->input('pagination_type', 'paginate'),
                $request->input('per_page', 20),
                [
                    'per_page' => $request->input('per_page', 20),
                    'sort' => $request->input('sort', '-created_at'),
                ]
            );

        return response()->json($products);
    }
}
```

**Example Requests:**
```bash
# Basic search
GET /api/products?filter[search]=notebook

# Search with price range
GET /api/products?filter[search]=notebook&filter[price_range]=500,2000

# Multiple categories
GET /api/products?filter[categories]=1,2,3

# Exclude status and filter by brand
GET /api/products?filter[exclude_status]=out_of_stock&filter[brand]=apple

# Full-text search with sorting
GET /api/products?filter[q]=macbook pro&sort=-price&per_page=50

# Products on sale or featured
GET /api/products?filter[search]=laptop

# Combined filters
GET /api/products?filter[search]=phone&filter[price_range]=1000,3000&filter[brand]=samsung&filter[featured]=1&sort=-created_at
```

### Example 2: Blog Post Filtering

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $posts = Post::query()
            ->filtrable([
                // Search in title and content
                Filter::fullText(['title', 'content', 'excerpt'], 'search')
                    ->setFullTextLanguage('portuguese')
                    ->setFullTextPrefixMatch(true),

                // Filter by status
                Filter::exact('status', 'status'),

                // Published date range
                Filter::between('published_at', 'date_range'),

                // Posts by specific author
                Filter::relationship('author', 'id', '=', 'author_id')
                    ->with(),

                // Posts with ALL these categories
                Filter::relationship('categories', 'slug')
                    ->whereAll([
                        ['slug', '=', $request->input('filter.primary_category')],
                        ['is_active', '=', true],
                    ])
                    ->with(),

                // Posts tagged with ANY of these tags
                Filter::relationship('tags', 'slug', '=', 'tags')
                    ->with(),

                // Only published posts
                Filter::isNotNull('published_at', 'published'),

                // Featured posts
                Filter::exact('is_featured', 'featured'),
            ])
            ->customPaginate('cursor', 10); // Use cursor for better performance

        return response()->json($posts);
    }
}
```

**Example Requests:**
```bash
# Search published posts
GET /api/posts?filter[search]=laravel&filter[published]=1

# Posts by date range
GET /api/posts?filter[date_range]=2024-01-01,2024-12-31

# Posts by author with specific tags
GET /api/posts?filter[author_id]=5&filter[tags]=tutorial

# Featured posts only
GET /api/posts?filter[featured]=1&filter[status]=published

# Full-text search with cursor pagination
GET /api/posts?filter[search]=php framework&cursor=eyJpZCI6MTAwfQ
```

### Example 3: User Management with Permissions

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use DevactionLabs\FilterablePackage\Filter;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->filtrable([
                // Search by name or email
                Filter::like('name', 'search'),
                Filter::like('email', 'email'),

                // Active users only
                Filter::exact('is_active', 'active'),

                // Users with specific role
                Filter::relationship('roles', 'name', '=', 'role')
                    ->with(),

                // Users with ALL required permissions
                Filter::relationship('permissions', 'name')
                    ->whereAll([
                        ['name', '=', 'edit-posts'],
                        ['name', '=', 'publish-posts'],
                    ])
                    ->with(),

                // Users registered in date range
                Filter::between('created_at', 'registration_date'),

                // Verified users
                Filter::isNotNull('email_verified_at', 'verified'),

                // Exclude specific users
                Filter::notIn('id', 'exclude_users'),
            ])
            ->customPaginate('paginate', 15, [
                'per_page' => $request->input('per_page', 15),
                'sort' => $request->input('sort', 'name'),
            ]);

        return response()->json($users);
    }
}
```

**Example Requests:**
```bash
# Search active users
GET /api/users?filter[search]=john&filter[active]=1

# Users with admin role
GET /api/users?filter[role]=admin&filter[verified]=1

# Users registered this year
GET /api/users?filter[registration_date]=2024-01-01,2024-12-31

# Exclude specific users
GET /api/users?filter[exclude_users]=1,2,3&sort=name
```

### Example 4: Advanced JSON Filtering

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
        $products = Product::query()
            ->filtrable([
                // JSON field filtering - exact match
                Filter::json('specifications', 'dimensions.width', '=', 'width')
                    ->setDatabaseDriver('pgsql'),

                // JSON field - greater than
                Filter::json('specifications', 'weight', '>', 'min_weight')
                    ->setDatabaseDriver('pgsql'),

                // JSON field - LIKE search
                Filter::json('specifications', 'material', 'LIKE', 'material')
                    ->setDatabaseDriver('pgsql'),

                // Multiple JSON paths
                Filter::json('metadata', 'seo.keywords', 'LIKE', 'keywords')
                    ->setDatabaseDriver('mysql'),
            ])
            ->customPaginate('paginate', 20);

        return response()->json($products);
    }
}
```

**Example Requests:**
```bash
# Filter by JSON fields
GET /api/products?filter[width]=50&filter[min_weight]=2.5

# Search in nested JSON
GET /api/products?filter[material]=cotton&filter[keywords]=organic
```

## Common Mistakes and How to Avoid Them

### ❌ Mistake 1: Using setValue() with whereAny/whereAll/whereNone

**Wrong:**
```php
// setValue() has no effect here because conditions are already hardcoded
Filter::relationship('tags', 'name')
    ->whereAny([
        ['name', '=', 'featured'],
        ['name', '=', 'sale'],
    ])
    ->setValue('custom_value') // ❌ This doesn't work
    ->with();
```

**Correct:**
```php
// Remove setValue() - the conditions already define what to match
Filter::relationship('tags', 'name')
    ->whereAny([
        ['name', '=', 'featured'],
        ['name', '=', 'sale'],
    ])
    ->with(); // ✅ This works correctly
```

### ❌ Mistake 2: Dynamic Values in whereAll

**Wrong:**
```php
// Trying to use setValue() for dynamic values
Filter::relationship('user', 'id')
    ->whereAll([
        ['active', '=', true],
        ['role', '=', 'admin'],
    ])
    ->setValue(auth()->id()); // ❌ This doesn't filter by user ID
```

**Correct:**
```php
// Include dynamic values directly in the conditions array
Filter::relationship('user', 'id')
    ->whereAll([
        ['id', '=', auth()->id()], // ✅ Dynamic value here
        ['active', '=', true],
        ['role', '=', 'admin'],
    ])
    ->with(); // ✅ This works correctly
```

### ❌ Mistake 3: Forgetting ->with() for Eager Loading

**Wrong:**
```php
// Relationship will cause N+1 queries
Filter::relationship('category', 'slug', '=', 'category'); // ❌ Missing ->with()
```

**Correct:**
```php
// Eager load the relationship to avoid N+1
Filter::relationship('category', 'slug', '=', 'category')
    ->with(); // ✅ Eager loads the relationship
```

### ❌ Mistake 4: Wrong Database Driver for JSON Filters

**Wrong:**
```php
// Database driver not set - may not work correctly
Filter::json('data', 'user.name', '=', 'name'); // ❌ Driver not set
```

**Correct:**
```php
// Always set the database driver for JSON filters
Filter::json('data', 'user.name', '=', 'name')
    ->setDatabaseDriver('pgsql'); // ✅ Driver specified
```

### ❌ Mistake 5: Using Between with Non-Array Values

**Wrong:**
```php
// Between expects array but gets string from request
// Request: ?filter[price_range]=100
$filter = Filter::between('price', 'price_range'); // ❌ Will fail
```

**Correct:**
```php
// Request should send comma-separated values
// Request: ?filter[price_range]=100,500
$filter = Filter::between('price', 'price_range'); // ✅ Automatically converts "100,500" to [100, 500]
```

### ❌ Mistake 6: Not Using castDate() for Date Filters

**Wrong:**
```php
// String date won't work with endOfDay()
Filter::exact('created_at', 'date')
    ->endOfDay(); // ❌ Expects Carbon instance
```

**Correct:**
```php
// Use castDate() to convert string to Carbon
Filter::exact('created_at', 'date')
    ->castDate() // ✅ Converts to Carbon first
    ->endOfDay(); // ✅ Now this works
```

### ✅ Best Practices

1. **Always use ->with() for relationships you need in the response**
   ```php
   Filter::relationship('user', 'id')->with() // Eager load
   ```

2. **Set database driver for JSON filters**
   ```php
   Filter::json('data', 'path')->setDatabaseDriver(config('database.default'))
   ```

3. **Use full-text search for better search experience**
   ```php
   Filter::fullText(['title', 'content'], 'search')
       ->setFullTextLanguage('portuguese')
   ```

4. **Prefer whereAny/whereAll over multiple separate relationship filters**
   ```php
   // Better performance
   Filter::relationship('user', 'email')
       ->whereAll([
           ['email', '=', 'test@example.com'],
           ['active', '=', true],
       ])
   ```

5. **Use cursor pagination for large datasets**
   ```php
   ->customPaginate('cursor', 20) // Better performance than 'paginate'
   ```

6. **Define allowedSorts in your model to prevent SQL injection**
   ```php
   protected array $allowedSorts = ['name', 'created_at', 'price'];
   ```

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
- [Alex Nogueira](https://github.com/alexnogueirasilva)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
