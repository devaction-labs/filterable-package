<?php

namespace DevactionLabs\FilterablePackage\MCP\Tools;

use DevactionLabs\FilterablePackage\MCP\Contracts\Tool;
use DevactionLabs\FilterablePackage\Traits\Filterable;
use FilesystemIterator;
use Illuminate\Support\Facades\App;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use stdClass;
use Throwable;

class ListFilterableModelsTool implements Tool
{
    public function name(): string
    {
        return 'list_filterable_models';
    }

    public function description(): string
    {
        return 'Scans the Laravel project and returns all Eloquent models that use the Filterable trait. Use this to discover which models support filtering before calling other tools.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new stdClass,
            'required' => [],
        ];
    }

    public function execute(array $args): string
    {
        $modelsPath = App::basePath('app/Models');

        if (! is_dir($modelsPath)) {
            return 'No app/Models directory found in this project.';
        }

        $found = $this->scanDirectory($modelsPath);

        if ($found === []) {
            return sprintf('No models using the Filterable trait were found in %s.', $modelsPath);
        }

        $lines = ['Models using the Filterable trait:', ''];
        foreach ($found as $class => $file) {
            $lines[] = '  - '.$class;
            $lines[] = '    File: '.$file;
        }

        $lines[] = '';
        $lines[] = 'Use get_model_schema(model) to inspect columns and relationships.';
        $lines[] = 'Use generate_filters(model) to get a ready-to-use filter array.';

        return implode("\n", $lines);
    }

    /** @return array<string, string> */
    private function scanDirectory(string $path): array
    {
        $found = [];
        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! ($file instanceof SplFileInfo)) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            $class = $this->resolveClassName($realPath);
            if ($class === null) {
                continue;
            }

            if ($this->usesFilterableTrait($class)) {
                $found[$class] = str_replace(App::basePath().'/', '', $realPath);
            }
        }

        return $found;
    }

    private function resolveClassName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^class\s+(\w+)/m', $content, $classMatch)) {
            return null;
        }

        return trim($nsMatch[1]).'\\'.trim($classMatch[1]);
    }

    private function usesFilterableTrait(string $class): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }

            $reflection = new ReflectionClass($class);
            $traits = $this->getAllTraits($reflection);

            return in_array(Filterable::class, $traits, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<int, string>
     */
    private function getAllTraits(ReflectionClass $class): array
    {
        $traits = array_keys($class->getTraits());

        if ($class->getParentClass() !== false) {
            return array_merge($traits, $this->getAllTraits($class->getParentClass()));
        }

        return $traits;
    }
}
