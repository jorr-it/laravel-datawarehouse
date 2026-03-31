<?php

namespace JorrIt\LaravelDatawarehouse;


use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * @see: https://github.com/illuminate/database
 * @see: https://codecourse.com/articles/using-eloquent-outside-of-laravel
 */
class Datawarehouse
{
    /**
     * Establish a connection to the datawarehouse using the provided configuration.
     *
     * @param array $config {
     *     @type string $driver (optional, default 'mysql')
     *     @type string $host (optional, default 'localhost')
     *     @type int $port (optional, default 3306)
     *     @type string $database
     *     @type string $username
     *     @type string $password
     *     @type string $charset (optional, default 'utf8_unicode_ci')
     *     @type string $collation (optional, default 'utf8')
     *     @type string $prefix (optional, default '')
     * }
     * @return Capsule also available as global Capsule facade with static methods
     */
    public static function connect(array $config, string $connectionName = 'default') : Capsule
    {
        $capsule = new Capsule();

        $capsule->addConnection(array_merge([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',            
        ], $config), $connectionName);

        // Make this Capsule instance available globally via static methods
        $capsule->setAsGlobal();

        // Setup the Eloquent ORM
        $capsule->bootEloquent();

        // Make this the default connection
        $capsule->getDatabaseManager()->setDefaultConnection($connectionName);
        $capsule->getDatabaseManager()->disableQueryLog();

        return $capsule;
    }

    /**
     * Run a set of Transformation classes
     * 
     * @param array<class-string<Transformation>, mixed> $transformations Key is name of Transformation class, value is optional Transformation constructor parameter or null
     * @param bool $reset Whether to go down first
     */
    public static function transform(array $transformations, bool $reset = false)
    {
        foreach ($transformations as $classname => $constructionParameter) {

            $transformation = $constructionParameter ? new $classname($constructionParameter) : new $classname();
            if ($reset) 
                $transformation->down();
            $transformation->up();
        }
    }
}