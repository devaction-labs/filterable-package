<?php

namespace DevactionLabs\FilterablePackage;

use DevactionLabs\FilterablePackage\Console\Commands\McpServeCommand;
use Illuminate\Support\ServiceProvider;

class FilterableServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([McpServeCommand::class]);

            $this->publishes([
                __DIR__.'/../stubs/mcp.json' => base_path('.mcp.json'),
            ], 'filterable-mcp');
        }
    }
}
