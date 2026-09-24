<?php

namespace App\Modules\Identity\Linking;

use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Support\Facades\DB;

class UnlinkExternalIdentity
{
    /**
     * Inspect and validate external identity for dry-run without mutation.
     *
     * @return array{external_identity_id: int, user_id: int, trust_key_fingerprint: string}
     *
     * @throws IdentityUnlinkException
     */
    public function inspect(int $externalIdentityId, int $userId): array
    {
        $target = ExternalIdentity::find($externalIdentityId);

        if ($target === null) {
            throw IdentityUnlinkException::targetIdentityNotFound();
        }

        if ((int) $target->user_id !== $userId) {
            throw IdentityUnlinkException::userMismatch();
        }

        if ($target->provider !== 'keycloak') {
            throw IdentityUnlinkException::providerMismatch();
        }

        $expectedIssuer = config('oidc.issuer');
        if (! is_string($expectedIssuer) || $target->issuer !== $expectedIssuer) {
            throw IdentityUnlinkException::issuerMismatch();
        }

        $fingerprint = hash('sha256', $target->issuer."\0".$target->subject);

        return [
            'external_identity_id' => (int) $target->getKey(),
            'user_id' => (int) $target->user_id,
            'trust_key_fingerprint' => $fingerprint,
        ];
    }

    /**
     * Execute controlled unlink mutation inside a database transaction with a row lock.
     *
     * @return array{external_identity_id: int, user_id: int, trust_key_fingerprint: string}
     *
     * @throws IdentityUnlinkException
     */
    public function execute(int $externalIdentityId, int $userId, ?string $confirmFingerprint): array
    {
        return DB::transaction(function () use ($externalIdentityId, $userId, $confirmFingerprint) {
            /** @var ExternalIdentity|null $target */
            $target = ExternalIdentity::where('id', $externalIdentityId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                throw IdentityUnlinkException::targetIdentityNotFound();
            }

            if ((int) $target->user_id !== $userId) {
                throw IdentityUnlinkException::userMismatch();
            }

            if ($target->provider !== 'keycloak') {
                throw IdentityUnlinkException::providerMismatch();
            }

            $expectedIssuer = config('oidc.issuer');
            if (! is_string($expectedIssuer) || $target->issuer !== $expectedIssuer) {
                throw IdentityUnlinkException::issuerMismatch();
            }

            $actualFingerprint = hash('sha256', $target->issuer."\0".$target->subject);

            if ($confirmFingerprint === null || trim($confirmFingerprint) === '') {
                throw IdentityUnlinkException::confirmationRequired($actualFingerprint);
            }

            if (! preg_match('/^[a-f0-9]{64}$/', $confirmFingerprint)) {
                throw IdentityUnlinkException::invalidConfirmation($actualFingerprint);
            }

            if (! hash_equals($confirmFingerprint, $actualFingerprint)) {
                throw IdentityUnlinkException::confirmationMismatch($actualFingerprint);
            }

            $identityId = (int) $target->getKey();
            $targetUserId = (int) $target->user_id;

            // Eloquent delete preserves model observers (ExternalIdentityAuditObserver)
            $deleted = $target->delete();

            if ($deleted !== true) {
                throw IdentityUnlinkException::deleteFailed($actualFingerprint);
            }

            return [
                'external_identity_id' => $identityId,
                'user_id' => $targetUserId,
                'trust_key_fingerprint' => $actualFingerprint,
            ];
        });
    }
}
