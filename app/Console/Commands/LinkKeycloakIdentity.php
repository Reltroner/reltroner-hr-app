<?php

namespace App\Console\Commands;

use App\Modules\Identity\Linking\IdentityLinkException;
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
     *
     * @param  LinkExternalIdentity  $linker
     * @return int
     */
    public function handle(LinkExternalIdentity $linker): int
    {
        $rawUserId = $this->argument('user_id');

        if (! is_string($rawUserId) && ! is_int($rawUserId)) {
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $userIdString = (string) $rawUserId;

        if (! preg_match('/^[1-9][0-9]*$/', $userIdString)) {
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
            $this->line('result=failed');
            $this->line('reason=invalid_user_id');

            return self::FAILURE;
        }

        $subject = (string) $this->argument('subject');
        $isDryRun = (bool) $this->option('dry-run');
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
                $identity->issuer . "\0" . $identity->subject
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

            $this->line('result=failed');
            $this->line("reason={$e->getReason()}");

            return self::FAILURE;
        } catch (Throwable) {
            if ($startedTransaction && DB::transactionLevel() > 0) {
                DB::rollBack();
                $startedTransaction = false;
            }

            $this->line('result=failed');
            $this->line('reason=unexpected_failure');

            return self::FAILURE;
        }
    }
}
