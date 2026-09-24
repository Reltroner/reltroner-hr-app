<?php

namespace App\Console\Commands;

use App\Modules\Identity\Linking\IdentityLinkException;
use App\Modules\Identity\Linking\IdentityLinkFailureReporter;
use App\Modules\Identity\Linking\LinkExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class LinkKeycloakIdentity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'identity:link-keycloak
        {user_id : Existing local User ID}
        {subject : Exact Keycloak subject}
        {--dry-run : Validate the operation without persisting a link}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Explicitly link an existing local user to a Keycloak identity';

    /**
     * Execute the console command.
     */
    public function handle(LinkExternalIdentity $linker, IdentityLinkFailureReporter $reporter): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $rawUserId = $this->argument('user_id');

        if (! is_string($rawUserId) && ! is_int($rawUserId)) {
            $reporter->reportExpected('link', 'invalid_user_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $userIdString = (string) $rawUserId;

        if (! preg_match('/^[1-9][0-9]*$/', $userIdString)) {
            $reporter->reportExpected('link', 'invalid_user_id', null, null, $isDryRun);
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
            $reporter->reportExpected('link', 'invalid_user_id', null, null, $isDryRun);
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $rawSubject = $this->argument('subject');
        $subject = is_string($rawSubject) ? $rawSubject : '';
        $startedTransaction = false;

        try {
            if ($isDryRun) {
                DB::beginTransaction();
                $startedTransaction = true;
            }

            $identity = $linker->execute($userId, $subject);

            $wasRecentlyCreated = $identity->wasRecentlyCreated;

            $fingerprint = hash(
                'sha256',
                $identity->issuer."\0".$identity->subject
            );

            $identityId = (int) $identity->getKey();
            $identityUserId = (int) $identity->user_id;

            if ($isDryRun) {
                if ($startedTransaction && DB::transactionLevel() > 0) {
                    DB::rollBack();
                    $startedTransaction = false;
                }

                $result = $wasRecentlyCreated
                    ? 'would_link'
                    : 'already_linked';

                $this->line("result={$result}");
                $this->line("user_id={$identityUserId}");

                if (! $wasRecentlyCreated) {
                    $this->line("external_identity_id={$identityId}");
                }

                $this->line("trust_key_fingerprint={$fingerprint}");
                $this->line('dry_run=true');

                return self::SUCCESS;
            }

            $result = $wasRecentlyCreated
                ? 'linked'
                : 'already_linked';

            $this->line("result={$result}");
            $this->line("user_id={$identityUserId}");
            $this->line("external_identity_id={$identityId}");
            $this->line("trust_key_fingerprint={$fingerprint}");
            $this->line('dry_run=false');

            return self::SUCCESS;
        } catch (IdentityLinkException $e) {
            if ($startedTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
                $startedTransaction = false;
            }

            $fingerprint = $this->computeSafeFingerprint(config('oidc.issuer'), $rawSubject);
            $reporter->reportExpected('link', $e->getReason(), $userId, $fingerprint, $isDryRun);

            $this->line('result=failed');
            $this->line("reason={$e->getReason()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            if ($startedTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
                $startedTransaction = false;
            }

            $fingerprint = $this->computeSafeFingerprint(config('oidc.issuer'), $rawSubject);
            $reporter->reportUnexpected('link', $e, $userId, $fingerprint, $isDryRun);

            $this->line('result=failed');
            $this->line('reason=unexpected_failure');

            return self::FAILURE;
        }
    }

    /**
     * Compute trust-key fingerprint if and only if issuer and subject are valid strings.
     */
    protected function computeSafeFingerprint(mixed $issuer, mixed $subject): ?string
    {
        if (! is_string($issuer) || trim($issuer) === '' || strlen($issuer) > 255 || filter_var($issuer, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($issuer, PHP_URL_SCHEME);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        $host = parse_url($issuer, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (! is_string($subject) || trim($subject) === '' || strlen($subject) > 255) {
            return null;
        }

        return hash('sha256', $issuer."\0".$subject);
    }
}
