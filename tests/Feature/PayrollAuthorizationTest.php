<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSequence = 1;
    private static int $payrollSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        self::$userSequence = 1;
        self::$payrollSequence = 1;
    }

    /**
     * Deterministic fixture builder: Department -> Role -> Employee -> User.employee_id
     *
     * @return array{0: User, 1: Employee}
     */
    private function createUserWithRole(string $roleTitle): array
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
            'fullname' => "{$roleTitle} Member {$seq}",
            'email' => "employee{$seq}@example.com",
            'phone' => '081234567890',
            'address' => 'Jakarta, Indonesia',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);

        $user = User::factory()->create([
            'name' => "{$roleTitle} User {$seq}",
            'email' => "user{$seq}@example.com",
            'email_verified_at' => now(),
            'employee_id' => $employee->id,
        ]);

        return [$user, $employee];
    }

    /**
     * Deterministic payroll record creator
     */
    private function createPayroll(Employee $employee, array $overrides = []): Payroll
    {
        $seq = self::$payrollSequence++;
        $day = str_pad((string) (($seq % 28) + 1), 2, '0', STR_PAD_LEFT);

        return Payroll::create(array_merge([
            'employee_id' => $employee->id,
            'salary' => 5000000.00,
            'bonus' => 500000.00,
            'deduction' => 200000.00,
            'net_salary' => 5300000.00,
            'payment_date' => "2026-01-{$day}",
        ], $overrides));
    }

    /**
     * Data provider for privileged payroll administrator roles
     */
    public static function privilegedRolesProvider(): array
    {
        return [
            'Admin' => ['Admin'],
            'HR Manager' => ['HR Manager'],
        ];
    }

    /**
     * Data provider for non-privileged self-service member roles
     */
    public static function nonPrivilegedRolesProvider(): array
    {
        return [
            'Developer' => ['Developer'],
            'Accountant' => ['Accountant'],
            'Data Entry' => ['Data Entry'],
            'Animator' => ['Animator'],
            'Marketer' => ['Marketer'],
        ];
    }

    // =========================================================================
    // PRIVILEGED PAYROLL ADMINISTRATORS (Admin, HR Manager)
    // =========================================================================

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_list_payrolls(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createPayroll($adminEmp);
        $this->createPayroll($otherEmp);

        $response = $this->actingAs($admin)->get('/payrolls');

        $response->assertStatus(200);
        $response->assertSee($adminEmp->fullname);
        $response->assertSee($otherEmp->fullname);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_view_another_employees_payroll(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPayroll = $this->createPayroll($otherEmp);

        $response = $this->actingAs($admin)->get("/payrolls/{$otherPayroll->id}");

        $response->assertStatus(200);
        $response->assertSee($otherEmp->fullname);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_payroll_create_form(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);

        $response = $this->actingAs($admin)->get('/payrolls/create');

        $response->assertStatus(200);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_create_payroll(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);
        [$targetUser, $targetEmp] = $this->createUserWithRole('Developer');

        $payload = [
            'employee_id' => $targetEmp->id,
            'salary' => 6000000.00,
            'bonus' => 500000.00,
            'deduction' => 100000.00,
            'payment_date' => '2026-05-15',
        ];

        $response = $this->actingAs($admin)->post('/payrolls', $payload);

        $response->assertRedirect(route('payrolls.index'));
        $created = Payroll::where('employee_id', $targetEmp->id)->latest('id')->first();
        $this->assertNotNull($created);
        $this->assertEquals(6000000.00, (float) $created->salary);
        $this->assertSame('2026-05-15', $created->payment_date->toDateString());
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_payroll_edit_form(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPayroll = $this->createPayroll($otherEmp);

        $response = $this->actingAs($admin)->get("/payrolls/{$otherPayroll->id}/edit");

        $response->assertStatus(200);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_update_payroll(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPayroll = $this->createPayroll($otherEmp);

        $updatePayload = [
            'employee_id' => $otherEmp->id,
            'salary' => 7500000.00,
            'bonus' => 600000.00,
            'deduction' => 150000.00,
            'payment_date' => '2026-06-20',
        ];

        $response = $this->actingAs($admin)->put("/payrolls/{$otherPayroll->id}", $updatePayload);

        $response->assertRedirect(route('payrolls.index'));
        $fresh = $otherPayroll->fresh();
        $this->assertEquals(7500000.00, (float) $fresh->salary);
        $this->assertSame('2026-06-20', $fresh->payment_date->toDateString());
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_delete_payroll(string $roleTitle): void
    {
        [$admin, $adminEmp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPayroll = $this->createPayroll($otherEmp);

        $response = $this->actingAs($admin)->delete("/payrolls/{$otherPayroll->id}");

        $response->assertRedirect(route('payrolls.index'));
        $this->assertSoftDeleted('payrolls', ['id' => $otherPayroll->id]);
    }

    // =========================================================================
    // NON-PRIVILEGED SELF-SERVICE MEMBERS
    // (Developer, Accountant, Data Entry, Animator, Marketer)
    // =========================================================================

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_can_access_payroll_index_and_sees_only_own_payroll(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Admin');

        $this->createPayroll($emp);
        $this->createPayroll($otherEmp);

        $response = $this->actingAs($user)->get('/payrolls');

        $response->assertStatus(200);
        $response->assertSee($emp->fullname);
        $response->assertDontSee($otherEmp->fullname);
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_can_view_own_payroll(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        $ownPayroll = $this->createPayroll($emp);

        $response = $this->actingAs($user)->get("/payrolls/{$ownPayroll->id}");

        $response->assertStatus(200);
        $response->assertSee($emp->fullname);
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_cannot_view_another_employees_payroll_by_direct_id(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Admin');
        $otherPayroll = $this->createPayroll($otherEmp);

        $response = $this->actingAs($user)->get("/payrolls/{$otherPayroll->id}");

        $response->assertStatus(403);
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_cannot_open_payroll_create_form(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);

        $response = $this->actingAs($user)->get('/payrolls/create');

        $response->assertStatus(403);
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_cannot_create_payroll(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        $initialCount = Payroll::count();

        $payload = [
            'employee_id' => $emp->id,
            'salary' => 9999999.00,
            'bonus' => 100000.00,
            'deduction' => 0.00,
            'payment_date' => '2026-07-01',
        ];

        $response = $this->actingAs($user)->post('/payrolls', $payload);

        $response->assertStatus(403);
        $this->assertSame($initialCount, Payroll::count());
        $this->assertDatabaseMissing('payrolls', [
            'employee_id' => $emp->id,
            'payment_date' => '2026-07-01',
        ]);
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_cannot_open_payroll_edit_form_even_for_own_payroll(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        $ownPayroll = $this->createPayroll($emp);

        $response = $this->actingAs($user)->get("/payrolls/{$ownPayroll->id}/edit");

        $response->assertStatus(403);
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_cannot_update_own_payroll(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        [$otherUser, $otherEmp] = $this->createUserWithRole('Admin');

        $ownPayroll = $this->createPayroll($emp, [
            'salary' => 5000000.00,
            'bonus' => 500000.00,
            'deduction' => 200000.00,
            'payment_date' => '2026-01-10',
        ]);

        $tamperedPayload = [
            'employee_id' => $otherEmp->id,
            'salary' => 12000000.00,
            'bonus' => 2000000.00,
            'deduction' => 0.00,
            'payment_date' => '2026-01-25',
        ];

        // Attempt via PUT
        $putResponse = $this->actingAs($user)->put("/payrolls/{$ownPayroll->id}", $tamperedPayload);
        $putResponse->assertStatus(403);

        // Attempt via PATCH
        $patchResponse = $this->actingAs($user)->patch("/payrolls/{$ownPayroll->id}", $tamperedPayload);
        $patchResponse->assertStatus(403);

        // Assert database state remains completely unchanged
        $fresh = $ownPayroll->fresh();
        $this->assertSame($emp->id, $fresh->employee_id);
        $this->assertEquals(5000000.00, (float) $fresh->salary);
        $this->assertEquals(500000.00, (float) $fresh->bonus);
        $this->assertEquals(200000.00, (float) $fresh->deduction);
        $this->assertSame('2026-01-10', $fresh->payment_date->toDateString());
    }

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_cannot_delete_own_payroll(string $roleTitle): void
    {
        [$user, $emp] = $this->createUserWithRole($roleTitle);
        $ownPayroll = $this->createPayroll($emp);

        $response = $this->actingAs($user)->delete("/payrolls/{$ownPayroll->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('payrolls', ['id' => $ownPayroll->id]);
        $this->assertDatabaseHas('payrolls', ['id' => $ownPayroll->id]);
    }

    // =========================================================================
    // EXPLICIT DIRECT-OTHER-RECORD MUTATION (Developer)
    // =========================================================================

    public function test_developer_cannot_update_another_employees_payroll(): void
    {
        [$devUser, $devEmp] = $this->createUserWithRole('Developer');
        [$otherUser, $otherEmp] = $this->createUserWithRole('Accountant');

        $otherPayroll = $this->createPayroll($otherEmp, [
            'salary' => 6000000.00,
            'bonus' => 400000.00,
            'deduction' => 100000.00,
            'payment_date' => '2026-02-15',
        ]);

        $tamperedPayload = [
            'employee_id' => $devEmp->id,
            'salary' => 9999999.00,
            'bonus' => 999999.00,
            'deduction' => 0.00,
            'payment_date' => '2026-02-28',
        ];

        $putResponse = $this->actingAs($devUser)->put("/payrolls/{$otherPayroll->id}", $tamperedPayload);
        $putResponse->assertStatus(403);

        $patchResponse = $this->actingAs($devUser)->patch("/payrolls/{$otherPayroll->id}", $tamperedPayload);
        $patchResponse->assertStatus(403);

        $fresh = $otherPayroll->fresh();
        $this->assertSame($otherEmp->id, $fresh->employee_id);
        $this->assertEquals(6000000.00, (float) $fresh->salary);
        $this->assertEquals(400000.00, (float) $fresh->bonus);
        $this->assertEquals(100000.00, (float) $fresh->deduction);
        $this->assertSame('2026-02-15', $fresh->payment_date->toDateString());
    }

    public function test_developer_cannot_delete_another_employees_payroll(): void
    {
        [$devUser, $devEmp] = $this->createUserWithRole('Developer');
        [$otherUser, $otherEmp] = $this->createUserWithRole('Accountant');
        $otherPayroll = $this->createPayroll($otherEmp);

        $response = $this->actingAs($devUser)->delete("/payrolls/{$otherPayroll->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('payrolls', ['id' => $otherPayroll->id]);
        $this->assertDatabaseHas('payrolls', ['id' => $otherPayroll->id]);
    }

    // =========================================================================
    // FAIL-CLOSED HTTP BEHAVIOR (Guest & Unsupported Role)
    // =========================================================================

    public function test_unauthenticated_guest_cannot_access_any_payroll_endpoint(): void
    {
        [$sampleUser, $sampleEmp] = $this->createUserWithRole('Developer');
        $samplePayroll = $this->createPayroll($sampleEmp);

        $this->get('/payrolls')->assertRedirect('/login');
        $this->get("/payrolls/{$samplePayroll->id}")->assertRedirect('/login');
        $this->get('/payrolls/create')->assertRedirect('/login');
        $this->post('/payrolls', [])->assertRedirect('/login');
        $this->get("/payrolls/{$samplePayroll->id}/edit")->assertRedirect('/login');
        $this->put("/payrolls/{$samplePayroll->id}", [])->assertRedirect('/login');
        $this->patch("/payrolls/{$samplePayroll->id}", [])->assertRedirect('/login');
        $this->delete("/payrolls/{$samplePayroll->id}")->assertRedirect('/login');
    }

    public function test_user_with_unsupported_role_is_denied_payroll_access(): void
    {
        [$contractorUser, $contractorEmp] = $this->createUserWithRole('Contractor');
        [$sampleUser, $sampleEmp] = $this->createUserWithRole('Developer');
        $samplePayroll = $this->createPayroll($sampleEmp);

        $this->actingAs($contractorUser)->get('/payrolls')->assertStatus(403);
        $this->actingAs($contractorUser)->get("/payrolls/{$samplePayroll->id}")->assertStatus(403);
        $this->actingAs($contractorUser)->get('/payrolls/create')->assertStatus(403);
        $this->actingAs($contractorUser)->post('/payrolls', [])->assertStatus(403);
        $this->actingAs($contractorUser)->get("/payrolls/{$samplePayroll->id}/edit")->assertStatus(403);
        $this->actingAs($contractorUser)->put("/payrolls/{$samplePayroll->id}", [])->assertStatus(403);
        $this->actingAs($contractorUser)->patch("/payrolls/{$samplePayroll->id}", [])->assertStatus(403);
        $this->actingAs($contractorUser)->delete("/payrolls/{$samplePayroll->id}")->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_payroll_access(): void
    {
        [$devUser, $devEmp] = $this->createUserWithRole('Developer');
        $payroll = $this->createPayroll($devEmp);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->employee_id = null;
        $user->role = 'Admin';

        // Attempt GET /payrolls
        $this->actingAs($user)->get('/payrolls')->assertStatus(403);

        // Attempt GET /payrolls/{payroll}
        $this->actingAs($user)->get("/payrolls/{$payroll->id}")->assertStatus(403);

        // Attempt GET /payrolls/create
        $this->actingAs($user)->get('/payrolls/create')->assertStatus(403);

        // Attempt POST /payrolls
        $initialCount = Payroll::count();
        $payload = [
            'employee_id' => $devEmp->id,
            'salary' => 7000000.00,
            'bonus' => 500000.00,
            'deduction' => 100000.00,
            'payment_date' => '2026-08-15',
        ];

        $postResponse = $this->actingAs($user)->post('/payrolls', $payload);
        $postResponse->assertStatus(403);

        $this->assertSame($initialCount, Payroll::count());
        $this->assertDatabaseMissing('payrolls', [
            'employee_id' => $devEmp->id,
            'payment_date' => '2026-08-15',
        ]);
    }
}
