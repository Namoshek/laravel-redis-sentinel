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
use ReflectionFunction;

/**
 * Ensures that the {@see PhpRedisSentinelConnector} functions properly.
 */
class PhpRedisSentinelConnectorTest extends TestCase
{
    public function test_connecting_to_redis_through_sentinel_without_password_works(): void
    {
        $connection = $this->getRedisConnection();

        self::assertTrue($connection->ping());
    }

    public function test_initial_connect_to_redis_with_a_failing_node(): void
    {
        /** @var \Mockery\MockInterface $spy */
        $spy = $this->spy(RetryManager::class, fn (MockInterface $mock) => $mock->makePartial());

        // Retrieve the connection.
        $connection = $this->getRedisConnection();

        // Force the shutdown of a node, but avoid aborting the test case.
        try {
            $connection->client()->rawCommand('DEBUG', 'SEGFAULT');
        } catch (RedisException) {
            // Ignored on purpose.
        }

        // Recreate the connection.
        $this->app->forgetInstance('redis');
        $connection = $this->getRedisConnection();

        /** @var \Mockery\VerificationDirector $expectation */
        $expectation = $spy->shouldHaveReceived('retry');

        // Make we are calling the retry from the connector and not the connection.
        $expectation->with(Mockery::on(function ($arg) {
            if (! is_callable($arg)) {
                return false;
            }

            $callableClass = (new ReflectionFunction($arg))
                ->getClosureScopeClass()
                ?->getName();

            return $callableClass === PhpRedisSentinelConnector::class;
        }));

        // It should be retried at least 3 times as there is some time before
        // sentinel marks the node as down and kicks in the failover.
        $expectation->between(3, PhpRedisSentinelConnector::DEFAULT_CONNECTOR_RETRY_ATTEMPTS);
    }

    public function test_retries_when_master_goes_away(): void
    {
        /** @var \Mockery\MockInterface $spy */
        $spy = $this->spy(RetryManager::class, fn (MockInterface $mock) => $mock->makePartial());

        // Connect for the first time and remember the object hash of the connection.
        $connection = $this->getRedisConnection();
        $port = $connection->executeRaw(['CONFIG', 'GET', 'port'])[1];

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

        /** @var \Mockery\VerificationDirector $expectation */
        $expectation = $spy->shouldHaveReceived('retry');

        // It should be retried at least 3 times as there is some time before
        // sentinel marks the node as down and kicks in the failover.
        $expectation->between(3, PhpRedisSentinelConnector::DEFAULT_CONNECTOR_RETRY_ATTEMPTS);

        // Check the port is updated.
        self::assertNotSame($port, $connection->executeRaw(['CONFIG', 'GET', 'port'])[1]);
    }

    public function test_no_retries_on_normal_exception(): void
    {
        $connection = $this->getRedisConnection();
        $clientId = spl_object_hash($connection->client());

        // Perform some random actions.
        $connection->ping();
        $connection->set('foo', 'bar');
        $connection->get('foo');
        $connection->del('foo');

        // Force an exception, but avoid aborting the test case.
        try {
            $connection->transaction(fn (Redis $redis) => throw new RedisException('this exception should not be retried.'));
        } catch (RedisException $e) {
            self::assertNotInstanceOf(RetryRedisException::class, $e);

            // We need to discard the ->multi() in the transaction, otherwise other tests may fail.
            $connection->client()->discard();
        }

        $connection->set('foo', 'bar');

        // Connect a second time and compare the object hash of this and the old connection.
        self::assertSame($clientId, spl_object_hash($connection->client()));
    }

    public function test_when_connection_goes_away_it_is_reestablished(): void
    {
        // Connect for the first time and remember the object hash of the connection.
        $connection = $this->getRedisConnection();
        $clientId = spl_object_hash($connection->client());

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
        } catch (RedisException) {
            // Ignored on purpose.
        }

        // Connect a second time and compare the object hash of this and the old connection.
        self::assertNotSame($clientId, spl_object_hash($connection->client()));
    }

    public function test_when_connection_becomes_readonly_it_is_reestablished(): void
    {
        // Connect for the first time and remember the object hash of the connection.
        $connection = $this->getRedisConnection();
        $clientId = spl_object_hash($connection->client());

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
        } catch (RedisException) {
            // Ignored on purpose.
        }

        // Connect a second time and compare the object hash of this and the old connection.
        self::assertNotSame($clientId, spl_object_hash($connection->client()));
    }

    public function test_when_connection_becomes_readonly_it_is_reestablished2(): void
    {
        // Connect for the first time and remember the object hash of the connection.
        $connection = $this->getRedisConnection();
        $clientId = spl_object_hash($connection->client());

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
        } catch (RedisException) {
            // Ignored on purpose.
        }

        // Connect a second time and compare the object hash of this and the old connection.
        self::assertNotSame($clientId, spl_object_hash($connection->client()));
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
