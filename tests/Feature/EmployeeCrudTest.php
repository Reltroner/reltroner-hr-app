<?php
// tests/Feature/EmployeeCrudTest.php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Department;
use App\Models\Role;
use App\Http\Middleware\CheckRole;

class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_employee(): void
    {
        $this->withoutMiddleware(CheckRole::class);

        $admin = User::factory()->create();
        $this->actingAs($admin);

        $dept = Department::create(['name'=>'IT','status'=>'active']);
        $role = Role::create(['title'=>'Admin']);

        $payload = [
            'fullname'      => 'John Tester',
            'email'         => 'john.tester@example.com',
            'phone'         => '08123456789',
            'address'       => 'Jakarta',
            'birth_date'    => '1995-01-01',
            'hire_date'     => now()->toDateString(),
            'department_id' => $dept->id,
            'role_id'       => $role->id,
            'status'        => 'active',
            'salary'        => 2000000,
        ];

        $this->post('/employees', $payload)->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'email' => 'john.tester@example.com',
        ]);
    }

    public function test_validate_required_fields_on_create(): void
    {
        $this->withoutMiddleware(CheckRole::class);

        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post('/employees', [])->assertSessionHasErrors([
            'fullname','email','phone','address','birth_date',
            'hire_date','department_id','role_id','status','salary'
        ]);
    }
}
