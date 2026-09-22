<?php

namespace App\Modules\Identity\Oidc;

use Illuminate\Contracts\Session\Session;

class OidcTransactionStore
{
    public const SESSION_KEY = '_oidc_transactions';

    public function __construct(
        protected Session $session,
        protected ?int $ttl = null
    ) {
    }

    /**
     * Resolve the configured transaction TTL in seconds.
     */
    protected function getTtl(): int
    {
        return $this->ttl ?? (int) config('oidc.transaction_ttl', 300);
    }

    /**
     * Store an OIDC transaction, pruning expired entries first.
     * Supports multiple concurrent transactions in the same session.
     */
    public function store(OidcTransaction $transaction): void
    {
        $this->pruneExpired();

        $transactions = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($transactions)) {
            $transactions = [];
        }

        $transactions[$transaction->state] = $transaction->toArray();

        $this->session->put(self::SESSION_KEY, $transactions);
    }

    /**
     * Find an active transaction by state without consuming it.
     *
     * Fails closed (returns null) for:
     * - empty / missing state
     * - unknown state
     * - expired transaction (purging the expired record)
     * - malformed record
     */
    public function find(string $state): ?OidcTransaction
    {
        if ($state === '') {
            return null;
        }

        $transactions = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($transactions) || !isset($transactions[$state])) {
            return null;
        }

        $record = $transactions[$state];
        if (!is_array($record)) {
            unset($transactions[$state]);
            $this->session->put(self::SESSION_KEY, $transactions);
            return null;
        }

        $transaction = OidcTransaction::fromArray($record);
        if ($transaction === null) {
            unset($transactions[$state]);
            $this->session->put(self::SESSION_KEY, $transactions);
            return null;
        }

        if ($this->isExpired($transaction)) {
            unset($transactions[$state]);
            $this->session->put(self::SESSION_KEY, $transactions);
            return null;
        }

        return $transaction;
    }

    /**
     * Consume an active transaction by state, removing it immediately from storage.
     * Guarantees sequential single-use semantics under serialized session access.
     *
     * IMPORTANT CONCURRENCY NOTE:
     * Session-backed mutation alone does not provide cross-request atomicity against
     * overlapping concurrent HTTP requests in Laravel without route session blocking.
     * The future Phase 7D/7E callback route must employ route-level session blocking
     * (e.g. ->block()) to prevent concurrent callback race conditions.
     */
    public function consume(string $state): ?OidcTransaction
    {
        if ($state === '') {
            return null;
        }

        $transactions = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($transactions) || !isset($transactions[$state])) {
            return null;
        }

        $record = $transactions[$state];
        unset($transactions[$state]);
        $this->session->put(self::SESSION_KEY, $transactions);

        if (!is_array($record)) {
            return null;
        }

        $transaction = OidcTransaction::fromArray($record);
        if ($transaction === null) {
            return null;
        }

        if ($this->isExpired($transaction)) {
            return null;
        }

        return $transaction;
    }

    /**
     * Prune all expired or malformed transactions from session storage.
     */
    public function pruneExpired(): void
    {
        $transactions = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($transactions)) {
            $this->session->forget(self::SESSION_KEY);
            return;
        }

        $changed = false;
        foreach ($transactions as $state => $record) {
            if (!is_array($record)) {
                unset($transactions[$state]);
                $changed = true;
                continue;
            }

            $transaction = OidcTransaction::fromArray($record);
            if ($transaction === null || $this->isExpired($transaction)) {
                unset($transactions[$state]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->session->put(self::SESSION_KEY, $transactions);
        }
    }

    /**
     * Check if a transaction is expired based on configured TTL.
     */
    protected function isExpired(OidcTransaction $transaction): bool
    {
        return (time() - $transaction->createdAt) > $this->getTtl();
    }
}
