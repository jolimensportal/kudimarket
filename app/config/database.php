<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'unix_socket' => env('DB_SOCKET', ''),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        // Ops connection for the Filament console. The web app role cannot call ops_transition;
        // a dispute decision is a staff action and is recorded under this role. Separate from the
        // app connection so a compromise of the trader-facing app does not carry desk authority.
        // The trader app's connection. A separate entry rather than reusing 'pgsql', because it is a
        // different database role with different limits: kudi_trader can open a trade and claim, and
        // cannot resolve a dispute or write a ledger row. If the trader app ever ran as kudi_app the
        // two would look the same in code and stop being different in fact.
        'pgsql_trader' => [
            'driver' => 'pgsql',
            'url' => env('DB_TRADER_URL'),
            'host' => env('DB_TRADER_HOST', '127.0.0.1'),
            'unix_socket' => env('DB_TRADER_SOCKET', ''),
            'port' => env('DB_TRADER_PORT', '5432'),
            'database' => env('DB_TRADER_DATABASE', 'kudi_dev'),
            'username' => env('DB_TRADER_USERNAME', 'kudi_trader_login'),
            'password' => env('DB_TRADER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],

        // Pricing gets its own connection for the same reason ops and trader do: SET ROLE is session
        // state, so switching a shared connection to kudi_pricing leaves every later query on that
        // connection running as pricing. That bit once and looked like a permissions bug in 008.
        'pgsql_pricing' => [
            'driver' => 'pgsql',
            'url' => env('DB_PRICING_URL'),
            // host defaults to the socket, not 127.0.0.1. The pgsql driver prefers host over
            // unix_socket, so a host defaulting to TCP silently wins over a configured socket and the
            // connection is refused by a cluster that listens on a socket only. Matching pgsql_ops.
            'host' => env('DB_PRICING_HOST', '/tmp'),
            'unix_socket' => env('DB_PRICING_SOCKET', ''),
            'port' => env('DB_PRICING_PORT', '5432'),
            'database' => env('DB_PRICING_DATABASE', 'kudi_dev'),
            'username' => env('DB_PRICING_USERNAME', 'kudi_pricing_login'),
            'password' => env('DB_PRICING_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],

        'pgsql_ops' => [
            'driver' => 'pgsql',
            'url' => env('DB_OPS_URL'),
            'host' => env('DB_OPS_HOST', '127.0.0.1'),
            'unix_socket' => env('DB_OPS_SOCKET', ''),
            'port' => env('DB_OPS_PORT', '5432'),
            'database' => env('DB_OPS_DATABASE', 'kudi_dev'),
            'username' => env('DB_OPS_USERNAME', 'kudi_ops_login'),
            'password' => env('DB_OPS_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],

        // Admin connection: migrations and seeding only. The application NEVER connects with this
        // role. kudi is a cluster superuser, and a regulated operator cannot run its product on a
        // superuser connection.
        'pgsql_admin' => [
            'driver' => 'pgsql',
            'url' => env('DB_ADMIN_URL'),
            'host' => env('DB_ADMIN_HOST', '127.0.0.1'),
            'unix_socket' => env('DB_ADMIN_SOCKET', ''),
            'port' => env('DB_ADMIN_PORT', '5432'),
            'database' => env('DB_ADMIN_DATABASE', 'kudi_dev'),
            'username' => env('DB_ADMIN_USERNAME', 'kudi'),
            'password' => env('DB_ADMIN_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
