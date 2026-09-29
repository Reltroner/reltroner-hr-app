<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_pages_load_for_admin(): void
    {
        $department = Department::firstOrCreate(
            ['name' => 'General Operations'],
            ['status' => 'active']
        );

        $role = Role::firstOrCreate(
            ['title' => 'Admin'],
            ['description' => 'Admin role']
        );

        $employee = Employee::create([
            'fullname' => 'Admin User',
            'email' => 'admin.employee@example.com',
            'phone' => '081234567890',
            'address' => 'Test Address',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);

        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'employee_id' => $employee->id,
        ]);

        $this->actingAs($admin);

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
