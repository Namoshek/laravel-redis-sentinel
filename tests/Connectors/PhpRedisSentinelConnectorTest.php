<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Tests\Connectors;

use Illuminate\Redis\RedisManager;
use Namoshek\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Namoshek\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Namoshek\Redis\Sentinel\Exceptions\NotImplementedException;
use Namoshek\Redis\Sentinel\Tests\TestCase;
use Redis;
use RedisException;

/**
 * Ensures that the {@see PhpRedisSentinelConnector} functions properly.
 */
class PhpRedisSentinelConnectorTest extends TestCase
{
    public function test_connecting_to_cluster_is_not_possible()
    {
        $this->expectException(NotImplementedException::class);

        $connector = new PhpRedisSentinelConnector();
        $connector->connectToCluster([], [], []);
    }

    /**
     * @throws RedisException
     */
    public function test_connecting_to_redis_through_sentinel_without_password_works()
    {
        /** @var RedisManager $redisManager */
        $redisManager = $this->app->make('redis');

        /** @var PhpRedisSentinelConnection $connection */
        $connection = $redisManager->connection('default');

        self::assertTrue($connection->ping());
    }

    /**
     * @throws RedisException
     */
    public function test_when_connection_goes_away_it_is_reestablished()
    {
        /** @var RedisManager $redisManager */
        $redisManager = $this->app->make('redis');

        // Connect for the first time and remember the object hash of the connection.
        /** @var PhpRedisSentinelConnection $connection */
        $connection = $redisManager->connection('default');
        $clientId = spl_object_hash($connection->client());

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(function (Redis $redis) {
                throw new RedisException('went away');
            });
        } catch (RedisException $e) {
            // Ignored on purpose.
        }

        // Connect a second time and compare the object hash of this and the old connection.
        $connection = $redisManager->connection('default');
        $clientId2 = spl_object_hash($connection->client());

        self::assertNotSame($clientId, $clientId2);
    }

    /**
     * @throws RedisException
     */
    public function test_when_connection_becomes_readonly_it_is_reestablished()
    {
        /** @var RedisManager $redisManager */
        $redisManager = $this->app->make('redis');

        // Connect for the first time and remember the object hash of the connection.
        /** @var PhpRedisSentinelConnection $connection */
        $connection = $redisManager->connection('default');
        $clientId = spl_object_hash($connection->client());

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(function (Redis $redis) {
                throw new RedisException('READONLY');
            });
        } catch (RedisException $e) {
            // Ignored on purpose.
        }

        // Connect a second time and compare the object hash of this and the old connection.
        $connection = $redisManager->connection('default');
        $clientId2 = spl_object_hash($connection->client());

        self::assertNotSame($clientId, $clientId2);
    }

    /**
     * @throws RedisException
     */
    public function test_when_connection_becomes_readonly_it_is_reestablished2()
    {
        /** @var RedisManager $redisManager */
        $redisManager = $this->app->make('redis');

        // Connect for the first time and remember the object hash of the connection.
        /** @var PhpRedisSentinelConnection $connection */
        $connection = $redisManager->connection('default');
        $clientId = spl_object_hash($connection->client());

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(function (Redis $redis) {
                throw new RedisException("You can't write against a read only replica");
            });
        } catch (RedisException $e) {
            // Ignored on purpose.
        }

        // Connect a second time and compare the object hash of this and the old connection.
        $connection = $redisManager->connection('default');
        $clientId2 = spl_object_hash($connection->client());

        self::assertNotSame($clientId, $clientId2);
    }
}
