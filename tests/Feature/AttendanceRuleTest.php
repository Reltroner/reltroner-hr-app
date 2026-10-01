<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Presence;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prevent_double_checkin_same_day(): void
    {
        Carbon::setTestNow('2026-09-30 08:30:00');

        $dept = Department::create([
            'name' => 'IT',
            'status' => 'active',
        ]);
        $role = Role::create([
            'title' => 'Developer',
        ]);
        $emp = Employee::create([
            'fullname' => 'Emp One',
            'email' => 'emp1@example.com',
            'phone' => '081234567890',
            'address' => 'JKT',
            'birth_date' => '1990-01-01',
            'hire_date' => '2026-01-01',
            'department_id' => $dept->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 1000000,
        ]);

        $user = User::factory()->create([
            'employee_id' => $emp->id,
        ]);

        $this->assertSame($emp->id, $user->employee->id);
        $this->assertSame('Developer', $user->employee->role->title);

        $this->actingAs($user);

        // First check-in (success)
        $firstResponse = $this->post('/attendance/check-in');
        $firstResponse->assertSessionHas('status', 'checked-in');

        $this->assertSame(
            1,
            Presence::where('employee_id', $user->employee_id)
                ->whereDate('date', '2026-09-30')
                ->count()
        );

        $presence = Presence::where('employee_id', $user->employee_id)
            ->whereDate('date', '2026-09-30')
            ->firstOrFail();

        $this->assertSame($user->employee_id, $presence->employee_id);
        $this->assertSame('present', $presence->status);
        $this->assertSame('2026-09-30', $presence->date->format('Y-m-d'));

        // Second check-in (rejected as duplicate on same date)
        $secondResponse = $this->post('/attendance/check-in');
        $secondResponse->assertSessionHasErrors(['attendance']);

        $this->assertSame(
            1,
            Presence::where('employee_id', $user->employee_id)
                ->whereDate('date', '2026-09-30')
                ->count()
        );
    }
}
