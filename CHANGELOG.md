# Changelog

All notable changes to `filterable-package` will be documented in this file.

## 1.1.3 - 2025-09-30

### Added
- **Cursor Pagination Support**: Added support for cursor-based pagination for improved performance on large datasets
  - `customPaginate()` now accepts three pagination types: `'paginate'`, `'simple'`, and `'cursor'`
  - Cursor pagination ideal for infinite scroll implementations
  - Simple pagination option for better performance when total count is not needed

### Changed
- **BREAKING**: `customPaginate()` method signature updated from `customPaginate(bool $useSimplePaginate, ?array $data)` to `customPaginate(string $type = 'paginate', ?int $perPage = null, ?array $data = null)`
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
