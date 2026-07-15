<?php

declare(strict_types=1);

namespace DevactionLabs\FilterablePackage\MCP\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Shared model-introspection helpers for the MCP tools.
 *
 * Relationship detection deliberately mirrors Laravel's own ModelInspector: a
 * method is only invoked once static analysis (declared return type or a
 * relation-factory call in its body) indicates it is a relationship. This
 * prevents the MCP server from executing arbitrary domain methods — e.g. a
 * model's notify()/recalculate() — merely because a client asked for a schema.
 */
trait InspectsModel
{
    /** @var array<int, string> */
    private array $relationFactories = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
        'hasManyThrough', 'hasOneThrough',
        'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany',
    ];

    private function resolveClass(string $model): ?string
    {
        if ($model === '') {
            return null;
        }

        if (class_exists($model)) {
            return $model;
        }

        foreach (['App\Models\\'.$model, 'App\\'.$model] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function getColumns(string $table): array
    {
        try {
            $types = [];
            foreach (Schema::getColumnListing($table) as $column) {
                $types[$column] = Schema::getColumnType($table, $column);
            }

            return $types;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Detect Eloquent relationships without running unrelated model logic.
     *
     * @return array<string, string> Relation name => relation type (e.g. BelongsTo)
     */
    private function getRelationships(string $class, Model $instance): array
    {
        $relationships = [];
        /** @var ReflectionClass<Model> $reflector */
        $reflector = new ReflectionClass($instance);

        foreach ($reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            if (! $this->looksLikeRelation($method)) {
                continue;
            }

            try {
                $result = $method->invoke($instance);
                if ($result instanceof Relation) {
                    $relationships[$method->getName()] = class_basename($result);
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $relationships;
    }

    /**
     * Decide, via static signals only, whether a method is a relationship
     * before it is ever invoked.
     */
    private function looksLikeRelation(ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType
            && ! $returnType->isBuiltin()
            && is_a($returnType->getName(), Relation::class, true)) {
            return true;
        }

        return $this->bodyCallsRelationFactory($method);
    }

    private function bodyCallsRelationFactory(ReflectionMethod $method): bool
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return false;
        }

        $source = file($file);
        if ($source === false) {
            return false;
        }

        $body = implode('', array_slice($source, $start - 1, $end - $start + 1));

        foreach ($this->relationFactories as $factory) {
            if (str_contains($body, '->'.$factory.'(')) {
                return true;
            }
        }

        return false;
    }
}
