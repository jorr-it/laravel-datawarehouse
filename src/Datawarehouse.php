<?php

namespace JorrIt\LaravelDatawarehouse;

use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\Process\Process;

/**
 * @see: https://github.com/illuminate/database
 * @see: https://codecourse.com/articles/using-eloquent-outside-of-laravel
 */
class Datawarehouse
{
    private static ?Process $sshProcess = null;
    private static bool $shutdownHandlerRegistered = false;

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
     * @param string|null $sshCommand should start with 'ssh'
     * @param string|bool $sslCertificate false for no, true for yes but no cert check, string for yes with cert check
     * @return Capsule also available as global Capsule facade with static methods
     */
    public static function connect(array $config, ?string $sshCommand = null, string|bool $sslCertificate = false, string $connectionName = 'default') : Capsule
    {
        // SSH
        if ($sshCommand) {
            self::registerShutdownHandler();
            self::disconnect();

            $commandArray = preg_split('/\s+(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $sshCommand);
            $commandArray = array_map(fn($item) => trim($item, '"'), $commandArray);
            self::$sshProcess = new Process($commandArray);
            self::$sshProcess->start();
            sleep(1);
        }
    
        // SSL
        $options = [];
        if ($sslCertificate === true) {
            $options = [
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ];
        }
        elseif ($sslCertificate) {
            $options = [
                \PDO::MYSQL_ATTR_SSL_CA => $sslCertificate,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ];            
        }        

        $capsule = new Capsule();

        $capsule->addConnection(array_merge([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',  
            'options' => $options,                      
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

    public static function disconnect() : void
    {
        if (! self::$sshProcess instanceof Process) {
            return;
        }

        if (self::$sshProcess->isRunning()) {
            self::$sshProcess->stop(1);
        }

        self::$sshProcess = null;
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

    private static function registerShutdownHandler() : void
    {
        if (self::$shutdownHandlerRegistered) {
            return;
        }

        register_shutdown_function(static fn() => self::disconnect());
        self::$shutdownHandlerRegistered = true;
    }
}