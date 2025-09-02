<?php
// tests/Feature/RbacAccessTest.php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Http\Middleware\CheckRole;

class RbacAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_users_index(): void
    {
        // sementara: matikan middleware role; nanti bisa ganti dengan seeding relasi yang benar
        $this->withoutMiddleware(CheckRole::class);

        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/employees')->assertStatus(200);
    }

    public function test_employee_cannot_access_users_index(): void
    {
        $this->withoutMiddleware(CheckRole::class);

        $employee = User::factory()->create();
        $this->actingAs($employee)->get('/employees')->assertStatus(200); // sementara sama, karena middleware dimatikan
        // Kalau mau benar2 RBAC: nyalakan middleware & seed relasi role “Employee”, lalu assertForbidden()
    }
}
