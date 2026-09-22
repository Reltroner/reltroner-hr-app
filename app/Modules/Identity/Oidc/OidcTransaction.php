<?php

namespace App\Modules\Identity\Oidc;

/**
 * Immutable Data Transfer Object representing a transient server-side OIDC transaction.
 *
 * Contains strictly the cryptographic parameters and creation timestamp required
 * for authorization flow verification and PKCE exchange.
 */
readonly class OidcTransaction
{
    public function __construct(
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
        public int $createdAt,
    ) {
    }

    /**
     * Create an OidcTransaction instance from a serialized session array.
     * Fails closed (returns null) if any required key is missing or invalid.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (
            !isset($data['state'], $data['nonce'], $data['code_verifier'], $data['code_challenge'], $data['created_at'])
            || !is_string($data['state']) || $data['state'] === ''
            || !is_string($data['nonce']) || $data['nonce'] === ''
            || !is_string($data['code_verifier']) || $data['code_verifier'] === ''
            || !is_string($data['code_challenge']) || $data['code_challenge'] === ''
            || !is_int($data['created_at'])
        ) {
            return null;
        }

        return new self(
            state: $data['state'],
            nonce: $data['nonce'],
            codeVerifier: $data['code_verifier'],
            codeChallenge: $data['code_challenge'],
            createdAt: $data['created_at'],
        );
    }

    /**
     * Convert the transaction to a plain associative array for session storage.
     *
     * @return array{state: string, nonce: string, code_verifier: string, code_challenge: string, created_at: int}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'code_challenge' => $this->codeChallenge,
            'created_at' => $this->createdAt,
        ];
    }
}
