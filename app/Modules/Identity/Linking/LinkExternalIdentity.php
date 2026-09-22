<?php

namespace App\Modules\Identity\Linking;

use App\Models\User;
use App\Modules\Identity\Models\ExternalIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LinkExternalIdentity
{
    /**
     * Storage capacity limit for issuer and subject columns (VARCHAR 255).
     */
    private const MAX_COLUMN_LENGTH = 255;

    /**
     * Execute controlled identity linking for an existing local user.
     *
     * @param int $userId
     * @param string $subject
     * @return ExternalIdentity
     *
     * @throws IdentityLinkException
     */
    public function execute(int $userId, string $subject): ExternalIdentity
    {
        $issuer = $this->resolveAndValidateIssuer();
        $this->validateSubject($subject);

        return DB::transaction(function () use ($userId, $issuer, $subject) {
            if (! User::where('id', $userId)->exists()) {
                throw IdentityLinkException::targetUserNotFound();
            }

            $existing = $this->findExternalIdentity($issuer, $subject);

            if ($existing !== null) {
                // Strict PHP equality defense against database collation case variants
                if ($existing->issuer !== $issuer || $existing->subject !== $subject) {
                    throw IdentityLinkException::trustKeyConflict();
                }

                // Idempotent: already linked to the same user
                if ((int) $existing->user_id === $userId) {
                    return $existing;
                }

                // Conflict: already linked to another user
                throw IdentityLinkException::trustKeyConflict();
            }

            try {
                return ExternalIdentity::create([
                    'user_id' => $userId,
                    'provider' => 'keycloak',
                    'issuer' => $issuer,
                    'subject' => $subject,
                    'email_at_link' => null,
                    'linked_at' => now(),
                    'last_login_at' => null,
                ]);
            } catch (QueryException) {
                throw IdentityLinkException::trustKeyConflict();
            }
        });
    }

    /**
     * Resolve and strictly validate configured OIDC issuer.
     */
    protected function resolveAndValidateIssuer(): string
    {
        $issuer = config('oidc.issuer');

        if (! is_string($issuer) || trim($issuer) === '' || strlen($issuer) > self::MAX_COLUMN_LENGTH) {
            throw IdentityLinkException::invalidIssuer();
        }

        if (filter_var($issuer, FILTER_VALIDATE_URL) === false) {
            throw IdentityLinkException::invalidIssuer();
        }

        $scheme = parse_url($issuer, PHP_URL_SCHEME);
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw IdentityLinkException::invalidIssuer();
        }

        $host = parse_url($issuer, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw IdentityLinkException::invalidIssuer();
        }

        return $issuer;
    }

    /**
     * Strictly validate the opaque external identity subject.
     */
    protected function validateSubject(string $subject): void
    {
        if ($subject === '' || trim($subject) === '' || strlen($subject) > self::MAX_COLUMN_LENGTH) {
            throw IdentityLinkException::invalidSubject();
        }
    }

    /**
     * Lookup existing ExternalIdentity by permanent trust key (issuer, subject).
     */
    protected function findExternalIdentity(string $issuer, string $subject): ?ExternalIdentity
    {
        return ExternalIdentity::where('issuer', $issuer)
            ->where('subject', $subject)
            ->first();
    }
}
