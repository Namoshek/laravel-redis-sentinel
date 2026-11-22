<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Tests\Services;

use Namoshek\Redis\Sentinel\Services\RetryManager;
use PHPUnit\Framework\TestCase;
use RedisException;

class RetryManagerTest extends TestCase
{
    public function test_retryable_failure_is_retried_until_success(): void
    {
        $attempts = 0;
        $failures = 0;

        $result = (new RetryManager)->retryOnFailure(
            function () use (&$attempts) {
                if (++$attempts <= 2) {
                    throw new RedisException('connection refused');
                }

                return 'connected';
            },
            retryAttempts: 2,
            retryDelay: 0,
            failureCallback: function () use (&$failures) {
                $failures++;
            },
        );

        self::assertSame('connected', $result);
        self::assertSame(3, $attempts);
        self::assertSame(2, $failures);
    }
}
