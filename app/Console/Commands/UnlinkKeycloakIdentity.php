<?php

namespace App\Console\Commands;

use App\Modules\Identity\Linking\IdentityLinkFailureReporter;
use App\Modules\Identity\Linking\IdentityUnlinkException;
use App\Modules\Identity\Linking\UnlinkExternalIdentity;
use Illuminate\Console\Command;
use Throwable;

class UnlinkKeycloakIdentity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'identity:unlink-keycloak
        {external_identity_id : Target ExternalIdentity ID}
        {user_id : Expected local User ID}
        {--confirm-fingerprint= : Exact 64-hex SHA-256 fingerprint from reviewed dry-run}
        {--dry-run : Validate the operation without deleting the link}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Explicitly unlink an existing local user from a Keycloak identity';

    /**
     * Execute the console command.
     */
    public function handle(UnlinkExternalIdentity $unlinker, IdentityLinkFailureReporter $reporter): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $rawExternalId = $this->argument('external_identity_id');

        if (! is_string($rawExternalId) && ! is_int($rawExternalId)) {
            $reporter->reportExpected('unlink', 'invalid_external_identity_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_external_identity_id');

            return self::FAILURE;
        }

        $externalIdString = (string) $rawExternalId;

        if (! preg_match('/^[1-9][0-9]*$/', $externalIdString)) {
            $reporter->reportExpected('unlink', 'invalid_external_identity_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_external_identity_id');

            return self::FAILURE;
        }

        $externalIdentityId = filter_var(
            $externalIdString,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($externalIdentityId === false) {
            $reporter->reportExpected('unlink', 'invalid_external_identity_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_external_identity_id');

            return self::FAILURE;
        }

        $rawUserId = $this->argument('user_id');

        if (! is_string($rawUserId) && ! is_int($rawUserId)) {
            $reporter->reportExpected('unlink', 'invalid_user_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $userIdString = (string) $rawUserId;

        if (! preg_match('/^[1-9][0-9]*$/', $userIdString)) {
            $reporter->reportExpected('unlink', 'invalid_user_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $userId = filter_var(
            $userIdString,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($userId === false) {
            $reporter->reportExpected('unlink', 'invalid_user_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $confirmFingerprint = $this->option('confirm-fingerprint');

        try {
            if ($isDryRun) {
                $details = $unlinker->inspect($externalIdentityId, $userId);

                $this->line('result=would_unlink');
                $this->line("user_id={$details['user_id']}");
                $this->line("external_identity_id={$details['external_identity_id']}");
                $this->line("trust_key_fingerprint={$details['trust_key_fingerprint']}");
                $this->line('dry_run=true');

                return self::SUCCESS;
            }

            $details = $unlinker->execute(
                $externalIdentityId,
                $userId,
                is_string($confirmFingerprint) ? $confirmFingerprint : null
            );

            $this->line('result=unlinked');
            $this->line("user_id={$details['user_id']}");
            $this->line("external_identity_id={$details['external_identity_id']}");
            $this->line("trust_key_fingerprint={$details['trust_key_fingerprint']}");
            $this->line('dry_run=false');

            return self::SUCCESS;
        } catch (IdentityUnlinkException $e) {
            $reporter->reportExpected('unlink', $e->getReason(), $userId, $e->getFingerprint(), $isDryRun);

            $this->line('result=failed');
            $this->line("reason={$e->getReason()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $reporter->reportUnexpected('unlink', $e, $userId, null, $isDryRun);

            $this->line('result=failed');
            $this->line('reason=unexpected_failure');

            return self::FAILURE;
        }
    }
}
