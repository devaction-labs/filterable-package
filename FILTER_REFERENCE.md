# Complete Filter Reference Guide

## Table of Contents
- [Understanding Parameters](#understanding-parameters)
- [Full-Text Search (Detailed)](#full-text-search-detailed)
- [All Filter Types](#all-filter-types)
- [Practical Examples](#practical-examples)

---

## Understanding Parameters

Every filter follows this pattern:

```php
Filter::method($attribute, $requestParameter)
//               ↑              ↑
//        database column   URL param name
```

### Parameter Breakdown

**Parameter 1: `$attribute` (required)**
- The **database column name** you want to filter
- Example: `'status'`, `'price'`, `'created_at'`

**Parameter 2: `$requestParameter` (optional)**
- The **URL query parameter name**
- Defaults to `$attribute` if omitted
- Example: `'product_status'`, `'min_price'`, `'date'`

### Example

```php
Filter::exact('status', 'product_status')
```

**URL:**
```
GET /api/products?filter[product_status]=active
                         ↑ parameter      ↑ value
```

**SQL:**
```sql
WHERE status = 'active'
      ↑ column  ↑ value
```

---

## Full-Text Search (Detailed)

### Basic Syntax

```php
Filter::fullText($columns, $requestParameter)
```

### Parameter 1: Columns (array|string)

**WHY:** Defines which database columns to search in

**Option A: Array (Multiple Columns)**
```php
Filter::fullText(['name', 'description', 'tags'], 'q')
//                ↑       ↑              ↑        ↑
//            column 1  column 2     column 3  request param
```

**What it does:**
- Searches across **ALL specified columns**
- Finds matches in ANY column
- More comprehensive search results

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('simple', COALESCE(name, '')) ||
    to_tsvector('simple', COALESCE(description, '')) ||
    to_tsvector('simple', COALESCE(tags, ''))
) @@ to_tsquery('simple', 'search:*')
```

**MySQL/SQLite SQL:**
```sql
WHERE (
    name LIKE '%search%' OR
    description LIKE '%search%' OR
    tags LIKE '%search%'
)
```

**Option B: String (Single Column)**
```php
Filter::fullText('name', 'q')
//                ↑      ↑
//            column  param
```

**What it does:**
- Searches in **ONE column only**
- Faster than multiple columns
- Use when you know which field to search

**Option C: search_vector (Pre-computed)**
```php
Filter::fullText('search_vector', 'q')
//                ↑
//        special indexed column
```

**What it does:**
- Uses **pre-processed** text column
- 10-100x **faster** than regular columns
- Requires database setup (PostgreSQL only)

### Parameter 2: Request Parameter

```php
Filter::fullText(['name', 'description'], 'q')
//                                         ↑
//                                  request param name
```

**URL:**
```
GET /api/products?filter[q]=laptop
                         ↑   ↑
                      param  value
```

**Default:** `'search'` if omitted

```php
Filter::fullText(['name', 'description'])
// Same as:
Filter::fullText(['name', 'description'], 'search')
```

### Configuration: setFullTextLanguage()

**WHY:** Different languages have different word stems

**Syntax:**
```php
->setFullTextLanguage('language_name')
```

**What is stemming?**
- English: "running" → "run", "runner" → "run"
- Portuguese: "correndo" → "corr", "correr" → "corr"

**Example:**
```php
Filter::fullText(['title', 'content'], 'q')
    ->setFullTextLanguage('portuguese')
```

**Available Languages (PostgreSQL):**
- `'simple'` - No stemming (default)
- `'english'` - English rules
- `'portuguese'` - Portuguese rules
- `'spanish'` - Spanish rules
- `'french'` - French rules
- `'german'` - German rules
- More... (depends on PostgreSQL installation)

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('portuguese', title) ||
    to_tsvector('portuguese', content)
) @@ to_tsquery('portuguese', 'search:*')
--              ↑ language applied here
```

**Note:** MySQL/SQLite ignore language setting

### Configuration: setFullTextPrefixMatch()

**WHY:** Control whether partial words match

**Syntax:**
```php
->setFullTextPrefixMatch(true|false)
```

**What is prefix matching?**

**Enabled (default):**
```php
->setFullTextPrefixMatch(true)
```
- `"lap"` matches: "lap", "**lap**top", "**lap**el"
- Uses wildcard `*` in PostgreSQL

**Disabled:**
```php
->setFullTextPrefixMatch(false)
```
- `"lap"` matches: "lap" only
- No wildcard in PostgreSQL

**Example:**
```php
Filter::fullText(['name'], 'q')
    ->setFullTextPrefixMatch(true)  // Default
```

**URL:**
```
GET /api/products?filter[q]=test
```

**Matches:**
- "test" ✓
- "testing" ✓
- "tester" ✓

**PostgreSQL SQL:**
```sql
WHERE to_tsvector('simple', name) @@ to_tsquery('simple', 'test:*')
--                                                             ↑ wildcard
```

---

### Using search_vector with GIN Index

**WHAT:** Pre-computed column for ultra-fast searches

**WHY:**
- 10-100x faster than regular columns
- Scales to millions of rows
- Updates automatically via trigger

**Step 1: Create Migration**

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

public function up()
{
    // 1. Add tsvector column
    Schema::table('products', function (Blueprint $table) {
        $table->tsvector('search_vector')->nullable();
    });

    // 2. Create GIN index (fast searching)
    DB::statement('
        CREATE INDEX products_search_vector_idx
        ON products
        USING GIN(search_vector)
    ');

    // 3. Create trigger (auto-update on insert/update)
    DB::statement("
        CREATE TRIGGER products_search_vector_update
        BEFORE INSERT OR UPDATE ON products
        FOR EACH ROW EXECUTE FUNCTION
        tsvector_update_trigger(
            search_vector,           -- column to update
            'pg_catalog.portuguese', -- language
            name,                    -- columns to index
            description,
            sku
        );
    ");
}
```

**WHAT EACH PART DOES:**

1. **tsvector column:** Stores processed text
2. **GIN index:** Makes searches blazing fast
3. **Trigger:** Auto-updates search_vector when data changes

**Step 2: Use in Filter**

```php
Filter::fullText('search_vector', 'q')
//                ↑
//        column name (not array!)
    ->setDatabaseDriver('pgsql')
```

**URL:**
```
GET /api/products?filter[q]=macbook pro
```

**PostgreSQL SQL:**
```sql
WHERE search_vector @@ websearch_to_tsquery('simple', 'macbook pro')
--    ↑ indexed!      ↑ optimized function
```

**Performance Comparison:**

| Method | 1M Rows | Notes |
|--------|---------|-------|
| Regular columns | ~500ms | Slow, scans all rows |
| search_vector | ~5ms | 100x faster! |

**WHY SO FAST?**
1. ✅ Pre-processed text (no to_tsvector() on every query)
2. ✅ GIN index lookup (instant)
3. ✅ Optimized query function (websearch_to_tsquery)

---

### Complete Full-Text Examples

#### Example 1: Multi-Column Search (Default)

```php
Filter::fullText(['title', 'content', 'excerpt'], 'search')
```

**WHAT:**
- Columns: `title`, `content`, `excerpt`
- Request param: `filter[search]`
- Language: `simple` (default)
- Prefix match: `true` (default)

**URL:**
```
GET /api/posts?filter[search]=laravel framework
```

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('simple', COALESCE(title, '')) ||
    to_tsvector('simple', COALESCE(content, '')) ||
    to_tsvector('simple', COALESCE(excerpt, ''))
) @@ to_tsquery('simple', 'laravel:* & framework:*')
```

#### Example 2: Portuguese with Exact Matching

```php
Filter::fullText(['title', 'body'], 'q')
    ->setFullTextLanguage('portuguese')  // Portuguese stemming
    ->setFullTextPrefixMatch(false)      // No wildcards
```

**WHAT:**
- Language: Portuguese word stems
- Prefix match: Disabled (exact words only)

**URL:**
```
GET /api/articles?filter[q]=desenvolvimento software
```

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('portuguese', COALESCE(title, '')) ||
    to_tsvector('portuguese', COALESCE(body, ''))
) @@ to_tsquery('portuguese', 'desenvolvimento & software')
--                            ↑ Portuguese stemming  ↑ no wildcards
```

**MATCHES:**
- "desenvolvimento de software" ✓
- "desenvolver software" ✓ (stem matches)
- "desenvolv" ✗ (prefix match disabled)

#### Example 3: High-Performance search_vector

```php
Filter::fullText('search_vector', 'q')
    ->setDatabaseDriver('pgsql')
```

**WHAT:**
- Uses pre-computed `search_vector` column
- GIN indexed for speed
- Best for large datasets

**URL:**
```
GET /api/products?filter[q]=macbook pro 13
```

**PostgreSQL SQL:**
```sql
WHERE search_vector @@ websearch_to_tsquery('simple', 'macbook pro 13')
```

**PERFORMANCE:**
- Regular: 500ms (1M rows)
- search_vector: 5ms (1M rows)
- **100x faster!**

---

## All Filter Types

### 1. Filter::exact()

**PURPOSE:** Exact match (SQL `=`)

**SYNTAX:**
```php
Filter::exact($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::exact('status', 'order_status')
```

**URL:**
```
GET /api/orders?filter[order_status]=pending
```

**SQL:**
```sql
WHERE status = 'pending'
```

**USE CASES:**
- Status matching
- ID lookups
- Boolean flags
- Enum values

---

### 2. Filter::like()

**PURPOSE:** Pattern matching with custom wildcards

**SYNTAX:**
```php
Filter::like($column, $requestParam)
    ->setLikePattern($pattern)  // Optional
```

**PARAMETER: setLikePattern()**
- `{{value}}` = placeholder for search term
- `%` = wildcard (any characters)

**DEFAULT PATTERN:** `'%{{value}}%'` (contains)

**CUSTOM PATTERNS:**

**a) Starts With:**
```php
Filter::like('name', 'prefix')
    ->setLikePattern('{{value}}%')
//                   ↑ term   ↑ wildcard
```

**URL:**
```
GET /api/products?filter[prefix]=mac
```

**SQL:**
```sql
WHERE name LIKE 'mac%'
-- Matches: "macbook", "mac pro"
-- No match: "imac" (doesn't start with 'mac')
```

**b) Ends With:**
```php
Filter::like('email', 'domain')
    ->setLikePattern('%{{value}}')
//                   ↑ wildcard ↑ term
```

**URL:**
```
GET /api/users?filter[domain]=@gmail.com
```

**SQL:**
```sql
WHERE email LIKE '%@gmail.com'
-- Matches: "user@gmail.com", "admin@gmail.com"
```

**c) Contains (Default):**
```php
Filter::like('description', 'search')
// Pattern: '%{{value}}%' (default)
```

**URL:**
```
GET /api/products?filter[search]=laptop
```

**SQL:**
```sql
WHERE description LIKE '%laptop%'
-- Matches anywhere in text
```

---

### 3. Filter::ilike()

**PURPOSE:** Case-insensitive LIKE

**SYNTAX:**
```php
Filter::ilike($column, $requestParam)
```

**DATABASE BEHAVIOR:**

| Database | How it works |
|----------|--------------|
| PostgreSQL | `name ILIKE '%value%'` (native) |
| SQLite | `name LIKE '%value%'` (already case-insensitive) |
| MySQL | `LOWER(name) LIKE LOWER('%value%')` |

**EXAMPLE:**
```php
Filter::ilike('name', 'search')
```

**URL:**
```
GET /api/products?filter[search]=LAPTOP
```

**MATCHES:**
- "laptop" ✓
- "Laptop" ✓
- "LAPTOP" ✓
- "LaPtOp" ✓

---

### 4. Filter::startsWith()

**PURPOSE:** Matches values that start with prefix

**SYNTAX:**
```php
Filter::startsWith($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::startsWith('sku', 'code')
```

**URL:**
```
GET /api/products?filter[code]=PRD
```

**SQL:**
```sql
WHERE sku LIKE 'PRD%'
-- Matches: "PRD001", "PRD-LAPTOP"
-- No match: "PRODUCT-001"
```

---

### 5. Filter::endsWith()

**PURPOSE:** Matches values that end with suffix

**SYNTAX:**
```php
Filter::endsWith($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::endsWith('filename', 'ext')
```

**URL:**
```
GET /api/files?filter[ext]=.pdf
```

**SQL:**
```sql
WHERE filename LIKE '%.pdf'
-- Matches: "document.pdf", "report.pdf"
-- No match: "document.pdf.zip"
```

---

### 6. Filter::notLike()

**PURPOSE:** Exclude pattern matches

**SYNTAX:**
```php
Filter::notLike($column, $requestParam)
    ->setLikePattern($pattern)  // Optional
```

**EXAMPLE:**
```php
Filter::notLike('email', 'exclude')
    ->setLikePattern('%{{value}}')
```

**URL:**
```
GET /api/users?filter[exclude]=@spam.com
```

**SQL:**
```sql
WHERE email NOT LIKE '%@spam.com'
-- Excludes any email ending with @spam.com
```

---

### 7. Filter::in()

**PURPOSE:** Match any value in list (SQL `IN`)

**SYNTAX:**
```php
Filter::in($column, $requestParam)
```

**REQUEST FORMATS:**

**a) Comma-separated:**
```
GET /api/products?filter[categories]=1,2,3
                                     ↑ automatically split to array
```

**b) Array:**
```
GET /api/products?filter[categories][]=1&filter[categories][]=2
```

**EXAMPLE:**
```php
Filter::in('category_id', 'categories')
```

**SQL:**
```sql
WHERE category_id IN (1, 2, 3)
```

**USE CASES:**
- Multiple categories
- Multiple statuses
- Tag filtering

---

### 8. Filter::notIn()

**PURPOSE:** Exclude values from list

**SYNTAX:**
```php
Filter::notIn($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::notIn('status', 'exclude_statuses')
```

**URL:**
```
GET /api/orders?filter[exclude_statuses]=cancelled,refunded
```

**SQL:**
```sql
WHERE status NOT IN ('cancelled', 'refunded')
```

---

### 9. Filter::between()

**PURPOSE:** Range filter (SQL `BETWEEN`)

**SYNTAX:**
```php
Filter::between($column, $requestParam)
```

**REQUEST FORMAT:** `value1,value2`

**EXAMPLE:**
```php
Filter::between('price', 'price_range')
```

**URL:**
```
GET /api/products?filter[price_range]=100,500
                                       ↑   ↑
                                     min  max
```

**SQL:**
```sql
WHERE price BETWEEN 100 AND 500
```

**USE CASES:**
- Price ranges
- Date ranges
- Age ranges

---

### 10. Filter::gt() / gte() / lt() / lte()

**PURPOSE:** Comparison operators

**SYNTAX:**
```php
Filter::gt($column, $param)   // >  Greater than
Filter::gte($column, $param)  // >= Greater or equal
Filter::lt($column, $param)   // <  Less than
Filter::lte($column, $param)  // <= Less or equal
```

**EXAMPLES:**

**Minimum price:**
```php
Filter::gte('price', 'min_price')
```

**URL:**
```
GET /api/products?filter[min_price]=100
```

**SQL:**
```sql
WHERE price >= 100
```

**Maximum age:**
```php
Filter::lte('age', 'max_age')
```

**URL:**
```
GET /api/users?filter[max_age]=65
```

**SQL:**
```sql
WHERE age <= 65
```

---

### 11. Filter::notEquals()

**PURPOSE:** Not equal (SQL `!=`)

**SYNTAX:**
```php
Filter::notEquals($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::notEquals('status', 'exclude_status')
```

**URL:**
```
GET /api/products?filter[exclude_status]=discontinued
```

**SQL:**
```sql
WHERE status != 'discontinued'
```

---

### 12. Filter::isNull()

**PURPOSE:** Check for NULL values

**SYNTAX:**
```php
Filter::isNull($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::isNull('deleted_at', 'show_deleted')
```

**URL:**
```
GET /api/posts?filter[show_deleted]=1
                                    ↑ any value triggers filter
```

**SQL:**
```sql
WHERE deleted_at IS NULL
```

**NOTE:** Request value doesn't matter, presence applies filter

---

### 13. Filter::isNotNull()

**PURPOSE:** Check for NOT NULL values

**SYNTAX:**
```php
Filter::isNotNull($column, $requestParam)
```

**EXAMPLE:**
```php
Filter::isNotNull('email_verified_at', 'verified')
```

**URL:**
```
GET /api/users?filter[verified]=1
```

**SQL:**
```sql
WHERE email_verified_at IS NOT NULL
```

**USE CASES:**
- Verified users
- Published posts
- Completed orders

---

### 14. Filter::json()

**PURPOSE:** Filter JSON column fields

**SYNTAX:**
```php
Filter::json($jsonColumn, $path, $operator, $requestParam)
//            ↑            ↑      ↑           ↑
//       JSON column    path   operator   param name
    ->setDatabaseDriver($driver)  // Required!
```

**PARAMETERS:**
1. `$jsonColumn` - JSON column name
2. `$path` - Dot-notation path ('user.name')
3. `$operator` - SQL operator ('=', 'LIKE', '>')
4. `$requestParam` - URL parameter name

**ALWAYS SET DRIVER:**
```php
->setDatabaseDriver('pgsql')  // or 'mysql', 'sqlite'
```

**EXAMPLES:**

**a) Exact match:**
```php
Filter::json('attributes', 'color', '=', 'color')
    ->setDatabaseDriver('pgsql')
```

**URL:**
```
GET /api/products?filter[color]=red
```

**PostgreSQL SQL:**
```sql
WHERE attributes->>'color' = 'red'
```

**b) Nested path:**
```php
Filter::json('metadata', 'dimensions.width', '>', 'min_width')
    ->setDatabaseDriver('mysql')
```

**URL:**
```
GET /api/products?filter[min_width]=50
```

**MySQL SQL:**
```sql
WHERE metadata->>'$.dimensions.width' > 50
```

**c) Pattern match:**
```php
Filter::json('specs', 'material', 'LIKE', 'material')
    ->setDatabaseDriver('pgsql')
```

**URL:**
```
GET /api/products?filter[material]=cotton
```

**SQL:**
```sql
WHERE specs->>'material' LIKE '%cotton%'
```

---

### 15. Filter::relationship()

**PURPOSE:** Filter by related model

**SYNTAX:**
```php
Filter::relationship($relation, $column, $operator, $requestParam)
//                    ↑          ↑        ↑          ↑
//              relationship  column   operator  param name
    ->with()  // Eager load (optional but recommended)
```

**PARAMETERS:**
1. `$relation` - Laravel relationship name
2. `$column` - Column in related table
3. `$operator` - SQL operator (default '=')
4. `$requestParam` - URL parameter name

**METHOD: ->with()**
- Eager loads the relationship
- Prevents N+1 queries
- **Always use for better performance**

**EXAMPLES:**

**a) Simple relationship:**
```php
Filter::relationship('category', 'slug', '=', 'category')
    ->with()
```

**URL:**
```
GET /api/products?filter[category]=electronics
```

**SQL:**
```sql
WHERE EXISTS (
    SELECT * FROM categories
    WHERE categories.id = products.category_id
    AND categories.slug = 'electronics'
)
```

**b) whereAny (OR logic):**

**SYNTAX:**
```php
->whereAny([
    [$column, $operator, $value],
    [$column, $operator, $value],  // OR
])
```

**EXAMPLE:**
```php
Filter::relationship('tags', 'name')
    ->whereAny([
        ['name', '=', 'sale'],      // condition 1
        ['name', '=', 'featured'],  // OR condition 2
    ])
    ->with()
```

**WHAT:** Product has tag 'sale' **OR** 'featured'

**SQL:**
```sql
WHERE EXISTS (
    SELECT * FROM tags
    INNER JOIN product_tag ON tags.id = product_tag.tag_id
    WHERE product_tag.product_id = products.id
    AND (tags.name = 'sale' OR tags.name = 'featured')
)
```

**c) whereAll (AND logic):**

**SYNTAX:**
```php
->whereAll([
    [$column, $operator, $value],
    [$column, $operator, $value],  // AND
])
```

**EXAMPLE:**
```php
Filter::relationship('user', 'id')
    ->whereAll([
        ['id', '=', auth()->id()],  // condition 1
        ['active', '=', true],      // AND condition 2
        ['verified', '=', true],    // AND condition 3
    ])
    ->with()
```

**WHAT:** User must match **ALL** conditions

**SQL:**
```sql
WHERE EXISTS (
    SELECT * FROM users
    WHERE users.id = posts.user_id
    AND users.id = 123
    AND users.active = 1
    AND users.verified = 1
)
```

**d) whereNone (NOT logic):**

**SYNTAX:**
```php
->whereNone([
    [$column, $operator, $value],
])
```

**EXAMPLE:**
```php
Filter::relationship('tags', 'name')
    ->whereNone([
        ['name', '=', 'banned'],
        ['name', '=', 'spam'],
    ])
```

**WHAT:** Product has **NO** tags named 'banned' or 'spam'

**SQL:**
```sql
WHERE NOT EXISTS (
    SELECT * FROM tags
    INNER JOIN product_tag ON tags.id = product_tag.tag_id
    WHERE product_tag.product_id = products.id
    AND (tags.name = 'banned' OR tags.name = 'spam')
)
```

---

### 16. Date Filters with Carbon

**PURPOSE:** Handle dates with automatic conversion

**SYNTAX:**
```php
Filter::exact($column, $requestParam)
    ->castDate()      // Convert string → Carbon
    ->startOfDay()    // Set to 00:00:00 (optional)
    ->endOfDay()      // Set to 23:59:59 (optional)
```

**METHODS:**
- `->castDate()` - Convert to Carbon instance
- `->startOfDay()` - Time = 00:00:00
- `->endOfDay()` - Time = 23:59:59

**EXAMPLES:**

**a) Start of day:**
```php
Filter::exact('created_at', 'date')
    ->castDate()
    ->startOfDay()
```

**URL:**
```
GET /api/orders?filter[date]=2024-01-15
```

**SQL:**
```sql
WHERE created_at BETWEEN
    '2024-01-15 00:00:00' AND
    '2024-01-15 23:59:59'
```

**b) End of day:**
```php
Filter::exact('deadline', 'due')
    ->castDate()
    ->endOfDay()
```

**URL:**
```
GET /api/tasks?filter[due]=2024-12-31
```

**SQL:**
```sql
WHERE deadline BETWEEN
    '2024-12-31 00:00:00' AND
    '2024-12-31 23:59:59'
```

---

## Practical Examples

### E-commerce Product Filtering

```php
use DevactionLabs\FilterablePackage\Filter;

$products = Product::query()
    ->filtrable([
        // Search product name (starts with)
        Filter::like('name', 'search')
            ->setLikePattern('{{value}}%'),
        //                  ↑ no prefix wildcard = starts with

        // Price range
        Filter::between('price', 'price_range'),
        //               ↑        ↑
        //          column name   request param

        // Multiple categories
        Filter::in('category_id', 'categories'),
        //          ↑              ↑
        //     column name    accepts: "1,2,3"

        // Exclude discontinued
        Filter::notEquals('status', 'exclude_status'),

        // Filter by brand (relationship)
        Filter::relationship('brand', 'slug', '=', 'brand')
        //                    ↑        ↑      ↑    ↑
        //               relation  column  op  param
            ->with(),  // Eager load brand

        // Products on sale OR featured
        Filter::relationship('tags', 'name')
            ->whereAny([              // OR logic
                ['name', '=', 'sale'],
                ['name', '=', 'featured'],
            ])
            ->with(),

        // Full-text search
        Filter::fullText(['name', 'description', 'sku'], 'q')
        //                ↑       ↑              ↑       ↑
        //            search in these columns    param
            ->setFullTextLanguage('portuguese'),
    ])
    ->customPaginate('paginate', 20);
```

**Example URLs:**

```bash
# Basic search
GET /api/products?filter[search]=laptop

# Search + price range
GET /api/products?filter[search]=laptop&filter[price_range]=1000,3000

# Multiple filters
GET /api/products?filter[categories]=1,2&filter[brand]=apple&filter[exclude_status]=discontinued

# Full-text search
GET /api/products?filter[q]=macbook pro retina&sort=-price
```

### Blog Post Filtering

```php
$posts = Post::query()
    ->filtrable([
        // Full-text search in multiple fields
        Filter::fullText(['title', 'content', 'excerpt'], 'search')
            ->setFullTextLanguage('portuguese')
            ->setFullTextPrefixMatch(true),

        // Status filter
        Filter::exact('status', 'status'),

        // Date range
        Filter::between('published_at', 'date_range'),

        // Author filter
        Filter::relationship('author', 'username', '=', 'author')
            ->with(),

        // Posts with ALL these categories
        Filter::relationship('categories', 'slug')
            ->whereAll([            // AND logic
                ['slug', '=', request('filter.category')],
                ['is_active', '=', true],
            ])
            ->with(),

        // Only published
        Filter::isNotNull('published_at', 'published'),
    ])
    ->customPaginate('cursor', 10);
```

**Example URLs:**

```bash
# Search published posts
GET /api/posts?filter[search]=laravel&filter[published]=1

# By author and date range
GET /api/posts?filter[author]=john&filter[date_range]=2024-01-01,2024-12-31

# Full-text search
GET /api/posts?filter[search]=desenvolvimento web&sort=-published_at
```

---

## Performance Tips

### 1. Use search_vector for Large Datasets

```php
// ❌ Slow on 1M+ rows
Filter::fullText(['title', 'content'], 'q')

// ✅ Fast even with millions of rows
Filter::fullText('search_vector', 'q')
    ->setDatabaseDriver('pgsql')
```

### 2. Always Eager Load Relationships

```php
// ❌ Causes N+1 queries
Filter::relationship('category', 'slug')  // Missing ->with()

// ✅ Prevents N+1 queries
Filter::relationship('category', 'slug')
    ->with()  // Eager loads relationship
```

### 3. Set Database Driver for JSON Filters

```php
// ❌ May not work correctly
Filter::json('data', 'path')  // Missing ->setDatabaseDriver()

// ✅ Works correctly
Filter::json('data', 'path')
    ->setDatabaseDriver('pgsql')
```

### 4. Use Cursor Pagination for Large Sets

```php
// ❌ Slow on large tables (counts all rows)
->customPaginate('paginate', 20)

// ✅ Fast (no total count)
->customPaginate('cursor', 20)
```

### 5. Create Database Indexes

```sql
-- For frequently filtered columns
CREATE INDEX idx_products_price ON products(price);
CREATE INDEX idx_products_status ON products(status);

-- For full-text (PostgreSQL)
CREATE INDEX idx_products_search_vector
ON products USING GIN(search_vector);
```

---

## Common Mistakes

### ❌ Mistake: Using setValue() with whereAny/whereAll

**Wrong:**
```php
Filter::relationship('tags', 'name')
    ->whereAny([
        ['name', '=', 'sale'],
    ])
    ->setValue('custom')  // ❌ Has no effect
```

**Correct:**
```php
Filter::relationship('tags', 'name')
    ->whereAny([
        ['name', '=', 'sale'],  // ✅ Condition already defined
    ])
    ->with()
```

### ❌ Mistake: Dynamic Values with whereAll

**Wrong:**
```php
Filter::relationship('user', 'id')
    ->whereAll([
        ['active', '=', true],
    ])
    ->setValue(auth()->id())  // ❌ Doesn't filter by ID
```

**Correct:**
```php
Filter::relationship('user', 'id')
    ->whereAll([
        ['id', '=', auth()->id()],  // ✅ Dynamic value in array
        ['active', '=', true],
    ])
    ->with()
```

### ❌ Mistake: Forgetting ->castDate()

**Wrong:**
```php
Filter::exact('created_at', 'date')
    ->endOfDay()  // ❌ Needs Carbon instance
```

**Correct:**
```php
Filter::exact('created_at', 'date')
    ->castDate()   // ✅ Convert to Carbon first
    ->endOfDay()
```

---

**See also:**
- [README.md](README.md) - Main documentation
- [CHANGELOG.md](CHANGELOG.md) - Version history
