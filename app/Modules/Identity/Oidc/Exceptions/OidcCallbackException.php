<?php

namespace App\Modules\Identity\Oidc\Exceptions;

use RuntimeException;
use Throwable;

class OidcCallbackException extends RuntimeException
{
    public function __construct(
        protected int $statusCode,
        protected string $category,
        string $userFacingMessage,
        ?Throwable $previous = null
    ) {
        parent::__construct($userFacingMessage, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getUserFacingMessage(): string
    {
        return $this->getMessage();
    }
}
