<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Services;

use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use RedisException;
use Throwable;

class RetryService
{
    /**
     * The following array contains all exception message parts which are interpreted as a connection loss or
     * another unavailability of Redis.
     */
    public const ERROR_MESSAGES_INDICATING_UNAVAILABILITY = [
        'connection closed',
        'connection refused',
        'connection lost',
        'failed while reconnecting',
        'is loading the dataset in memory',
        'php_network_getaddresses',
        'read error on connection',
        'socket',
        'went away',
        'loading',
        'readonly',
        "can't write against a read only replica",
    ];

    /**
     * The following array contains all exception message parts which are interpreted as an issue with name
     * resolving.
     */
    public const MESSAGES_INDICATING_NAME_RESOLUTION_ERRORS = [
        'getaddrinfo',
        'name or service not known',
    ];

    /**
     * Attempt to retry the provided operation when the client fails to connect
     * to a Redis server.
     *
     * @param  callable  $callback  The operation to execute.
     * @param  int  $retryAttempts  The number of times the retry is performed.
     * @param  int  $retryDelay  The time in milliseconds to wait before retrying again.
     * @param  callable|null  $failureCallback  The callback to execute when failure occours.
     * @return mixed The result of the first successful attempt.
     *
     * @throws RetryRedisException|RedisException
     */
    public function retryOnFailure(
        callable $callback,
        int $retryAttempts,
        int $retryDelay,
        ?callable $failureCallback = null,
    ): mixed {
        $attempts = 0;
        $lastException = null;
        for ($currentAttempt = 0; $currentAttempt <= $retryAttempts; $currentAttempt++) {
            try {
                return $callback();
            } catch (Throwable $exception) {
                // Check if we should retry this exception.
                if (! $this->shouldRetry($exception)) {
                    throw $exception;
                }

                // Wait before retry.
                if ($retryAttempts !== 0) {
                    usleep($retryDelay * 1000);
                }

                // Execute optional failure callback.
                if ($failureCallback && is_callable($failureCallback)) {
                    call_user_func($failureCallback);
                }

                $lastException = $exception;
            }
        }

        throw new RetryRedisException(sprintf('Reached the (re)connect limit of %d attempts.', $attempts), 0, $lastException);
    }

    /**
     * We check if the Exception should be retried. This means checking for:
     * - retryable redis exceptions
     * - name resolution exceptions
     */
    public function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof RedisException && $this->shouldRetryRedisException($exception)) {
            return true;
        }

        if ($this->isNameResolutionException($exception)) {
            return true;
        }

        return false;
    }

    /**
     * Inspects the given exception and reconnects the client if the reported error indicates that the server
     * went away or is in readonly mode, which may happen in case of a Redis Sentinel failover.
     */
    public function shouldRetryRedisException(RedisException $exception): bool
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::ERROR_MESSAGES_INDICATING_UNAVAILABILITY as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the given exception is a name resolution exception.
     */
    public function isNameResolutionException(Throwable $exception): bool
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::MESSAGES_INDICATING_NAME_RESOLUTION_ERRORS as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                return true;
            }
        }

        return false;
    }
}
