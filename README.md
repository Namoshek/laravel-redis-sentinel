# Laravel Redis Sentinel driver to connect to Redis through Sentinel(s)

:warning: :construction: **This package is currently under development. An initial release will be added soon.** :construction: :warning:

[![Latest Version on Packagist](https://img.shields.io/packagist/v/namoshek/laravel-redis-sentinel.svg?style=flat-square)](https://packagist.org/packages/namoshek/laravel-redis-sentinel)
[![Total Downloads](https://img.shields.io/packagist/dt/namoshek/laravel-redis-sentinel.svg?style=flat-square)](https://packagist.org/packages/namoshek/laravel-redis-sentinel)
[![Tests](https://github.com/Namoshek/laravel-redis-sentinel/workflows/Tests/badge.svg)](https://github.com/Namoshek/laravel-redis-sentinel/actions?query=workflow%3ATests)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=alert_status)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=sqale_rating)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=reliability_rating)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=security_rating)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=namoshek_laravel-redis-sentinel&metric=vulnerabilities)](https://sonarcloud.io/dashboard?id=namoshek_laravel-redis-sentinel)
[![License](https://poser.pugx.org/namoshek/laravel-redis-sentinel/license)](https://packagist.org/packages/namoshek/laravel-redis-sentinel)

This package provides a Laravel Redis driver which allows to connect to a Redis master through a Redis Sentinel instance.
The package is intended to be used in a Kubernetes environment or similar, where connecting to Redis Sentinels is possible through a load balancer.

This driver is an alternative to [`monospice/laravel-redis-sentinel-drivers`](https://github.com/monospice/laravel-redis-sentinel-drivers).
The primary difference is that this driver supports the [`phpredis/phpredis` PHP extension](https://github.com/phpredis/phpredis)
and has significantly simpler configuration, due to a simpler architecture.
In detail this means that this package does not override the entire Redis subsystem of Laravel, it only adds an additional driver.

By default, Laravel supports the `predis` and `phpredis` drivers. This package adds a `phpredis-sentinel` driver,
which is an extension of the `phpredis` driver for Redis Sentinel. An extension for `predis` is currently not available.

## Installation

You can install the package via composer:

```bash
composer require namoshek/laravel-redis-sentinel
```

The service provider which comes with the package is registered automatically.

## Configuration

The package requires no extra configuration and does therefore not provide an additional configuration file.

## Usage

To use the Redis Sentinel driver, the `redis.client` in `config/database.php` needs to be adjusted:

```php
'redis' => [

    'client' => env('REDIS_CLIENT', 'phpredis-sentinel'),

    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
        'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        'sentinel_timeout' => env('REDIS_SENTINEL_TIMEOUT', 0.2),
        'sentinel_persistent' => env('REDIS_SENTINEL_PERSISTENT', null),
        'sentinel_retry_interval' => env('REDIS_SENTINEL_RETRY_INTERVAL', 0),
        'sentinel_read_timeout' => env('REDIS_SENTINEL_READ_TIMEOUT', 0),
        'sentinel_password' => env('REDIS_SENTINEL_PASSWORD', null),
    ]
]
```

Instead of changing the configuration file directly, you can also set `REDIS_CLIENT=phpredis-sentinel` in the environment variables,
if you are using the default configuration for Redis so far.

As you can see, there are also a few new option `sentinel_*` options available for a connection.
Most of them work very similar to the normal Redis options, except that they are used for the connection to Redis Sentinel.
Noteworthy is the `sentinel_service`, which represents the instance name of the monitored Redis master.

All other options are the same for the Redis Sentinel driver, except that `url` is not supported.
Also keep in mind that the `REDIS_PORT` should be the port of your Sentinel, e.g. `26379` which is the default.
This is because the configuration now defines how we connect to the Sentinel, not Redis directly.

### How does it work?

An additional Laravel Redis driver is added (`phpredis-sentinel`), which resolves the currently declared master instance of a replication
cluster as active Redis instance. Under the hood, this driver relies on the framework driver for [`phpredis/phpredis`](https://github.com/phpredis/phpredis),
it only wraps the connection part of it and adds some error handling which forces a reconnect in case of a failover.

## Limitations

For the moment, this package supports only the [`phpredis/phpredis` PHP extension](https://github.com/phpredis/phpredis).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
