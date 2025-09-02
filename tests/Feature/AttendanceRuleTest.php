<?php
// tests/Feature/AttendanceRuleTest.php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Department;
use App\Models\Role;
use App\Models\Employee;

class AttendanceRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_prevent_double_checkin_same_day(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $dept = Department::create(['name'=>'IT','status'=>'active']);
        $role = Role::create(['title'=>'Employee']);
        $emp  = Employee::create([
            'fullname'=>'Emp One','email'=>'emp1@example.com','phone'=>'08',
            'address'=>'JKT','birth_date'=>'1990-01-01','hire_date'=>now()->toDateString(),
            'department_id'=>$dept->id,'role_id'=>$role->id,'status'=>'active','salary'=>1000000,
        ]);

        // App kamu baca employee_id dari session
        session(['employee_id' => $emp->id, 'role' => 'Employee']);

        // Check-in pertama (sukses)
        $this->post('/attendance/check-in')->assertSessionHasNoErrors();

        // Check-in kedua (harus ditolak)
        $this->post('/attendance/check-in')
             ->assertSessionHasErrors(['attendance']);
    }
}
