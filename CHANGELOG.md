# Changelog

All notable changes to `filterable-package` will be documented in this file.

## 2.3.0 - 2026-07-15

See [RFC 0001](docs/rfc/0001-or-groups-security-mcp-laravel13.md) for the full design and reproduction of each issue.

### Added
- **`Filter::anyOf()`** — a first-class OR group that combines direct-column and relationship filters into a single grouped `WHERE ( ... OR ... )`. Enables a single search box that matches an own-table column **or** a related-model column, e.g. `Filter::anyOf([Filter::like('receipt_number'), Filter::relationship('items.product', 'sku', 'ILIKE')], 'search')`. Previously impossible without hand-writing the query (which was also prone to an AND/OR grouping bug that leaked rows past other filters).
- **`config('filterable.max_per_page')`** (default `100`) and a per-model `protected int $maxPerPage` to cap request-supplied page sizes.
- **`config('filterable.strict_sorts')`** (default `false`) — when `true`, a request `sort` is rejected unless an explicit `allowedSorts()` list is configured.

### Security
- **SQL injection via `sort` on PostgreSQL (critical)**: a `sort` key containing `->` triggered PostgreSQL's JSON-path grammar, which interpolated the segment inside single quotes without escaping them, allowing a blind boolean injection (`?sort=-email->x'||(...)||'`). Request sort keys are now validated against a strict column-identifier pattern before reaching `orderBy()`, closing the injection on every driver.
- **`per_page` denial-of-service**: `?per_page=0` (division-by-zero 500), `?per_page=-5` (query error), and `?per_page=999999999` (memory exhaustion) are fixed by clamping request page sizes to `[1, max_per_page]`.
- **Type-confusion 500**: `?sort[]=x` now throws a catchable `InvalidArgumentException` instead of an uncaught `TypeError`.
- **PostgreSQL full-text 500**: an invalid-UTF-8 search term no longer produces a `TypeError` or a malformed `to_tsquery` (bare `:*`); degenerate lexemes are dropped safely.
- **Column enumeration**: opt-in `strict_sorts` closes the sort-oracle for callers who do not configure an allow-list. The default remains permissive for backward compatibility (configuring `allowedSorts()` is strongly recommended).
- **MCP arbitrary method execution**: the MCP schema/filter-generation tools invoked *every* zero-argument public model method to detect relationships, which could run domain logic (e.g. a model's `notifyWarehouse()`). Detection now mirrors Laravel's `ModelInspector`: a method is only invoked once its return type or source body indicates it is a relationship.

### Fixed
- **`ILIKE` + JSON path on MySQL** raised `ArgumentCountError` because Laravel 13's `Expression::getValue()` requires a grammar argument; the grammar is now passed.
- **Missing `illuminate/pagination` dependency**: `customPaginate()` could fatal with "Paginator not found"; the dependency is now declared.
- **MCP JSON-RPC transport**: notifications (messages without an `id`) no longer receive a response (spec violation that broke strict clients); `initialize` now negotiates the client's requested `protocolVersion`; and the serve command routes PHP error output to STDERR so a stray warning cannot corrupt the framed message stream.

### Changed
- **`ILIKE` uses the native `whereLike($column, $value, caseSensitive: false)`** on Laravel 11.17+ (driver-aware: `ilike` on PostgreSQL, collation `like` on MySQL, `like`/`glob` on SQLite). This is index-friendly, unlike the previous `LOWER(col) LIKE LOWER(?)` on MySQL. Older Laravel versions keep the previous per-driver behavior. Note: on MySQL, case-insensitivity now follows the column collation (case-insensitive by default) rather than being forced with `LOWER()`.
- The MCP `initialize` default `protocolVersion` is now `2025-06-18` (supported: `2024-11-05`, `2025-03-26`, `2025-06-18`).

### Upgrading from 2.2.x
- Request `per_page` values above `100` are now capped. Raise the limit with `config('filterable.max_per_page')` or a per-model `$maxPerPage` if you need larger pages.
- If you relied on MySQL `ILIKE` being case-insensitive under a case-sensitive/binary collation, note it now follows the collation. Default MySQL collations are case-insensitive and unaffected.
- No changes required for `Filter::anyOf()` — it is purely additive.

## 2.1.0 - 2026-04-10

### Added
- **Laravel 13 support**: `illuminate/*` constraints expanded to `^11|^12|^13`

### Security
- **JSON path injection fix**: `setJsonPath()` now validates the path with a strict regex — only alphanumeric characters, dots, underscores, and brackets are accepted. Invalid paths throw `InvalidArgumentException`.
- **Full-text column injection fix**: Column names passed to `Filter::fullText()` are validated before interpolation into PostgreSQL `whereRaw()` queries.
- **Full-text language injection fix**: The language parameter in PostgreSQL full-text search is validated to only allow alphanumeric characters and underscores.
- **Operator injection fix**: `Filter::generic()` now validates the operator via `FilterOperator::from()`, rejecting any value not defined in the enum.

### Performance
- Removed static `$cachedDatabaseDriver` property — the static cache caused test interference in multi-database scenarios and could return stale results in multi-tenant applications. The driver is now cached per-instance.
- Removed unbounded `$validationCache` in `Filterable` trait — the MD5+`json_encode` overhead for caching trivial `in_array()` results was replaced with direct inline checks.
- Removed redundant `setValueFromRequest()` call in `Filter::json()` factory — the constructor already calls it; JSON extraction happens lazily in `getValue()`.

### Changed
- Dependency updates: `phpunit` → 12.5.16, `phpstan` → 2.1.46, `pest` → 4.5.0, `rector` → 2.4.1, `symfony/*` → 7.4.8, `nesbot/carbon` → 3.11.4.

### Upgrading from 2.0.x
- No breaking changes for existing users on Laravel 11/12.
- `Filter::generic()` now throws `InvalidArgumentException` for operators not present in `FilterOperator`. If you were passing raw SQL operators, migrate to the corresponding enum case.
- `Filter::json()` with an empty or unsafe path now throws `InvalidArgumentException` instead of silently ignoring it.

## 1.1.4 - 2025-09-30

### Changed
- **Parameter Rename**: Renamed `$type` parameter to `$paginationType` in `customPaginate()` method for better IDE autocomplete clarity
- Removed unnecessary debug logging for empty filter values (filters with null/empty values are expected behavior and should not pollute logs)

### Fixed
- Debug logs no longer spam when filters are not provided in requests (empty filters are silently ignored as intended)

## 1.1.3 - 2025-09-30

### Added
- **Cursor Pagination Support**: Added support for cursor-based pagination for improved performance on large datasets
  - `customPaginate()` now accepts three pagination types: `'paginate'`, `'simple'`, and `'cursor'`
  - Cursor pagination ideal for infinite scroll implementations
  - Simple pagination option for better performance when total count is not needed

### Changed
- **BREAKING**: `customPaginate()` method signature updated from `customPaginate(bool $useSimplePaginate, ?array $data)` to `customPaginate(string $paginationType = 'paginate', ?int $perPage = null, ?array $data = null)`
  - Migration: Change `->customPaginate(false, $data)` to `->customPaginate('paginate', null, $data)`
  - Migration: Change `->customPaginate(true, $data)` to `->customPaginate('simple', null, $data)`
- Code refactoring: Removed all `else` statements in favor of early returns and guard clauses for improved readability
- Improved README documentation with comprehensive pagination examples

### Fixed
- Optimized `collectConditions()` method by removing unnecessary size estimation loop

## 1.1.0 - 2025-09-14

### Added
- **New Filter Types**: Added 7 new commonly used filter types
  - `ilike()` - Case-insensitive LIKE with database-specific handling (PostgreSQL ILIKE, SQLite LIKE, MySQL LOWER())
  - `notEquals()` - NOT EQUALS (!=) filter for excluding specific values
  - `notIn()` - NOT IN filter for excluding multiple values from a list
  - `notLike()` - NOT LIKE filter for excluding text patterns
  - `isNull()` - IS NULL filter for checking null values
  - `isNotNull()` - IS NOT NULL filter for checking non-null values
  - `startsWith()` - STARTS WITH filter using LIKE with % suffix
  - `endsWith()` - ENDS WITH filter using LIKE with % prefix

### Enhanced
- **Database Compatibility**: Improved database-specific handling for different SQL dialects
  - PostgreSQL: Uses native ILIKE for case-insensitive searches
  - SQLite: Falls back to LIKE for case-insensitive searches  
  - MySQL: Uses LOWER() function for case-insensitive comparisons
- **Filter Value Processing**: Enhanced `prepareValue()` method to handle new filter types
  - Automatic pattern generation for STARTS_WITH and ENDS_WITH
  - Support for comma-separated values in NOT_IN filters
  - Pattern application for NOT_LIKE filters

### Testing
- **Comprehensive Test Coverage**: Added complete test suite for all new filter types
  - Unit tests for each new filter type
  - Database driver detection tests
  - Operator validation tests
  - Integration tests with Filterable trait

### Performance
- **Optimized Query Building**: Enhanced `applyFilterToBuilder()` method
  - Direct method calls for null checks (whereNull/whereNotNull)
  - Efficient handling of negation filters (whereNotIn)
  - Streamlined LIKE pattern processing

## 1.0.22 - 2025-04-04

### Added
- Initial release with core functionality
- Filterable trait implementation
- Support for various filter types (exact, like, in, between, gt, gte, lt, lte)
- JSON filtering support
- Relationship filtering
- Dynamic sorting capabilities
- Pagination integration
- Performance optimizations including validation caching

### Changed
- Implementamos várias otimizações de performance importantes no trait Filterable, incluindo:

    1. Caching de validações repetidas
       - Armazenamos em cache resultados de verificações como isValidRelationship e hasFilterConditionalLogic
       - Usamos cache para atributos resolvidos evitando recálculos
    2. Otimização de estruturas de dados
       - Substituímos array_unique por array associativo como "Set" para manter relacionamentos únicos
       - Pré-alocamos arrays para condições com estimativa de tamanho
    3. Otimização de SQL
       - Implementamos casos especiais para filtros simples em relacionamentos
       - Reduzimos consultas aninhadas desnecessárias
    4. Refatorações inteligentes
       - Substituímos encadeamentos de if/elseif por switch e match expressions
       - Extraímos verificações comuns para métodos utilitários
    5. Testes de performance
       - Criamos testes específicos que verificam o comportamento otimizado
       - Verificamos tempo de execução para conjuntos grandes de filtros

### Fixed
- N/A

## [Unreleased]

### Added
- Support for advanced date filtering with Carbon
- Enhanced performance through attribute caching
- Conditional relationship filtering with whereAny, whereAll, and whereNone
- Custom LIKE patterns for more flexible text searching
- Database-specific JSON field handling optimizations

### Changed
- Optimization for relationship loading to avoid duplications

### Fixed
- Better validation for array values in filters
