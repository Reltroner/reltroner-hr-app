<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacAccessTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        self::$userSequence = 1;
    }

    /**
     * Deterministic helper for creating Department, Role, Employee, and linked User.
     */
    private function createUserWithRole(string $roleTitle): User
    {
        $seq = self::$userSequence++;

        $department = Department::firstOrCreate(
            ['name' => 'General Operations'],
            ['status' => 'active']
        );

        $role = Role::firstOrCreate(
            ['title' => $roleTitle],
            ['description' => "{$roleTitle} role"]
        );

        $employee = Employee::create([
            'fullname' => "{$roleTitle} User {$seq}",
            'email' => "employee{$seq}@example.com",
            'phone' => '081234567890',
            'address' => 'Test Address',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);

        return User::factory()->create([
            'name' => "{$roleTitle} User {$seq}",
            'email' => "user{$seq}@example.com",
            'email_verified_at' => now(),
            'employee_id' => $employee->id,
        ]);
    }

    public function test_admin_can_access_employees_index(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $this->actingAs($admin)
            ->get('/employees')
            ->assertStatus(200);
    }

    public function test_hr_manager_can_access_employees_index(): void
    {
        $hrManager = $this->createUserWithRole('HR Manager');

        $this->actingAs($hrManager)
            ->get('/employees')
            ->assertStatus(200);
    }

    public function test_employee_cannot_access_employees_index(): void
    {
        $employee = $this->createUserWithRole('Employee');

        $this->actingAs($employee)
            ->get('/employees')
            ->assertStatus(403);
    }

    public function test_unauthenticated_guest_cannot_access_dashboard_presence(): void
    {
        $response = $this->get('/dashboard/presence');

        $response->assertRedirect('/login');
    }

    public function test_admin_can_access_dashboard_presence(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $this->actingAs($admin)
            ->get('/dashboard/presence')
            ->assertStatus(200);
    }

    public function test_hr_manager_can_access_dashboard_presence(): void
    {
        $hrManager = $this->createUserWithRole('HR Manager');

        $this->actingAs($hrManager)
            ->get('/dashboard/presence')
            ->assertStatus(200);
    }

    public function test_employee_cannot_access_dashboard_presence(): void
    {
        $employee = $this->createUserWithRole('Employee');

        $this->actingAs($employee)
            ->get('/dashboard/presence')
            ->assertStatus(403);
    }
}
