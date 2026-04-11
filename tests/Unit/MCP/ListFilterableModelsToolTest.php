<?php

declare(strict_types=1);

namespace Tests\Unit\MCP;

use DevactionLabs\FilterablePackage\MCP\Tools\ListFilterableModelsTool;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use Illuminate\Support\Facades\App;
use ReflectionClass;

function callResolveClassName(ListFilterableModelsTool $tool, string $filePath): ?string
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('resolveClassName');

    return $method->invoke($tool, $filePath);
}

function callUsesFilterableTrait(ListFilterableModelsTool $tool, string $class): bool
{
    $ref = new ReflectionClass($tool);
    $method = $ref->getMethod('usesFilterableTrait');

    return $method->invoke($tool, $class);
}

it('has correct name', function (): void {
    expect((new ListFilterableModelsTool)->name())->toBe('list_filterable_models');
});

it('has non-empty description', function (): void {
    expect((new ListFilterableModelsTool)->description())->not->toBeEmpty();
});

it('has valid input schema with no required properties', function (): void {
    $schema = (new ListFilterableModelsTool)->inputSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe([]);
});

it('returns no-directory message when app/Models does not exist', function (): void {
    App::clearResolvedInstances();
    App::shouldReceive('basePath')->with('app/Models')->andReturn('/nonexistent/path/app/models');

    $result = (new ListFilterableModelsTool)->execute([]);

    expect($result)->toContain('No app/Models directory found');
});

it('returns no-models message when directory has no filterable classes', function (): void {
    // Create a temp directory with a PHP file that defines a class
    $dir = sys_get_temp_dir().'/filterable_test_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/Ghost.php', "<?php\nnamespace App\\Models;\nclass Ghost {}\n");

    App::clearResolvedInstances();
    App::shouldReceive('basePath')->with('app/Models')->andReturn($dir);
    // basePath() without args is called when building the relative path display
    App::shouldReceive('basePath')->withNoArgs()->andReturn($dir);

    $result = (new ListFilterableModelsTool)->execute([]);

    // Cleanup
    unlink($dir.'/Ghost.php');
    rmdir($dir);

    expect($result)->toContain('No models using the Filterable trait were found');
});

// resolveClassName — pure PHP, no facade needed
it('resolveClassName returns null when file has no namespace', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($file, "<?php\nclass Foo {}\n");

    expect(callResolveClassName(new ListFilterableModelsTool, $file))->toBeNull();

    unlink($file);
});

it('resolveClassName returns null when file has no class declaration', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($file, "<?php\nnamespace App\\Models;\n");

    expect(callResolveClassName(new ListFilterableModelsTool, $file))->toBeNull();

    unlink($file);
});

it('resolveClassName returns FQCN when namespace and class are present', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($file, "<?php\nnamespace App\\Models;\nclass User {}\n");

    expect(callResolveClassName(new ListFilterableModelsTool, $file))->toBe('App\\Models\\User');

    unlink($file);
});

// usesFilterableTrait — pure PHP reflection
it('usesFilterableTrait returns false for non-existent class', function (): void {
    expect(callUsesFilterableTrait(new ListFilterableModelsTool, 'NonExistentClass999'))->toBeFalse();
});

it('usesFilterableTrait returns false for class without filterable trait', function (): void {
    expect(callUsesFilterableTrait(new ListFilterableModelsTool, ListFilterableModelsTool::class))->toBeFalse();
});

it('usesFilterableTrait returns true for class using Filterable trait', function (): void {
    $class = new class
    {
        use Filterable;
    };

    expect(callUsesFilterableTrait(new ListFilterableModelsTool, $class::class))->toBeTrue();
});
