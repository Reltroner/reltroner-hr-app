<?php
// tests/Feature/SmokeRoutesTest.php
namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;
use App\Http\Middleware\CheckRole; // ⬅️ ganti import

class SmokeRoutesTest extends TestCase
{
    public function test_critical_pages_load_for_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        // Matikan middleware role yang bener
        $this->withoutMiddleware(CheckRole::class);
        // Atau: $this->withoutMiddleware('role');  // kalau alias terdaftar di Kernel

        $routes = [
            '/dashboard',
            '/employees',
            '/tasks',
            '/presences',
            '/payrolls',
            '/leave_requests',
        ];

        foreach ($routes as $r) {
            $this->get($r)->assertSuccessful();
        }
    }
}
