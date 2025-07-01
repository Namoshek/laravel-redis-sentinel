# Laravel Redis Sentinel driver for `phpredis/phpredis` PHP extension

[![Latest Version on Packagist](https://img.shields.io/packagist/v/namoshek/laravel-redis-sentinel.svg?style=flat-square)](https://packagist.org/packages/namoshek/laravel-redis-sentinel)
[![Total Downloads](https://img.shields.io/packagist/dt/namoshek/laravel-redis-sentinel.svg?style=flat-square)](https://packagist.org/packages/namoshek/laravel-redis-sentinel)
[![Tests](https://github.com/Namoshek/laravel-redis-sentinel/workflows/Tests/badge.svg)](https://github.com/Namoshek/laravel-redis-sentinel/actions?query=workflow%3ATests)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=alert_status)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=sqale_rating)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=reliability_rating)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=security_rating)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=vulnerabilities)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![License](https://poser.pugx.org/namoshek/laravel-redis-sentinel/license)](https://packagist.org/packages/namoshek/laravel-redis-sentinel)

This package provides a Laravel Redis driver which allows connecting to a Redis master through a Redis Sentinel instance.
The package is intended to be used in a Kubernetes environment or similar, where connecting to Redis Sentinels is possible through a load balancer.

This driver is an alternative to [`monospice/laravel-redis-sentinel-drivers`](https://github.com/monospice/laravel-redis-sentinel-drivers).
The primary difference is that this driver supports the [`phpredis/phpredis` PHP extension](https://github.com/phpredis/phpredis)
and has significantly simpler configuration, due to a simpler architecture.
In detail this means that this package does not override the entire Redis subsystem of Laravel, it only adds an additional driver.

By default, Laravel supports the `predis` and `phpredis` drivers. This package adds a third `phpredis-sentinel` driver,
which is an extension of the `phpredis` driver for Redis Sentinel.
An extension for `predis` is currently not available and not necessary, since [`predis/predis`](https://github.com/predis/predis) already supports
connecting to Redis through one or more Sentinels.

## Installation

You can install the package via composer:

```bash
composer require namoshek/laravel-redis-sentinel
```

The service provider which comes with the package is registered automatically.

## Configuration

The package requires no extra configuration and does therefore not provide an additional configuration file.

## Usage

To use the Redis Sentinel driver, the `redis` section in `config/database.php` needs to be adjusted:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis-sentinel'),

    'default' => [
        'sentinel_host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
        'sentinel_port' => (int) env('REDIS_SENTINEL_PORT', 26379),
        'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        'sentinel_timeout' => (float) env('REDIS_SENTINEL_TIMEOUT', 0),
        'sentinel_persistent' => env('REDIS_SENTINEL_PERSISTENT'),
        'sentinel_retry_interval' => (int) env('REDIS_SENTINEL_RETRY_INTERVAL', 0),
        'sentinel_read_timeout' => (float) env('REDIS_SENTINEL_READ_TIMEOUT', 0),
        'sentinel_username' => env('REDIS_SENTINEL_USERNAME'),
        'sentinel_password' => env('REDIS_SENTINEL_PASSWORD'),

        'connector_retry_attempts' => env('REDIS_CONNECTOR_RETRY_ATTEMPTS'),
        'connector_retry_delay' => env('REDIS_CONNECTOR_RETRY_DELAY'),

        'password' => env('REDIS_PASSWORD'),
        'database' => (int) env('REDIS_DB', 0),
    ]
]
```

Instead of changing `redis.client` in the configuration file directly, you can also set `REDIS_CLIENT=phpredis-sentinel` in the environment variables.

As you can see, there are also a few new `sentinel_*` options available for each Redis connection.
Most of them work very similar to the normal Redis options, except that they are used for the connection to Redis Sentinel.
Noteworthy is the `sentinel_service`, which represents the instance name of the monitored Redis master.

All other options are the same for the Redis Sentinel driver, except that `url` is not supported and `host` and `port` are ignored.

### SSL/TLS Support

If you want to use SSL/TLS to connect to Redis Sentinel, you need to add an additional configuration option `sentinel_ssl` next to the other `sentinel_*` settings:

```php
'sentinel_ssl' => [
    // ... SSL settings ...
],
```

Available SSL context options can be found in the [official PHP documentation](https://www.php.net/manual/en/context.ssl.php). Please note that SSL support for the Sentinel connection was added to the `phpredis` extension starting in version 6.1.

Also note that if your Redis Sentinel resolves SSL connections to Redis, you potentially need to add additional context options for your Redis connection:

```php
'context' => [
    'stream' => [
        // ... SSL settings ...
    ]
],
'scheme' => 'tls',
```

A full configuration example using SSL for Redis Sentinel as well as Redis looks like this if authentication is also enabled (environment variables omitted for clarity):

```php
'redis' => [
    'client' => 'phpredis-sentinel',

    'redis_with_tls' => [
        'sentinel_host' => 'tls://sentinel_host',
        'sentinel_port' => 26379,
        'sentinel_service' => 'mymaster',
        'sentinel_timeout' => 0,
        'sentinel_persistent' => false,
        'sentinel_retry_interval' => 0,
        'sentinel_read_timeout' => 0,
        'sentinel_username' => 'sentinel_username',
        'sentinel_password' => 'sentinel_password',
        'sentinel_ssl' => [
            'cafile' => '/path/to/sentinel_ca.crt',
        ],
        'context' => [
            'stream' => [
                'cafile' => '/path/to/redis_ca.crt',
            ],
        ],
        'scheme' => 'tls',
        'username' => 'redis_username',
        'password' => 'redis_password',
        'database' => 1,
    ]
]
```

The important parts are the `tls://` protocol in `sentinel_host` as well as the `tls` in `scheme`, plus the `sentinel_ssl` and `context.stream` options.

Because Redis Sentinel resolves Redis instances by IP and port, your Redis certificate needs to have the IP as SAN. Alternatively, you can set `verify_peer` and maybe also `verify_peer_name` to `false`.

### How does it work?

An additional Laravel Redis driver is added (`phpredis-sentinel`), which resolves the currently declared master instance of a replication
cluster as active Redis instance. Under the hood, this driver relies on the framework driver for [`phpredis/phpredis`](https://github.com/phpredis/phpredis),
it only wraps the connection part of it and adds some error handling which forcefully reconnects in case of a failover.

Please be aware that this package does not manage load balancing between Sentinels (which is supposed to be done on an infrastructure level)
and does also not load balance read/write calls to replica/master nodes. All traffic is sent to the currently reported master.

## Developing

To run the tests locally, a Redis cluster needs to be running.
The repository contains a script (thanks to [`monospice/laravel-redis-sentinel-drivers`](https://github.com/monospice/laravel-redis-sentinel-drivers))
which can be used to start one by running `sh start-redis-cluster.sh`.
The script requires that Redis is installed on your machine. To install Redis on Ubuntu or Debian,
you can use `sudo apt update && sudo apt install redis-server`. For other operating systems, please see [redis.io](https://redis.io/).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
