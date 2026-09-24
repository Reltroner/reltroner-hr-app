<?php

namespace App\Modules\Identity\Linking;

use RuntimeException;
use Throwable;

class IdentityUnlinkException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $reason,
        protected ?string $fingerprint = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public static function targetIdentityNotFound(): self
    {
        return new self('Target external identity not found.', 'target_identity_not_found');
    }

    public static function userMismatch(): self
    {
        return new self('External identity user mismatch.', 'user_mismatch');
    }

    public static function providerMismatch(): self
    {
        return new self('External identity provider mismatch.', 'provider_mismatch');
    }

    public static function issuerMismatch(): self
    {
        return new self('External identity issuer mismatch.', 'issuer_mismatch');
    }

    public static function confirmationRequired(?string $fingerprint = null): self
    {
        return new self('Fingerprint confirmation is required for execution.', 'confirmation_required', $fingerprint);
    }

    public static function invalidConfirmation(?string $fingerprint = null): self
    {
        return new self('Supplied confirmation fingerprint is invalid.', 'invalid_confirmation', $fingerprint);
    }

    public static function confirmationMismatch(?string $fingerprint = null): self
    {
        return new self('Confirmation fingerprint does not match target identity.', 'confirmation_mismatch', $fingerprint);
    }

    public static function deleteFailed(?string $fingerprint = null): self
    {
        return new self('External identity deletion was rejected.', 'delete_failed', $fingerprint);
    }
}
