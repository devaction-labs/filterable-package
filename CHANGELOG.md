# Changelog

All notable changes to `filterable-package` will be documented in this file.

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
- Improved README documentation with comprehensive examples
- Optimization for relationship loading to avoid duplications

### Fixed
- Better validation for array values in filters
