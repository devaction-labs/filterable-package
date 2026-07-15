# RFC 0001 — Cross-table OR groups, security hardening, MCP fixes, and Laravel 13 adoption

- **Status:** Accepted
- **Author:** DevAction Labs
- **Created:** 2026-07-15
- **Target release:** 2.3.0
- **Verified against:** Laravel (illuminate/*) 13.4.0, PHP 8.5.8

## 1. Summary

This RFC bundles four changes that came out of an audit of the package against Laravel 13:

1. **`Filter::anyOf()`** — a first-class OR group that can combine direct-column filters *and* relationship filters into a single grouped `WHERE ( ... OR ... )`. This closes a real gap (a single search box that matches either an own-table column or a related-model column) and removes a **correctness trap** in the manual workaround people write today.
2. **Security hardening of pagination/sort** — fix an uncaught `TypeError` (HTTP 500) from array `sort` input, clamp `per_page`, and validate the `sort` identifier when no allow-list is configured.
3. **MCP server fixes** — stop the MCP tools from invoking arbitrary model methods (a real side-effect / RCE-adjacent hazard), and make the JSON-RPC transport spec-compliant and robust.
4. **Laravel 13 adoption** — replace the hand-rolled `ILIKE` raw SQL with the native, driver-aware `whereLike(caseSensitive: false)`, and declare the `illuminate/pagination` dependency that `customPaginate` actually needs.

## 2. Motivation and evidence

Every claim below was reproduced by running the package against a real SQLite/Eloquent database and by driving the MCP server over real JSON-RPC.

### 2.1 The cross-table OR gap (and a data-leak bug)

The package combines every `Filter::` with `AND`. Relationship filters become a separate `whereHas`, also `AND`-ed. There is no way to express *"this direct-column filter **OR** this relationship filter"*, so people hand-write it in the controller:

```php
->where('status', 'open')
->where('receipt_number', 'like', $term)
->orWhere('invoice_number', 'like', $term)
->orWhereHas('items.product', fn ($r) => $r->where('sku', 'like', $term));
```

This is **wrong**. The `orWhere` chain is not grouped, so it escapes the `AND status = 'open'` constraint. Reproduced: the query above returned a `status = 'closed'` row. Whenever the leading constraint is a tenant/ownership scope, this leaks rows across the boundary. The correct SQL wraps the alternatives in a group:

```sql
where "status" = ? and ("receipt_number" like ? or "invoice_number" like ? or exists (...))
```

### 2.2 Pagination/sort robustness

- **CRITICAL — SQL injection through `sort` on PostgreSQL.** When `allowedSorts()` is empty (the default), the raw `sort` value reaches `orderBy()` unchecked. A key containing `->` triggers the JSON-selector grammar, and PostgreSQL's `wrapJsonPathAttributes` interpolates the path segment inside single quotes **without escaping embedded quotes**. Reproduced through `scopeCustomPaginate`: `?sort=-email->x'||(CASE WHEN (SELECT 1)=1 THEN chr(65) ELSE chr(66) END)||'` compiles to valid, executable
  `order by "email"->>'x'||(CASE WHEN (SELECT 1)=1 THEN chr(65) ELSE chr(66) END)||'' desc` — a blind boolean injection usable to exfiltrate arbitrary data. MySQL and SQLite double the quotes, so they are not injectable this way (but see enumeration below).
- **Column enumeration (all drivers).** Even without injection, an empty allow-list lets `?sort=-password` order by a hidden column, turning pagination into an ordering oracle to binary-search secret values.
- `?sort[]=x` → `TypeError: Cannot access offset of type array` at `Filterable.php:65` → uncaught HTTP 500.
- `per_page` is unbounded/degenerate: `?per_page=999999999` compiles a huge `LIMIT` (memory DoS); `?per_page=0` → `DivisionByZeroError` on the default paginator; `?per_page=-5` → `QueryException` (Laravel drops the negative `LIMIT`).
- **PostgreSQL full-text 500 (Finding 5).** An invalid-UTF-8 search term makes `preg_replace('/.../u', ...)` return `null`; with `prefixMatch=false` the `: string` closure returns `null` → `TypeError`; with the default `prefixMatch=true` it builds a malformed `to_tsquery` → `QueryException`.
- **ILIKE + JSON on MySQL 500 (Finding 6).** In L13 `Expression::getValue()` requires a `Grammar` argument; the legacy MySQL ILIKE path calls it with none → `ArgumentCountError`.

### 2.3 MCP invokes arbitrary model methods

`GetModelSchemaTool` and `GenerateFiltersTool` detect relationships by reflecting over the model and **invoking every public zero-argument method** to see if it returns a `Relation`. Reproduced: asking the MCP for a schema executed a model's `recalculateTotals()` and `notifyWarehouse()` methods (the latter "sent an email"). Merely inspecting a model must never run domain logic.

Other MCP defects reproduced over JSON-RPC:

- Any JSON-RPC **notification** (a message with no `id`) whose method is unknown gets an error response with `"id": null`. Notifications must never receive a response; strict clients disconnect.
- `initialize` hard-codes `protocolVersion: "2024-11-05"` and ignores the client's requested version.
- A single PHP warning on STDOUT (e.g. `display_errors=1`) corrupts the framed JSON-RPC stream and the client can no longer parse it.

### 2.4 Laravel 13 leaves the hand-rolled ILIKE obsolete

Laravel 11.17+ ships `whereLike($column, $value, caseSensitive)`, which compiles to `ilike` on PostgreSQL, collation `like`/`like binary` on MySQL, and `like`/`glob` on SQLite. The package instead emits `whereRaw("LOWER(\`col\`) LIKE LOWER(?)")` on MySQL, which cannot use an index. Native `whereLike` is index-friendly and driver-correct.

## 3. Design

### 3.1 `Filter::anyOf()`

A new enum case `FilterOperator::OR_GROUP` marks a *group* filter that carries child `Filter` instances instead of a value.

```php
Filter::anyOf(array $filters, ?string $filterBy = null): self
```

- Each child is a normal `Filter` (direct or relationship).
- If `$filterBy` is provided, every child is re-pointed to that request key and re-reads its value — this is the single-search-box case: `?filter[search]=ABC` feeds all children.
- If `$filterBy` is `null`, each child keeps its own request key — an OR across independent inputs.

The group is applied as one unit on the main builder:

```php
$builder->where(function ($query) use ($children) {
    foreach ($children as $child) {
        if ($child->shouldIgnore()) continue;
        $query->orWhere(function ($branch) use ($child) {
            // direct child      -> $branch->where(...)/whereBetween(...)/whereLike(...)
            // relationship child -> $branch->whereHas($relation, ...)
        });
    }
});
```

Each child sits inside its own `orWhere(Closure)` so a child that expands to multiple conditions (e.g. `between`) stays an internal `AND` within its OR branch. The whole group is skipped (`shouldIgnore()`) when *all* children are empty, so an empty search box adds no SQL.

Usage:

```php
Order::filterable([
    Filter::exact('status'),
    Filter::anyOf([
        Filter::like('receipt_number'),
        Filter::like('invoice_number'),
        Filter::relationship('items.product', 'sku', 'ILIKE'),
    ], filterBy: 'search'),
]);
// ?filter[status]=open&filter[search]=ABC
// => where status = ? and ( receipt_number like ? or invoice_number like ? or exists(...sku...) )
```

**Performance note (documented, not enforced):** the relationship branch compiles to an indexed correlated `EXISTS`. The dominant cost is any leading-wildcard `LIKE '%term%'`, which forces a full scan regardless of grouping. Guidance: prefer `startsWith`/`exact`/`in` where possible, or a full-text/trigram index for true "contains" search at scale.

### 3.2 Pagination/sort hardening (`scopeCustomPaginate`)

- **Reject non-string `sort`** with a clean `InvalidArgumentException` (consistent with the existing invalid-sort exception) instead of a `TypeError`. Closes the `?sort[]` crash.
- **Always validate the request sort key** against `^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$` before it reaches `orderBy()`. A legitimate sort key is always a plain column identifier; this rejects `->`, quotes, and other grammar-significant characters, which **closes the PostgreSQL injection regardless of allow-list state** (defense-in-depth). `filterMap`-resolved column names are developer-defined and left as-is.
- **Clamp `per_page`** to `[1, maxPerPage]`. `maxPerPage` defaults to `100`, overridable per model (`protected int $maxPerPage`) and globally via `config('filterable.max_per_page')`. Closes the `per_page=0/-5/huge` DoS trio.
- **Enumeration:** the allow-list check is unchanged (an unlisted key throws when `allowedSorts` is non-empty). Optional **strict mode** `config('filterable.strict_sorts')` (default `false`, backward compatible): when `true`, an empty `allowedSorts` rejects *any* request-supplied sort, closing the enumeration oracle for callers who opt in.

`maxPerPage = 100` is a behavior change for callers requesting more; it is documented in the CHANGELOG and is trivially raised via config.

### 3.2b Full-text hardening

- `applyPostgreSQLFullTextSearch`: sanitize the term to valid UTF-8 up front, coalesce `preg_replace` `null` results to `''`, and drop empty lexemes *after* the cast so neither `prefixMatch` mode can emit `null` or a bare `:*`. Closes Finding 5.

### 3.2c Legacy ILIKE + JSON fix

- The pre-11.17 fallback path passes the grammar to `Expression::getValue($grammar)`. Closes Finding 6. (The native `whereLike` path in §3.4 avoids the call entirely.)

### 3.3 MCP fixes

- **Safe relationship detection** (shared helper used by both schema tools): only consider a method a relationship if its declared return type is a `Relation` subclass, *or* its source body calls a known relation factory (`belongsTo`, `hasMany`, …). Only then is it invoked. This mirrors `Illuminate\Database\Eloquent\ModelInspector` and prevents running unrelated domain methods.
- **JSON-RPC notifications:** requests without an `id` never receive a response (success or error).
- **Protocol negotiation:** `initialize` echoes the client's requested `protocolVersion` when recognized, else falls back to the server default.
- **Transport hardening:** the serve command routes PHP errors to STDERR (`display_errors=stderr`) so STDOUT carries only framed JSON-RPC.

### 3.4 Native `whereLike`

`applyIlikeFilter` uses `whereLike($attribute, $value, caseSensitive: false)` when the query builder supports it (detected on `Illuminate\Database\Query\Builder`, since Eloquent forwards it via `__call`). Pre-11.17 keeps the existing driver-specific fallback. MySQL case-insensitivity now follows the column collation (default CI) instead of `LOWER()`, gaining index usage; documented in the CHANGELOG.

## 4. Backward compatibility

| Change | Compatible? | Notes |
|---|---|---|
| `Filter::anyOf()` | ✅ additive | New API; nothing else changes. |
| `sort[]` / bad sort key throws `InvalidArgumentException` | ✅ | Previously an uncaught `TypeError` or a SQL injection; now a catchable, documented exception. |
| `per_page` clamp to `[1,100]` | ⚠️ behavior | Configurable; raise via `config('filterable.max_per_page')`. |
| `strict_sorts` | ✅ opt-in | Default `false` preserves current behavior. |
| Native `whereLike` for ILIKE | ⚠️ subtle | MySQL relies on collation for CI (default unchanged); gains index usage. |
| MCP safe relation detection | ✅ | Same output for real relationships; stops running unrelated methods. |
| `illuminate/pagination` dependency | ✅ | Declares an already-required transitive dependency. |

## 5. Testing

- Real SQLite/Eloquent integration tests (not just Mockery) for: `anyOf` grouped SQL + correct row set, the tenant-leak regression, `sort[]` rejection, `per_page` clamping, and native `whereLike` per driver.
- MCP tests: a model with a side-effecting method is **not** invoked; notifications get no response; `protocolVersion` echo.
- Full gate: `pint`, `phpstan` (level 9), `rector --dry-run`, `pest`.

## 6. Out of scope

- Migrating to the first-party `laravel/mcp` package (still 0.x/beta) — tracked separately.
- `whereVectorSimilarTo` (pgvector) as a `Filter::vector()` — a follow-up once a concrete use case exists.
