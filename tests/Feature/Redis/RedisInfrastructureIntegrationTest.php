<?php

namespace Tests\Feature\Redis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\Fixtures\Redis\TestFailingJob;
use Tests\Fixtures\Redis\TestSuccessJob;
use Tests\TestCase;

class RedisInfrastructureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $gate = env('RUN_REDIS_INTEGRATION');
        if ($gate !== true && $gate !== 'true') {
            $this->markTestSkipped('Redis integration tests are skipped because RUN_REDIS_INTEGRATION is not enabled.');
        }

        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->fail('The phpredis PHP extension is required for Redis integration tests, but is not loaded.');
        }

        try {
            Redis::connection('default')->ping();
        } catch (\Throwable $e) {
            $this->fail('Redis connection [default] failed: ' . $e->getMessage());
        }
    }

    /**
     * A. Prove Laravel Redis connectivity and logical DB separation across connections.
     */
    public function test_laravel_redis_named_connections_and_logical_database_separation(): void
    {
        $connections = ['default', 'cache', 'queue', 'session'];

        foreach ($connections as $name) {
            try {
                $pong = Redis::connection($name)->ping();
                $this->assertTrue(
                    $pong === true || $pong === '+PONG' || $pong === 'PONG',
                    "Named Redis connection [{$name}] ping did not return expected response."
                );
            } catch (\Throwable $e) {
                $this->fail("Named Redis connection [{$name}] failed to ping: " . $e->getMessage());
            }
        }

        $probeKey = 'probe_db_isolation_' . bin2hex(random_bytes(8));

        try {
            Redis::connection('default')->set($probeKey, 'db0');
            Redis::connection('cache')->set($probeKey, 'db1');
            Redis::connection('queue')->set($probeKey, 'db2');
            Redis::connection('session')->set($probeKey, 'db3');

            $this->assertSame('db0', Redis::connection('default')->get($probeKey));
            $this->assertSame('db1', Redis::connection('cache')->get($probeKey));
            $this->assertSame('db2', Redis::connection('queue')->get($probeKey));
            $this->assertSame('db3', Redis::connection('session')->get($probeKey));
        } finally {
            Redis::connection('default')->del($probeKey);
            Redis::connection('cache')->del($probeKey);
            Redis::connection('queue')->del($probeKey);
            Redis::connection('session')->del($probeKey);
        }
    }

    /**
     * B. Prove Redis-backed cache operations through Laravel Cache facade.
     */
    public function test_redis_backed_cache_store_operations(): void
    {
        config(['cache.default' => 'redis']);

        $key = 'test_cache_' . bin2hex(random_bytes(6));
        $val = 'cache_val_' . bin2hex(random_bytes(8));

        try {
            Cache::store('redis')->put($key, $val, 60);
            $this->assertSame($val, Cache::store('redis')->get($key));

            Cache::store('redis')->forget($key);
            $this->assertNull(Cache::store('redis')->get($key));
        } finally {
            Cache::store('redis')->forget($key);
        }
    }

    /**
     * C. Prove Redis-backed session persistence across simulated HTTP requests.
     */
    public function test_redis_backed_session_persistence_across_http_requests(): void
    {
        config([
            'session.driver' => 'redis',
            'session.connection' => 'session',
        ]);

        $sessionKey = 'integration_session_' . bin2hex(random_bytes(4));
        $sessionValue = 'session_val_' . bin2hex(random_bytes(8));

        Route::middleware('web')->get('/_test_redis_session_put', function (Request $request) use ($sessionKey, $sessionValue) {
            $request->session()->put($sessionKey, $sessionValue);
            return response('session_saved');
        });

        Route::middleware('web')->get('/_test_redis_session_get', function (Request $request) use ($sessionKey) {
            return response((string) $request->session()->get($sessionKey, 'session_not_found'));
        });

        $res1 = $this->get('/_test_redis_session_put');
        $res1->assertOk()->assertSee('session_saved');

        $cookieName = config('session.cookie');
        $sessionCookie = $res1->getCookie($cookieName);
        $this->assertNotNull($sessionCookie, 'Session cookie must be present in response.');

        $res2 = $this->withCookie($cookieName, $sessionCookie->getValue())
            ->get('/_test_redis_session_get');

        $res2->assertOk()->assertSee($sessionValue);
    }

    /**
     * D. Prove Redis queue success execution via deterministic single-pass worker.
     */
    public function test_redis_queue_job_dispatch_and_successful_processing(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
        ]);

        $queueName = 'int_test_success_' . bin2hex(random_bytes(4));
        $markerKey = 'marker_' . bin2hex(random_bytes(6));
        $markerValue = 'success_' . bin2hex(random_bytes(8));

        try {
            TestSuccessJob::dispatch($markerKey, $markerValue)
                ->onConnection('redis')
                ->onQueue($queueName);

            $exitCode = Artisan::call('queue:work', [
                'connection' => 'redis',
                '--queue' => $queueName,
                '--once' => true,
                '--tries' => 1,
                '--stop-when-empty' => true,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertSame($markerValue, Cache::store('redis')->get($markerKey));
        } finally {
            Cache::store('redis')->forget($markerKey);
            Artisan::call('queue:clear', [
                'connection' => 'redis',
                '--queue' => $queueName,
                '--force' => true,
            ]);
        }
    }

    /**
     * E. Prove Redis queue failure persists to failed_jobs and emits structured logging.
     */
    public function test_redis_queue_job_failure_persists_and_emits_structured_logging(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queue',
            'queue.failed.driver' => 'database-uuids',
        ]);

        $queueName = 'int_test_fail_' . bin2hex(random_bytes(4));

        Log::spy();

        try {
            TestFailingJob::dispatch('intentional_failure')
                ->onConnection('redis')
                ->onQueue($queueName);

            Artisan::call('queue:work', [
                'connection' => 'redis',
                '--queue' => $queueName,
                '--once' => true,
                '--tries' => 1,
                '--stop-when-empty' => true,
            ]);

            $this->assertDatabaseHas('failed_jobs', [
                'connection' => 'redis',
                'queue' => $queueName,
            ]);

            Log::shouldHaveReceived('error')
                ->with('Queue job failed', Mockery::on(function ($context) use ($queueName) {
                    $this->assertIsArray($context);
                    $this->assertSame('redis', $context['connection'] ?? null);
                    $this->assertSame($queueName, $context['queue'] ?? null);
                    $this->assertSame(TestFailingJob::class, $context['job'] ?? null);
                    $this->assertSame(1, $context['attempts'] ?? null);
                    $this->assertSame(RuntimeException::class, $context['exception_class'] ?? null);
                    $this->assertNotEmpty($context['job_id'] ?? null);
                    $this->assertNotEmpty($context['exception_file'] ?? null);
                    $this->assertNotEmpty($context['exception_line'] ?? null);
                    $this->assertArrayNotHasKey('exception_message', $context);
                    $this->assertArrayNotHasKey('payload', $context);
                    return true;
                }))
                ->once();
        } finally {
            DB::table('failed_jobs')->where('queue', $queueName)->delete();
            Artisan::call('queue:clear', [
                'connection' => 'redis',
                '--queue' => $queueName,
                '--force' => true,
            ]);
        }
    }
}
