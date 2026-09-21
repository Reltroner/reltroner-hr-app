<?php

namespace Tests\Fixtures\Redis;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class TestFailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $marker = 'intentional_queue_failure')
    {
    }

    public function handle(): void
    {
        throw new RuntimeException('Intentional integration test queue failure');
    }
}
