<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Identity\Events\IdentityLinkAuditEvent;
use App\Modules\Identity\Listeners\LogIdentityLinkAudit;
use App\Modules\Identity\Models\ExternalIdentity;
use App\Modules\Identity\Observers\ExternalIdentityAuditObserver;
use App\Modules\Identity\Observers\UserIdentityLinkAuditObserver;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enable Vite prefetch with concurrency control
        Vite::prefetch(concurrency: 3);

        // Optional: support hot module replacement during local dev
        if ($this->app->environment('local')) {
            Vite::useHotFile(public_path('hot'));
        }

        if (env('APP_ENV') === 'production') {
            URL::forceScheme('https');
        }

        Queue::failing(function (JobFailed $event) {
            Log::error('Queue job failed', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'attempts' => $event->job->attempts(),
                'exception_class' => get_class($event->exception),
                'exception_file' => $event->exception->getFile(),
                'exception_line' => $event->exception->getLine(),
            ]);
        });

        ExternalIdentity::observe(ExternalIdentityAuditObserver::class);
        User::observe(UserIdentityLinkAuditObserver::class);

        Event::listen(
            IdentityLinkAuditEvent::class,
            LogIdentityLinkAudit::class
        );
    }
}
