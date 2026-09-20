<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPresenceAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_monthly_aggregation_is_database_portable(): void
    {
        $now = now();

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Engineering',
            'description' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'title' => 'Employee',
            'description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $employeeId = DB::table('employees')->insertGetId([
            'fullname' => 'PostgreSQL Compatibility User',
            'email' => 'postgres-compat@example.com',
            'phone' => '0800000000',
            'address' => 'Test Address',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $departmentId,
            'role_id' => $roleId,
            'status' => 'active',
            'salary' => '1000000.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('presences')->insert([
            [
                'employee_id' => $employeeId,
                'check_in' => '2025-01-05 08:00:00',
                'check_out' => null,
                'date' => '2025-01-05',
                'status' => 'present',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => $employeeId,
                'check_in' => '2026-01-10 08:00:00',
                'check_out' => null,
                'date' => '2026-01-10',
                'status' => 'present',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => $employeeId,
                'check_in' => '2026-02-10 08:15:00',
                'check_out' => null,
                'date' => '2026-02-10',
                'status' => 'late',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'employee_id' => $employeeId,
                'check_in' => '2026-03-10 08:00:00',
                'check_out' => null,
                'date' => '2026-03-10',
                'status' => 'present',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = app(DashboardController::class)->presence();
        $data = $response->getData(true);

        $this->assertCount(12, $data['present']);
        $this->assertCount(12, $data['late']);

        // Existing behavior groups by month number across years.
        $this->assertSame(2, $data['present'][0]);

        $this->assertSame(1, $data['late'][1]);
        $this->assertSame(1, $data['present'][2]);

        $this->assertSame(0, $data['absent'][0]);
        $this->assertSame(0, $data['leave'][0]);
    }
}