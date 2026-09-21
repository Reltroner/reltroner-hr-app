<?php

namespace Tests\Fixtures\Redis;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class TestSuccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $markerKey, public string $markerValue)
    {
    }

    public function handle(): void
    {
        Cache::store('redis')->put($this->markerKey, $this->markerValue, 60);
    }
}
