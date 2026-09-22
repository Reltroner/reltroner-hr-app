<?php

namespace App\Modules\Identity\Linking;

use RuntimeException;
use Throwable;

class IdentityLinkException extends RuntimeException
{
    public function __construct(
        string $message,
        protected string $reason,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public static function targetUserNotFound(): self
    {
        return new self('Target user not found.', 'target_user_not_found');
    }

    public static function invalidIssuer(): self
    {
        return new self('Configured OIDC issuer is invalid.', 'invalid_issuer');
    }

    public static function invalidSubject(): self
    {
        return new self('Supplied external identity subject is invalid.', 'invalid_subject');
    }

    public static function trustKeyConflict(): self
    {
        return new self('External identity trust key conflict.', 'trust_key_conflict');
    }
}
