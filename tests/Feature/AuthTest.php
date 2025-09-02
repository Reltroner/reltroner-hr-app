<?php
// tests/Feature/AuthTest.php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $resp = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $resp->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }
}
