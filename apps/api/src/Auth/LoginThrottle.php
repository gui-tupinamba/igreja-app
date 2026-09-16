<?php

declare(strict_types=1);

namespace App\Auth;

use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** Shared by all PHP workers; rejected requests also consume both counters. */
final class LoginThrottle
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
    ) {
    }

    public function consume(string $email, string $ip): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new LogicException('Login throttling must commit before credential verification.');
        }

        $limits = [
            hash_hmac('sha256', 'account:'.strtolower(trim($email)), $this->appSecret) => 10,
            hash_hmac('sha256', 'ip:'.$ip, $this->appSecret) => 100,
        ];
        // Stable order prevents opposite account/IP combinations from deadlocking.
        ksort($limits);
        $retryAfter = $this->connection->transactional(function () use ($limits): int {
            $rejectedFor = 0;
            foreach ($limits as $id => $limit) {
                $row = $this->connection->fetchAssociative(<<<'SQL'
                    INSERT INTO auth_login_limits (id, attempts, window_started_at)
                    VALUES (?, 1, to_timestamp(floor(extract(epoch FROM clock_timestamp()) / 900) * 900))
                    ON CONFLICT (id) DO UPDATE SET
                        attempts = CASE
                            WHEN auth_login_limits.window_started_at < EXCLUDED.window_started_at THEN 1
                            ELSE LEAST(auth_login_limits.attempts::bigint + 1, 2147483647)::integer
                        END,
                        window_started_at = GREATEST(auth_login_limits.window_started_at, EXCLUDED.window_started_at)
                    RETURNING attempts,
                        GREATEST(1, ceil(extract(epoch FROM window_started_at + interval '15 minutes' - clock_timestamp())))::integer AS retry_after
                    SQL, [$id]);
                if ($row !== false && (int) $row['attempts'] > $limit) {
                    $rejectedFor = max($rejectedFor, (int) $row['retry_after']);
                }
            }

            return $rejectedFor;
        });

        if ($retryAfter > 0) {
            throw new TooManyRequestsHttpException($retryAfter);
        }
    }

    /** Run from controlled maintenance; stale counters no longer affect any window. */
    public function pruneExpired(): int
    {
        return $this->connection->executeStatement(
            "DELETE FROM auth_login_limits WHERE window_started_at < clock_timestamp() - interval '1 day'",
        );
    }
}
