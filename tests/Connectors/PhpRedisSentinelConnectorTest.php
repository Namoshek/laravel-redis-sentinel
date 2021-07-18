<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Tests\Connectors;

use Illuminate\Redis\RedisManager;
use Namoshek\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Namoshek\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Namoshek\Redis\Sentinel\Exceptions\NotImplementedException;
use Namoshek\Redis\Sentinel\Tests\TestCase;
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
        $connector->connectToCluster(
            config: [],
            clusterOptions: [],
            options: []
        );
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
}
