<?php

namespace App\Modules\Identity\Oidc;

readonly class OidcCallbackResult
{
    private string $idTokenHint;

    public function __construct(
        public ResolvedOidcIdentity $resolvedIdentity,
        #[\SensitiveParameter] string $idTokenHint
    ) {
        $this->idTokenHint = $idTokenHint;
    }

    public function idTokenHint(): string
    {
        return $this->idTokenHint;
    }
}
