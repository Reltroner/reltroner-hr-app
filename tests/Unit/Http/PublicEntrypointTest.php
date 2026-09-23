<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

class PublicEntrypointTest extends TestCase
{
    public function test_public_entrypoint_conforms_to_canonical_single_dispatch_lifecycle(): void
    {
        $indexPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php';

        $this->assertFileExists($indexPath, 'public/index.php must exist.');

        $content = file_get_contents($indexPath);

        // 1. Exactly one occurrence of: handleRequest(Request::capture())
        $normalizedContent = preg_replace('/\s+/', ' ', $content);
        $this->assertSame(
            1,
            substr_count($normalizedContent, 'handleRequest(Request::capture())'),
            'public/index.php must contain exactly one occurrence of handleRequest(Request::capture()).'
        );

        // 2. No: use Illuminate\Contracts\Http\Kernel;
        $this->assertStringNotContainsString(
            'use Illuminate\Contracts\Http\Kernel;',
            $content,
            'public/index.php must not import Illuminate\Contracts\Http\Kernel.'
        );

        // 3. No: $app->make(Kernel::class)
        $this->assertStringNotContainsString(
            '$app->make(Kernel::class)',
            $content,
            'public/index.php must not manually resolve Kernel::class.'
        );

        // 4. No: $kernel->handle(
        $this->assertStringNotContainsString(
            '$kernel->handle(',
            $content,
            'public/index.php must not manually invoke $kernel->handle().'
        );

        // 5. No: $kernel->terminate(
        $this->assertStringNotContainsString(
            '$kernel->terminate(',
            $content,
            'public/index.php must not manually invoke $kernel->terminate().'
        );
    }
}
