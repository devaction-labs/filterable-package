<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class for tests that exercise the package against a real in-memory
 * SQLite database (as opposed to the Mockery-based unit tests). It boots a
 * minimal container with the config/request/db bindings the trait and Filter
 * facades need, so generated SQL and row results can be asserted for real.
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    protected Container $app;

    protected Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('DATABASE_DRIVER=sqlite');

        $this->app = new Container;
        Container::setInstance($this->app);
        $this->app->instance('config', new ArrayConfig(['database' => ['default' => 'sqlite'], 'filterable' => []]));

        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();

        $this->capsule = new Capsule($this->app);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlite');
        $this->capsule->getDatabaseManager()->setDefaultConnection('sqlite');
        $this->config()->set('database.default', 'sqlite');
        $this->capsule->setEventDispatcher(new Dispatcher($this->app));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->app->instance('db', $this->capsule->getDatabaseManager());

        $this->setRequestQuery([]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance();
        putenv('DATABASE_DRIVER');
        Mockery::close();

        parent::tearDown();
    }

    protected function config(): ArrayConfig
    {
        /** @var ArrayConfig $config */
        $config = $this->app->make('config');

        return $config;
    }

    /**
     * Rebind the request so filters read the given query parameters. Must be
     * called before constructing filters (they read the request on creation).
     *
     * @param  array<string, mixed>  $filters  The filter[...] query bag
     * @param  array<string, mixed>  $extra  Other query params (per_page, sort, ...)
     */
    protected function setRequestQuery(array $filters, array $extra = []): void
    {
        $query = array_merge(['filter' => $filters], $extra);
        $this->app->instance('request', Request::create('/?'.http_build_query($query)));
        Facade::clearResolvedInstance('request');
    }
}
