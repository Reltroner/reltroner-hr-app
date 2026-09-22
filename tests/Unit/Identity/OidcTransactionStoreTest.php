<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Oidc\OidcTransaction;
use App\Modules\Identity\Oidc\OidcTransactionStore;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class OidcTransactionStoreTest extends TestCase
{
    protected Store $session;
    protected OidcTransactionStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new Store('test_session', new ArraySessionHandler(10));
        $this->session->start();
        $this->store = new OidcTransactionStore($this->session, 300);
    }

    protected function makeTransaction(string $state = 'state-123', ?int $createdAt = null): OidcTransaction
    {
        return new OidcTransaction(
            state: $state,
            nonce: 'nonce-' . $state,
            codeVerifier: 'verifier-' . $state,
            codeChallenge: 'challenge-' . $state,
            createdAt: $createdAt ?? time(),
        );
    }

    /**
     * 1. Prove transaction can be stored and found.
     */
    public function test_transaction_can_be_stored_and_found(): void
    {
        $transaction = $this->makeTransaction('state-alpha');
        $this->store->store($transaction);

        $found = $this->store->find('state-alpha');

        $this->assertNotNull($found);
        $this->assertSame('state-alpha', $found->state);
        $this->assertSame('nonce-state-alpha', $found->nonce);
        $this->assertSame('verifier-state-alpha', $found->codeVerifier);
        $this->assertSame('challenge-state-alpha', $found->codeChallenge);
        $this->assertSame($transaction->createdAt, $found->createdAt);
    }

    /**
     * 2. Prove unknown/altered state returns null.
     */
    public function test_unknown_or_altered_state_returns_null(): void
    {
        $transaction = $this->makeTransaction('state-original');
        $this->store->store($transaction);

        $this->assertNull($this->store->find('state-altered'));
        $this->assertNull($this->store->find(''));
        $this->assertNull($this->store->find('non-existent'));
    }

    /**
     * 3. Prove expired transaction returns null.
     */
    public function test_expired_transaction_returns_null(): void
    {
        $expiredTransaction = $this->makeTransaction('state-expired', time() - 301);
        $this->store->store($expiredTransaction);

        $found = $this->store->find('state-expired');

        $this->assertNull($found);
    }

    /**
     * 4. Prove expired transaction is removed on find.
     */
    public function test_expired_transaction_is_removed_on_find(): void
    {
        $expiredTransaction = $this->makeTransaction('state-stale', time() - 350);
        $this->store->store($expiredTransaction);

        // Before find, raw session contains the record
        $rawBefore = $this->session->get(OidcTransactionStore::SESSION_KEY);
        $this->assertArrayHasKey('state-stale', $rawBefore);

        // find() triggers removal of expired record
        $result = $this->store->find('state-stale');
        $this->assertNull($result);

        // After find, raw session no longer contains the record
        $rawAfter = $this->session->get(OidcTransactionStore::SESSION_KEY);
        $this->assertArrayNotHasKey('state-stale', $rawAfter);
    }

    /**
     * 5. Prove consume returns valid transaction once.
     */
    public function test_consume_returns_valid_transaction_once(): void
    {
        $transaction = $this->makeTransaction('state-consume');
        $this->store->store($transaction);

        $consumed = $this->store->consume('state-consume');

        $this->assertNotNull($consumed);
        $this->assertSame('state-consume', $consumed->state);
    }

    /**
     * 6. Prove second sequential consume returns null (single-use anti-replay).
     */
    public function test_second_sequential_consume_returns_null(): void
    {
        $transaction = $this->makeTransaction('state-single-use');
        $this->store->store($transaction);

        $first = $this->store->consume('state-single-use');
        $this->assertNotNull($first);

        $second = $this->store->consume('state-single-use');
        $this->assertNull($second);
    }

    /**
     * 7. Prove state-A and state-B coexist in the same session.
     */
    public function test_state_a_and_state_b_coexist(): void
    {
        $txA = $this->makeTransaction('state-A');
        $txB = $this->makeTransaction('state-B');

        $this->store->store($txA);
        $this->store->store($txB);

        $foundA = $this->store->find('state-A');
        $foundB = $this->store->find('state-B');

        $this->assertNotNull($foundA);
        $this->assertNotNull($foundB);
        $this->assertSame('state-A', $foundA->state);
        $this->assertSame('state-B', $foundB->state);
    }

    /**
     * 8. Prove consuming A leaves B intact.
     */
    public function test_consuming_a_leaves_b_intact(): void
    {
        $txA = $this->makeTransaction('state-A');
        $txB = $this->makeTransaction('state-B');

        $this->store->store($txA);
        $this->store->store($txB);

        $consumedA = $this->store->consume('state-A');
        $this->assertNotNull($consumedA);
        $this->assertSame('state-A', $consumedA->state);

        // B must remain intact and valid
        $foundB = $this->store->find('state-B');
        $this->assertNotNull($foundB);
        $this->assertSame('state-B', $foundB->state);

        $consumedB = $this->store->consume('state-B');
        $this->assertNotNull($consumedB);
        $this->assertSame('state-B', $consumedB->state);
    }

    /**
     * 9. Prove storing a new transaction prunes expired transactions.
     */
    public function test_storing_a_new_transaction_prunes_expired_transactions(): void
    {
        // Directly put an expired transaction into session
        $expiredData = [
            'state' => 'state-old',
            'nonce' => 'nonce-old',
            'code_verifier' => 'verifier-old',
            'code_challenge' => 'challenge-old',
            'created_at' => time() - 400,
        ];
        $this->session->put(OidcTransactionStore::SESSION_KEY, [
            'state-old' => $expiredData,
        ]);

        // Store a new valid transaction
        $newTx = $this->makeTransaction('state-new');
        $this->store->store($newTx);

        // Stored session array should now only have 'state-new'
        $all = $this->session->get(OidcTransactionStore::SESSION_KEY);
        $this->assertArrayNotHasKey('state-old', $all);
        $this->assertArrayHasKey('state-new', $all);
    }

    /**
     * 10. Prove malformed session transaction fails closed.
     */
    public function test_malformed_session_transaction_fails_closed(): void
    {
        // Missing required fields
        $this->session->put(OidcTransactionStore::SESSION_KEY, [
            'state-corrupted' => [
                'state' => 'state-corrupted',
                // missing nonce, verifier, etc.
            ],
            'state-not-array' => 'invalid-string-data',
        ]);

        $this->assertNull($this->store->find('state-corrupted'));
        $this->assertNull($this->store->find('state-not-array'));
        $this->assertNull($this->store->consume('state-corrupted'));
        $this->assertNull($this->store->consume('state-not-array'));
    }
}
