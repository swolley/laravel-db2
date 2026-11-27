<?php

namespace Tests\Unit;

use Easi\DB2\DB2ServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Tests\Unit\Helpers\FakeConfig;

function providerTestContainer(): Container
{
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new FakeConfig([
        'database' => ['connections' => ['db2' => ['driver' => 'db2_ibmi_odbc', 'database' => 'TESTDB', 'prefix' => '']]],
    ]));
    bindDatabaseManager($app);

    return $app;
}

/**
 * Binds the `db` singleton the way DatabaseServiceProvider::register() does.
 */
function bindDatabaseManager(Container $app): void
{
    $app->singleton('db', fn (Container $app): DatabaseManager => new DatabaseManager($app, new ConnectionFactory($app)));
}

/**
 * @return array<string, mixed>
 */
function registeredExtensions(DatabaseManager $manager): array
{
    return (fn () => $this->extensions)->call($manager);
}

describe('DB2ServiceProvider', function () {
    it('extends the db manager that was resolved before it registered', function () {
        $app = providerTestContainer();
        $before = $app->make('db');

        (new DB2ServiceProvider($app))->register();

        expect(registeredExtensions($before))->toHaveKeys(['db2', 'vms_rms_gateway']);
    });

    it('extends the db manager when it is resolved after the provider registered', function () {
        $app = providerTestContainer();

        (new DB2ServiceProvider($app))->register();

        expect(registeredExtensions($app->make('db')))->toHaveKeys(['db2', 'vms_rms_gateway']);
    });

    // eloquent-spatial's provider re-runs DatabaseServiceProvider::register(), dropping the resolved manager.
    it('extends the db manager built after another provider rebinds db', function () {
        $app = providerTestContainer();
        $app->make('db');

        (new DB2ServiceProvider($app))->register();
        bindDatabaseManager($app);

        expect(registeredExtensions($app->make('db')))->toHaveKeys(['db2', 'vms_rms_gateway']);
    });

    it('does not extend the same manager twice', function () {
        $app = providerTestContainer();
        $app->make('db');

        (new DB2ServiceProvider($app))->register();
        $manager = $app->make('db');
        $extension = registeredExtensions($manager)['db2'];
        $app->make('db');

        expect(registeredExtensions($manager)['db2'])->toBe($extension);
    });
});
