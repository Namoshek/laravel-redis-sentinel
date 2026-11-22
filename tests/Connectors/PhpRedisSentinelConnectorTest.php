<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Tests\Connectors;

use Illuminate\Redis\RedisManager;
use Mockery;
use Mockery\MockInterface;
use Namoshek\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Namoshek\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use Namoshek\Redis\Sentinel\Services\RetryManager;
use Namoshek\Redis\Sentinel\Tests\TestCase;
use Redis;
use RedisException;

/**
 * Ensures that the {@see PhpRedisSentinelConnector} functions properly.
 */
class PhpRedisSentinelConnectorTest extends TestCase
{
    public function test_initial_connection_retries_after_a_connection_failure(): void
    {
        $client = new Redis;
        $attempts = 0;
        $connector = Mockery::mock(PhpRedisSentinelConnector::class, [new RetryManager])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $connector->shouldReceive('createClient')
            ->twice()
            ->andReturnUsing(function () use (&$attempts, $client) {
                if (++$attempts === 1) {
                    throw new RedisException('connection refused');
                }

                return $client;
            });

        $connection = $connector->connect([
            'connector_retry_attempts' => 1,
            'connector_retry_delay' => 0,
        ], []);

        self::assertSame($client, $connection->client());
    }

    public function test_connecting_to_redis_through_sentinel_without_password_works(): void
    {
        $connection = $this->getRedisConnection();
        $serverId = $this->extractServerId($connection->executeRaw(['INFO', 'server']));

        self::assertTrue($connection->ping());
        self::assertSame($serverId, $this->extractServerId($connection->executeRaw(['INFO', 'server'])));
    }

    public function test_initial_connect_to_redis_with_a_failing_node(): void
    {
        // Retrieve the connection.
        $connection = $this->getRedisConnection();
        $serverId = $this->extractServerId($connection->executeRaw(['INFO', 'server']));
        self::assertNotNull($serverId);

        // Force the shutdown of a node, but avoid aborting the test case.
        try {
            $connection->client()->rawCommand('DEBUG', 'SEGFAULT');
        } catch (RedisException) {
            // Ignored on purpose.
        }

        // Recreate the connection.
        $this->app->forgetInstance('redis');
        $connection = $this->getRedisConnection();

        $newServerId = $this->extractServerId($connection->executeRaw(['INFO', 'server']));
        self::assertNotNull($newServerId);
        self::assertNotSame($serverId, $newServerId);
    }

    public function test_reconnects_when_master_goes_away(): void
    {
        /** @var MockInterface $spy */
        $spy = $this->spy(RetryManager::class, fn (MockInterface $mock) => $mock->makePartial());

        // Connect for the first time and remember the server.
        $connection = $this->getRedisConnection();
        $serverId = $this->extractServerId($connection->executeRaw(['INFO', 'server']));
        self::assertNotNull($serverId);

        // Perform some random actions.
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        $spy->shouldNotHaveReceived('retry');

        // Force the shutdown of a node, but avoid aborting the test case.
        try {
            $connection->client()->rawCommand('DEBUG', 'SEGFAULT');
        } catch (RedisException) {
            // Ignored on purpose.
        }

        // Set and check if a new Redis instance is connected.
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Check the server id is updated.
        $newServerId = $this->extractServerId($connection->executeRaw(['INFO', 'server']));
        self::assertNotNull($newServerId);
        self::assertNotSame($serverId, $newServerId);
    }

    public function test_no_retries_on_normal_exception(): void
    {
        $connection = $this->getRedisConnection();
        $client = $connection->client();

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(fn (Redis $redis) => throw new RedisException('this exception should not be retried.'));
            self::fail('Expected the original exception.');
        } catch (RedisException $e) {
            self::assertNotInstanceOf(RetryRedisException::class, $e);
            self::assertSame('this exception should not be retried.', $e->getMessage());

            // We need to discard the ->multi() in the transaction, otherwise other tests may fail.
            $connection->client()->discard();
        }

        $connection->set('foo', 'bar');

        // Check that the client was not replaced.
        self::assertSame($client, $connection->client());
    }

    public function test_when_connection_goes_away_it_is_reestablished(): void
    {
        // Keep the original client alive for the identity comparison.
        $connection = $this->getRedisConnection();
        $client = $connection->client();

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(
                fn (Redis $redis) => throw new RedisException('went away'),
                retryAttempts: 0,
            );
            self::fail('Expected a retry exception.');
        } catch (RetryRedisException $e) {
            self::assertSame('went away', $e->getPrevious()?->getMessage());
        }

        // Check that the client was replaced.
        self::assertNotSame($client, $connection->client());
    }

    public function test_when_connection_becomes_readonly_it_is_reestablished(): void
    {
        // Keep the original client alive for the identity comparison.
        $connection = $this->getRedisConnection();
        $client = $connection->client();

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(
                fn (Redis $redis) => throw new RedisException('READONLY'),
                retryAttempts: 0,
            );
            self::fail('Expected a retry exception.');
        } catch (RetryRedisException $e) {
            self::assertSame('READONLY', $e->getPrevious()?->getMessage());
        }

        // Check that the client was replaced.
        self::assertNotSame($client, $connection->client());
    }

    public function test_when_connection_becomes_readonly_it_is_reestablished2(): void
    {
        // Keep the original client alive for the identity comparison.
        $connection = $this->getRedisConnection();
        $client = $connection->client();

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(
                fn (Redis $redis) => throw new RedisException("You can't write against a read only replica"),
                retryAttempts: 0,
            );
            self::fail('Expected a retry exception.');
        } catch (RetryRedisException $e) {
            self::assertSame("You can't write against a read only replica", $e->getPrevious()?->getMessage());
        }

        // Check that the client was replaced.
        self::assertNotSame($client, $connection->client());
    }

    /**
     * Extract the run_id from the server info block.
     */
    private function extractServerId(string $serverInfo): ?string
    {
        if (preg_match('/^run_id:(.+)$/m', $serverInfo, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Retrieve the Redis connection.
     */
    private function getRedisConnection(string $name = 'default'): PhpRedisSentinelConnection
    {
        /** @var RedisManager $redisManager */
        $redisManager = $this->app->make('redis');

        /** @var PhpRedisSentinelConnection $connection */
        return $redisManager->connection($name);
    }
}
