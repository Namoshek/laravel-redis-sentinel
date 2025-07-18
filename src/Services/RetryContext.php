<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Services;

use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use RedisException;

class RetryContext
{
    /**
     * @param  int  $retryAttempts  The number of times a commend is retried when it fails
     * @param  int  $retryDelay  The time in milliseconds to wait before retrying
     */
    public function __construct(
        protected RetryManager $retryManager,
        protected int $retryAttempts,
        protected int $retryDelay,
    ) {
        //
    }

    /**
     * Attempt to retry the provided operation when the client fails to connect
     * to a Redis server.
     *
     * @param  callable  $callback  The operation to execute.
     * @param  int|null  $retryAttempts  The number of times the retry is performed.
     * @param  int|null  $retryDelay  The time in milliseconds to wait before retrying again.
     * @param  callable|null  $failureCallback  The callback to execute when failure occours.
     * @return mixed The result of the first successful attempt.
     *
     * @throws RetryRedisException|RedisException
     */
    public function retryOnFailure(
        callable $callback,
        ?int $retryAttempts = null,
        ?int $retryDelay = null,
        ?callable $failureCallback = null,
    ): mixed {
        $retryAttempts ??= $this->retryAttempts;
        $retryDelay ??= $this->retryDelay;

        return $this->retryManager->retryOnFailure(
            $callback,
            $retryAttempts,
            $retryDelay,
            $failureCallback,
        );
    }

    /**
     * Retrieve the RetryManager.
     */
    public function manager(): RetryManager
    {
        return $this->retryManager;
    }
}
