<?php

namespace App\Modules\Identity\Linking;

use Illuminate\Support\Facades\Log;
use Throwable;

class IdentityLinkFailureReporter
{
    /**
     * Report an expected controlled identity link or unlink failure.
     */
    public function reportExpected(
        string $operation,
        string $reason,
        ?int $userId = null,
        ?string $trustKeyFingerprint = null,
        bool $dryRun = false,
        string $actorType = 'system'
    ): void {
        $eventName = $operation === 'unlink' ? 'identity.unlink.failed' : 'identity.link.failed';

        Log::warning($eventName, [
            'operation' => $operation,
            'reason' => $reason,
            'user_id' => $userId,
            'trust_key_fingerprint' => $trustKeyFingerprint,
            'dry_run' => $dryRun,
            'actor_type' => $actorType,
        ]);
    }

    /**
     * Report an unexpected controlled identity link or unlink failure.
     */
    public function reportUnexpected(
        string $operation,
        Throwable $exception,
        ?int $userId = null,
        ?string $trustKeyFingerprint = null,
        bool $dryRun = false,
        string $actorType = 'system'
    ): void {
        $eventName = $operation === 'unlink' ? 'identity.unlink.failed' : 'identity.link.failed';

        Log::error($eventName, [
            'operation' => $operation,
            'reason' => 'unexpected_failure',
            'user_id' => $userId,
            'trust_key_fingerprint' => $trustKeyFingerprint,
            'dry_run' => $dryRun,
            'actor_type' => $actorType,
            'exception_class' => get_class($exception),
        ]);
    }
}
