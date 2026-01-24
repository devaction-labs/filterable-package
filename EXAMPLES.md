# Filterable Package - Detailed Examples & Parameter Explanation

This document provides practical examples and detailed explanations of all filter parameters.

## Table of Contents

- [Understanding Filter Parameters](#understanding-filter-parameters)
- [Full-Text Search Explained](#full-text-search-explained)
- [E-commerce Examples](#e-commerce-examples)
- [Blog/CMS Examples](#blogcms-examples)
- [User Management Examples](#user-management-examples)
- [Advanced Use Cases](#advanced-use-cases)

## Understanding Filter Parameters

Every filter method follows this pattern:
```php
Filter::method($attribute, $requestParameter)
```

### Parameter Explanation

**First Parameter (`$attribute`):** The **database column name** you want to filter
**Second Parameter (`$requestParameter`):** The **URL query parameter name** that will contain the filter value

**Example:**
```php
Filter::exact('status', 'product_status')
//            ↑              ↑
//      column name    request param name
```

**Request URL:**
```bash
GET /api/products?filter[product_status]=active
#                        ↑                  ↑
#                 parameter name      filter value
```

**Generated SQL:**
```sql
WHERE status = 'active'
--    ↑          ↑
-- column    value from request
```

### Why Two Parameters?

**Flexibility:** Your API parameter names don't need to match database column names

**Example:**
```php
// Database column: 'created_at'
// API parameter: 'date'
Filter::exact('created_at', 'date')
```

**Request:**
```bash
GET /api/posts?filter[date]=2024-01-15
# Clean API parameter 'date' maps to 'created_at' column
```

### When Second Parameter is Optional

If you omit the second parameter, it defaults to the first:

```php
Filter::exact('status')
// Same as:
Filter::exact('status', 'status')
```

**Request:**
```bash
GET /api/products?filter[status]=active
```

## Full-Text Search Explained

### Basic Syntax

```php
Filter::fullText($columns, $requestParameter)
//                  ↑              ↑
//          array or string   URL param name
```

### Parameter 1: Columns (array or string)

**Purpose:** Define which database columns to search in

**Array - Multiple Columns:**
```php
Filter::fullText(['name', 'description'], 'q')
//                ↑                ↑
//          column 1         column 2
```

**Why Array?**
- Searches across **multiple fields** simultaneously
- Better search results (finds matches in any column)
- More user-friendly (user doesn't need to know which field contains data)

**PostgreSQL Example:**
```sql
-- Generated SQL (PostgreSQL)
WHERE (
    to_tsvector('simple', name) ||
    to_tsvector('simple', description)
) @@ to_tsquery('simple', 'search_term:*')
```

**MySQL/SQLite Example:**
```sql
-- Generated SQL (MySQL/SQLite)
WHERE (
    name LIKE '%search_term%' OR
    description LIKE '%search_term%'
)
```

**String - Single Column:**
```php
Filter::fullText('name', 'q')
//                ↑
//          single column
```

**Why String?**
- When you only need to search **one specific field**
- Or when using a **pre-computed search_vector** column (PostgreSQL)

### Parameter 2: Request Parameter

**Purpose:** The URL query parameter name for the search term

```php
Filter::fullText(['name', 'description'], 'q')
//                                         ↑
//                                  parameter name
```

**Request:**
```bash
GET /api/products?filter[q]=laptop
#                        ↑     ↑
#                    param  search term
```

**Default:** If omitted, defaults to `'search'`

```php
Filter::fullText(['name', 'description'])
// Same as:
Filter::fullText(['name', 'description'], 'search')
```

### Configuration Methods

#### 1. setFullTextLanguage()

**Purpose:** Set the language for PostgreSQL full-text search

**Why it matters:**
- Different languages have different **stemming rules**
- Example: "running" → "run" in English
- Improves search accuracy for that language

```php
Filter::fullText(['title', 'content'], 'q')
    ->setFullTextLanguage('portuguese')
//                         ↑
//                  language name
```

**Available languages (PostgreSQL):**
- `'simple'` - No stemming, exact words (default)
- `'english'` - English stemming
- `'portuguese'` - Portuguese stemming
- `'spanish'` - Spanish stemming
- `'french'` - French stemming
- And more... (check your PostgreSQL installation)

**Example:**
```php
// Portuguese search
Filter::fullText(['title', 'content'], 'search')
    ->setFullTextLanguage('portuguese')
```

**Request:**
```bash
GET /api/posts?filter[search]=correndo
# Will match: "correr", "correndo", "corrida" (Portuguese stemming)
```

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('portuguese', title) ||
    to_tsvector('portuguese', content)
) @@ to_tsquery('portuguese', 'correndo:*')
```

**Note:** Language setting only affects PostgreSQL. MySQL/SQLite ignore this setting.

#### 2. setFullTextPrefixMatch()

**Purpose:** Enable or disable prefix matching

**What is prefix matching?**
- `true` (default): "test" matches "test", "testing", "tester"
- `false`: "test" matches only "test"

```php
Filter::fullText(['name'], 'q')
    ->setFullTextPrefixMatch(true)  // Enable (default)
//                           ↑
//                        boolean
```

**Example with prefix matching ENABLED (default):**
```php
Filter::fullText(['name'], 'q')
    ->setFullTextPrefixMatch(true)
```

**Request:**
```bash
GET /api/products?filter[q]=lap
```

**Matches:**
- "lap" ✓
- "laptop" ✓
- "lapel" ✓

**PostgreSQL SQL:**
```sql
WHERE to_tsvector('simple', name) @@ to_tsquery('simple', 'lap:*')
--                                                              ↑
--                                                        prefix wildcard
```

**Example with prefix matching DISABLED:**
```php
Filter::fullText(['name'], 'q')
    ->setFullTextPrefixMatch(false)
```

**Request:**
```bash
GET /api/products?filter[q]=lap
```

**Matches:**
- "lap" ✓
- "laptop" ✗
- "lapel" ✗

**PostgreSQL SQL:**
```sql
WHERE to_tsvector('simple', name) @@ to_tsquery('simple', 'lap')
--                                                              ↑
--                                                      no wildcard
```

### Using Pre-computed search_vector (PostgreSQL GIN Index)

**What is search_vector?**
- A **special column** that stores pre-processed text for fast searching
- Uses **GIN index** for blazing-fast full-text queries
- Updates automatically via database trigger

**Step 1: Create Migration**

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

Schema::table('products', function (Blueprint $table) {
    // Add tsvector column
    $table->tsvector('search_vector')->nullable();
});

// Create GIN index for fast searches
DB::statement('CREATE INDEX products_search_vector_idx ON products USING GIN(search_vector)');

// Create trigger to auto-update search_vector
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
```

**Step 2: Use in Filter**

```php
Filter::fullText('search_vector', 'q')
//                ↑
//        pre-computed column (not array!)
    ->setDatabaseDriver('pgsql')
```

**Request:**
```bash
GET /api/products?filter[q]=macbook pro
```

**Generated SQL:**
```sql
WHERE search_vector @@ websearch_to_tsquery('simple', 'macbook pro')
--    ↑                ↑
-- indexed column  optimized function
```

**Performance:**
- ⚡ **10-100x faster** than searching raw columns
- 🎯 **Scales** to millions of rows
- 💾 **Space efficient** with GIN index

**Why search_vector is special:**
1. **Single column** (string, not array)
2. **Already processed** - no need to call `to_tsvector()` on each query
3. Uses **websearch_to_tsquery()** - smarter query parsing
4. **Automatic updates** via trigger

### Complete Full-Text Examples

#### Example 1: Basic Multi-Column Search

```php
Filter::fullText(['title', 'content', 'tags'], 'search')
//                ↑        ↑          ↑         ↑
//           column 1  column 2   column 3   param name
```

**What it does:**
- Searches in 3 columns: `title`, `content`, `tags`
- Uses request parameter `filter[search]`
- Prefix matching enabled (default)
- Language: 'simple' (default)

**Request:**
```bash
GET /api/posts?filter[search]=laravel framework
```

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('simple', COALESCE(title, '')) ||
    to_tsvector('simple', COALESCE(content, '')) ||
    to_tsvector('simple', COALESCE(tags, ''))
) @@ to_tsquery('simple', 'laravel:* & framework:*')
```

**MySQL/SQLite SQL:**
```sql
WHERE (
    title LIKE '%laravel framework%' OR
    content LIKE '%laravel framework%' OR
    tags LIKE '%laravel framework%'
)
```

#### Example 2: Portuguese Language with Exact Matching

```php
Filter::fullText(['title', 'content'], 'q')
    ->setFullTextLanguage('portuguese')  // Portuguese stemming
    ->setFullTextPrefixMatch(false)      // Exact word match only
```

**What it does:**
- Searches `title` and `content` columns
- Uses Portuguese language rules
- Only matches complete words (no prefix matching)

**Request:**
```bash
GET /api/articles?filter[q]=desenvolvimento software
```

**PostgreSQL SQL:**
```sql
WHERE (
    to_tsvector('portuguese', COALESCE(title, '')) ||
    to_tsvector('portuguese', COALESCE(content, ''))
) @@ to_tsquery('portuguese', 'desenvolvimento & software')
--                            ↑                              ↑
--                     Portuguese stemming         no :* wildcard
```

#### Example 3: Optimized with search_vector

```php
Filter::fullText('search_vector', 'q')
//                ↑
//        single pre-computed column
    ->setDatabaseDriver('pgsql')
```

**What it does:**
- Uses pre-computed `search_vector` column
- Ultra-fast with GIN index
- Uses `websearch_to_tsquery` for better query parsing

**Request:**
```bash
GET /api/products?filter[q]=macbook pro 13 inch
```

**PostgreSQL SQL:**
```sql
WHERE search_vector @@ websearch_to_tsquery('simple', 'macbook pro 13 inch')
--    ↑ indexed!      ↑ handles phrases intelligently
```

**Performance:**
```
Regular search:  ~500ms for 1M rows
search_vector:   ~5ms for 1M rows  (100x faster!)
```

### Database-Specific Behavior

| Database   | Strategy | Features |
|------------|----------|----------|
| PostgreSQL | Native full-text search | - `to_tsvector()`, `to_tsquery()`<br>- Language stemming<br>- Prefix matching with `:*`<br>- GIN indexes<br>- Ranking support |
| MySQL      | LIKE fallback | - `column LIKE '%term%'`<br>- OR across multiple columns<br>- No stemming<br>- Regular indexes |
| SQLite     | LIKE fallback | - `column LIKE '%term%'`<br>- OR across multiple columns<br>- No stemming<br>- Regular indexes |

### When to Use Each Approach

**Use Array of Columns:**
```php
Filter::fullText(['title', 'description', 'tags'], 'search')
```
✅ **Good for:**
- General text search across multiple fields
- When you don't have search_vector set up
- Works on all databases

**Use search_vector Column:**
```php
Filter::fullText('search_vector', 'q')->setDatabaseDriver('pgsql')
```
✅ **Good for:**
- High-performance requirements
- Large datasets (millions of rows)
- PostgreSQL only
- Worth the setup time

**Use Language Setting:**
```php
->setFullTextLanguage('portuguese')
```
✅ **Good for:**
- Non-English content
- Better search relevance
- PostgreSQL only

**Disable Prefix Matching:**
```php
->setFullTextPrefixMatch(false)
```
✅ **Good for:**
- When you want exact word matches
- Reduce false positives
- More precise results

## E-commerce Examples

### Filtrar Produtos com Múltiplos Critérios

```php
use DevactionLabs\FilterablePackage\Filter;

$products = Product::query()
    ->filtrable([
        // Busca por nome (começa com)
        Filter::like('name', 'search')
            ->setLikePattern('{{value}}%'),

        // Faixa de preço
        Filter::between('price', 'price_range'),

        // Categorias específicas
        Filter::in('category_id', 'categories'),

        // Marca
        Filter::relationship('brand', 'slug', '=', 'brand')
            ->with(),

        // Produtos com desconto OU em destaque
        Filter::relationship('tags', 'name')
            ->whereAny([
                ['name', '=', 'sale'],
                ['name', '=', 'featured'],
            ])
            ->with(),

        // Full-text search
        Filter::fullText(['name', 'description'], 'q'),
    ])
    ->customPaginate('paginate', 20);
```

**Requests:**
```bash
# Busca simples
GET /api/products?filter[search]=notebook

# Com faixa de preço
GET /api/products?filter[search]=notebook&filter[price_range]=1000,3000

# Múltiplas categorias
GET /api/products?filter[categories]=1,2,3&filter[brand]=apple

# Full-text search
GET /api/products?filter[q]=macbook pro retina&sort=-price
```

### Filtrar por Atributos JSON

```php
$products = Product::query()
    ->filtrable([
        // Especificações do produto (JSON)
        Filter::json('specs', 'color', '=', 'color')
            ->setDatabaseDriver('pgsql'),

        Filter::json('specs', 'weight', '>', 'min_weight')
            ->setDatabaseDriver('pgsql'),

        Filter::json('specs', 'dimensions.width', '=', 'width')
            ->setDatabaseDriver('pgsql'),
    ])
    ->get();
```

**Request:**
```bash
GET /api/products?filter[color]=black&filter[min_weight]=2.5&filter[width]=50
```

## Blog/CMS

### Listar Posts com Filtros Avançados

```php
$posts = Post::query()
    ->filtrable([
        // Busca em múltiplos campos
        Filter::fullText(['title', 'content', 'excerpt'], 'search')
            ->setFullTextLanguage('portuguese'),

        // Status
        Filter::exact('status', 'status'),

        // Faixa de datas
        Filter::between('published_at', 'date_range'),

        // Autor específico
        Filter::relationship('author', 'username', '=', 'author')
            ->with(),

        // Posts com TODAS as categorias
        Filter::relationship('categories', 'slug')
            ->whereAll([
                ['slug', '=', request('filter.category')],
                ['is_active', '=', true],
            ])
            ->with(),

        // Apenas publicados
        Filter::isNotNull('published_at', 'published'),
    ])
    ->customPaginate('cursor', 10);
```

**Requests:**
```bash
# Busca publicados
GET /api/posts?filter[search]=laravel&filter[published]=1

# Por autor e categoria
GET /api/posts?filter[author]=john&filter[category]=tutorials

# Faixa de datas
GET /api/posts?filter[date_range]=2024-01-01,2024-12-31&sort=-published_at
```

### Filtrar Comentários

```php
$comments = Comment::query()
    ->filtrable([
        // Comentários aprovados
        Filter::exact('status', 'status'),

        // Por post
        Filter::relationship('post', 'slug', '=', 'post')
            ->with(),

        // Por usuário
        Filter::relationship('user', 'id', '=', 'user_id')
            ->with(),

        // Comentários SEM spam
        Filter::relationship('reports', 'type')
            ->whereNone([
                ['type', '=', 'spam'],
            ]),

        // Comentários recentes
        Filter::gte('created_at', 'since'),
    ])
    ->customPaginate('simple', 50);
```

**Request:**
```bash
GET /api/comments?filter[status]=approved&filter[post]=my-first-post&filter[since]=2024-01-01
```

## Gestão de Usuários

### Filtrar Usuários por Permissões

```php
$users = User::query()
    ->filtrable([
        // Busca por nome ou email
        Filter::like('name', 'search'),
        Filter::like('email', 'email'),

        // Usuários ativos
        Filter::exact('is_active', 'active'),

        // Por papel (role)
        Filter::relationship('roles', 'name', '=', 'role')
            ->with(),

        // Usuários com TODAS as permissões necessárias
        Filter::relationship('permissions', 'name')
            ->whereAll([
                ['name', '=', 'edit-posts'],
                ['name', '=', 'publish-posts'],
                ['name', '=', 'delete-posts'],
            ])
            ->with(),

        // Verificados
        Filter::isNotNull('email_verified_at', 'verified'),

        // Excluir usuários específicos
        Filter::notIn('id', 'exclude'),
    ])
    ->customPaginate('paginate', 15);
```

**Request:**
```bash
GET /api/users?filter[search]=john&filter[active]=1&filter[role]=admin&filter[verified]=1
```

### Filtrar por Data de Registro

```php
$users = User::query()
    ->filtrable([
        // Data exata (com hora)
        Filter::exact('created_at', 'date')
            ->castDate()
            ->startOfDay(),

        // Ou faixa de datas
        Filter::between('created_at', 'date_range'),
    ])
    ->get();
```

**Requests:**
```bash
# Data exata
GET /api/users?filter[date]=2024-01-15

# Faixa
GET /api/users?filter[date_range]=2024-01-01,2024-12-31
```

## API REST

### Endpoint Genérico com Todos os Filtros

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
        // Validação dos parâmetros
        $validated = $request->validate([
            'filter.search' => 'nullable|string|max:255',
            'filter.price_range' => 'nullable|string',
            'filter.categories' => 'nullable|string',
            'filter.brand' => 'nullable|string',
            'filter.in_stock' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort' => 'nullable|string',
        ]);

        $products = Product::query()
            ->filtrable([
                Filter::like('name', 'search'),
                Filter::between('price', 'price_range'),
                Filter::in('category_id', 'categories'),
                Filter::relationship('brand', 'slug', '=', 'brand')->with(),
                Filter::exact('in_stock', 'in_stock'),
                Filter::fullText(['name', 'description'], 'q'),
            ])
            ->customPaginate(
                $request->input('pagination_type', 'paginate'),
                $request->input('per_page', 20),
                [
                    'per_page' => $request->input('per_page', 20),
                    'sort' => $request->input('sort', '-created_at'),
                ]
            );

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }
}
```

### Resposta JSON Padrão

```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "name": "MacBook Pro",
        "price": 2500.00,
        "brand": {
          "id": 1,
          "name": "Apple",
          "slug": "apple"
        }
      }
    ],
    "first_page_url": "http://api.example.com/products?page=1",
    "from": 1,
    "last_page": 10,
    "last_page_url": "http://api.example.com/products?page=10",
    "next_page_url": "http://api.example.com/products?page=2",
    "path": "http://api.example.com/products",
    "per_page": 20,
    "prev_page_url": null,
    "to": 20,
    "total": 200
  }
}
```

## Casos Avançados

### Filtro Condicional Baseado no Usuário

```php
$filters = [
    Filter::like('title', 'search'),
];

// Apenas admin pode ver posts não publicados
if (!auth()->user()->isAdmin()) {
    $filters[] = Filter::exact('status', 'status')->setValue('published');
}

// Filtrar por posts do próprio usuário
if ($request->has('filter.my_posts')) {
    $filters[] = Filter::relationship('author', 'id')
        ->whereAll([
            ['id', '=', auth()->id()],
        ])
        ->with();
}

$posts = Post::query()->filtrable($filters)->get();
```

### Combinar Full-Text Search com Filtros Específicos

```php
$products = Product::query()
    ->filtrable([
        // Full-text search em múltiplos campos
        Filter::fullText(['name', 'description', 'brand_name'], 'search')
            ->setFullTextLanguage('portuguese')
            ->setFullTextPrefixMatch(true),

        // Mas apenas em categorias específicas
        Filter::in('category_id', 'categories'),

        // E com preço mínimo
        Filter::gte('price', 'min_price'),

        // Ordenar por relevância (quando suportado)
        // Ou por data
    ])
    ->customPaginate('paginate', 20);
```

**Request:**
```bash
GET /api/products?filter[search]=smartphone android&filter[categories]=1,2&filter[min_price]=500&sort=-created_at
```

### Usar search_vector Pre-computado (PostgreSQL)

```php
// Migration para criar a coluna
Schema::table('products', function (Blueprint $table) {
    $table->tsvector('search_vector')->nullable();
});

// Trigger para atualizar automaticamente
DB::statement("
    CREATE TRIGGER products_search_vector_update
    BEFORE INSERT OR UPDATE ON products
    FOR EACH ROW EXECUTE FUNCTION
    tsvector_update_trigger(
        search_vector, 'pg_catalog.portuguese',
        name, description, sku
    );
");

// Usar no filtro
$products = Product::query()
    ->filtrable([
        Filter::fullText('search_vector', 'search')
            ->setDatabaseDriver('pgsql'),
    ])
    ->get();
```

### Filtros com Cache

```php
use Illuminate\Support\Facades\Cache;

$cacheKey = 'products:' . md5(json_encode($request->query('filter', [])));

$products = Cache::remember($cacheKey, 300, function () use ($request) {
    return Product::query()
        ->filtrable([
            Filter::like('name', 'search'),
            Filter::between('price', 'price_range'),
        ])
        ->customPaginate('simple', 20);
});
```

## Dicas de Performance

### Use Cursor Pagination para Grandes Datasets

```php
// ✅ Melhor performance
->customPaginate('cursor', 20)

// ❌ Mais lento em grandes tabelas
->customPaginate('paginate', 20)
```

### Eager Load Apenas o Necessário

```php
// ✅ Carrega apenas relationships usadas
Filter::relationship('category', 'slug')->with()

// ❌ Sem ->with() causa N+1
Filter::relationship('category', 'slug') // Missing ->with()!
```

### Use Índices nas Colunas Filtradas

```sql
-- Para filtros frequentes
CREATE INDEX idx_products_price ON products(price);
CREATE INDEX idx_products_category_id ON products(category_id);
CREATE INDEX idx_products_created_at ON products(created_at);

-- Para full-text (PostgreSQL)
CREATE INDEX idx_products_search_vector ON products USING GIN(search_vector);
```

### Defina allowedSorts no Model

```php
protected array $allowedSorts = ['name', 'price', 'created_at'];
```

Isso previne SQL injection e melhora performance ao evitar sorts em colunas não indexadas.

## Referências

- [README.md](README.md) - Documentação completa
- [CHANGELOG.md](CHANGELOG.md) - Histórico de mudanças
