<?php

namespace App\Modules\Identity\Oidc;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class OidcLogoutContext
{
    public const SESSION_KEY = 'identity.oidc_logout';

    public const ENCRYPTED_ID_TOKEN_HINT_KEY = 'encrypted_id_token_hint';

    public static function store(
        Request $request,
        #[\SensitiveParameter] string $idTokenHint
    ): void {
        if (trim($idTokenHint) === '') {
            throw new InvalidArgumentException('OIDC logout hint must not be empty.');
        }

        $request->session()->put(self::SESSION_KEY, [
            self::ENCRYPTED_ID_TOKEN_HINT_KEY => Crypt::encryptString($idTokenHint),
        ]);
    }

    public static function pullIdTokenHint(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $payload = $request->session()->pull(self::SESSION_KEY);

        if ($payload === null) {
            return null;
        }

        if (
            ! is_array($payload)
            || array_keys($payload) !== [self::ENCRYPTED_ID_TOKEN_HINT_KEY]
            || ! isset($payload[self::ENCRYPTED_ID_TOKEN_HINT_KEY])
            || ! is_string($payload[self::ENCRYPTED_ID_TOKEN_HINT_KEY])
            || trim($payload[self::ENCRYPTED_ID_TOKEN_HINT_KEY]) === ''
        ) {
            Log::warning('oidc.logout_context.invalid');

            return null;
        }

        try {
            $idTokenHint = Crypt::decryptString(
                $payload[self::ENCRYPTED_ID_TOKEN_HINT_KEY]
            );
        } catch (DecryptException) {
            Log::warning('oidc.logout_context.invalid');

            return null;
        }

        if (trim($idTokenHint) === '') {
            Log::warning('oidc.logout_context.invalid');

            return null;
        }

        return $idTokenHint;
    }

    public static function has(Request $request): bool
    {
        return $request->hasSession()
            && $request->session()->has(self::SESSION_KEY);
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
