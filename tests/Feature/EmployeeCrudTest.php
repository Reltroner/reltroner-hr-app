<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fixture helper: genuine Admin principal linked through User -> Employee -> Role.title = 'Admin'
     *
     * @return array{0: User, 1: Department, 2: Role, 3: Employee}
     */
    private function createAdminPrincipal(): array
    {
        $department = Department::create([
            'name' => 'Management Operations',
            'status' => 'active',
        ]);

        $role = Role::create([
            'title' => 'Admin',
            'description' => 'Administrator role',
        ]);

        $employee = Employee::create([
            'fullname' => 'Admin Principal',
            'email' => 'admin.principal@example.com',
            'phone' => '081200000001',
            'address' => 'Jakarta Headquarters',
            'birth_date' => '1985-05-15',
            'hire_date' => '2020-01-01',
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 15000000.00,
        ]);

        $user = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin.user@example.com',
            'employee_id' => $employee->id,
            'email_verified_at' => now(),
        ]);

        $this->assertNotNull($user->employee);
        $this->assertSame('Admin', $user->employee->role->title);

        return [$user, $department, $role, $employee];
    }

    public function test_admin_can_create_employee(): void
    {
        [$admin] = $this->createAdminPrincipal();
        $this->actingAs($admin);

        $dept = Department::create(['name' => 'IT', 'status' => 'active']);
        $role = Role::firstOrCreate(['title' => 'Admin']);

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
        [$admin] = $this->createAdminPrincipal();
        $this->actingAs($admin);

        $this->post('/employees', [])->assertSessionHasErrors([
            'fullname', 'email', 'phone', 'address', 'birth_date',
            'hire_date', 'department_id', 'role_id', 'status', 'salary',
        ]);
    }
}
