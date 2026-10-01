<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Presence;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PresenceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSequence = 1;
    private static int $presenceSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        self::$userSequence = 1;
        self::$presenceSequence = 1;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
     * Fixture helper: User with no Employee relationship, exercising the legacy
     * in-memory CheckRole users.role fallback without modifying test database schema.
     */
    private function createUnlinkedUserWithLegacyRole(string $role = 'Admin'): User
    {
        $user = User::factory()->create([
            'employee_id' => '0',
            'email_verified_at' => now(),
        ]);

        // This intentionally exercises the legacy CheckRole users.role fallback;
        // persistence is not fabricated because the migration-defined test schema
        // does not contain users.role;
        // the business Policy must still reject because User->Employee is missing.
        $user->setAttribute('role', $role);

        $this->assertNull($user->employee);

        return $user;
    }

    /**
     * Deterministic presence record creator
     */
    private function createPresence(Employee $employee, array $overrides = []): Presence
    {
        $seq = self::$presenceSequence++;
        $day = str_pad((string) (($seq % 28) + 1), 2, '0', STR_PAD_LEFT);
        $date = "2026-05-{$day}";

        return Presence::create(array_merge([
            'employee_id' => $employee->id,
            'date'        => $date,
            'check_in'    => "{$date} 09:00:00",
            'check_out'   => "{$date} 17:00:00",
            'status'      => 'present',
            'latitude'    => -6.200000,
            'longitude'   => 106.816666,
        ], $overrides));
    }

    /**
     * Data provider for privileged administrator roles
     */
    public static function privilegedRolesProvider(): array
    {
        return [
            'Admin' => ['Admin'],
            'HR Manager' => ['HR Manager'],
        ];
    }

    /**
     * Data provider for self-service member roles
     */
    public static function selfServiceRolesProvider(): array
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
    // PRIVILEGED ROLES TESTS (Admin, HR Manager)
    // =========================================================================

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_list_all_presences(string $role): void
    {
        [$adminUser, $adminEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createPresence($adminEmp);
        $this->createPresence($otherEmp);

        $response = $this->actingAs($adminUser)->get('/presences');

        $response->assertSuccessful();
        $response->assertSee($adminEmp->fullname);
        $response->assertSee($otherEmp->fullname);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_view_another_employees_presence(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherPresence = $this->createPresence($otherEmp);

        $response = $this->actingAs($adminUser)->get("/presences/{$otherPresence->id}");

        $response->assertSuccessful();
        $response->assertSee($otherEmp->fullname);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_presence_create_form(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);

        $response = $this->actingAs($adminUser)->get('/presences/create');

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_manually_create_presence_for_another_employee(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $payload = [
            'employee_id' => $otherEmp->id,
            'check_in'    => '2026-06-01 08:30:00',
            'date'        => '2026-06-01',
            'status'      => 'present',
        ];

        $response = $this->actingAs($adminUser)->post('/presences', $payload);

        $response->assertRedirect(route('presences.index'));
        $this->assertDatabaseHas('presences', [
            'employee_id' => $otherEmp->id,
            'date'        => '2026-06-01',
            'status'      => 'present',
        ]);

        $presence = Presence::where('employee_id', $otherEmp->id)
            ->whereDate('date', '2026-06-01')
            ->firstOrFail();

        $this->assertSame($otherEmp->id, $presence->employee_id);
        $this->assertSame('2026-06-01', $presence->date->format('Y-m-d'));
        $this->assertSame('present', $presence->status);
        $this->assertSame('2026-06-01 08:30:00', $presence->check_in->format('Y-m-d H:i:s'));
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_edit_form_for_another_employees_presence(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherPresence = $this->createPresence($otherEmp);

        $response = $this->actingAs($adminUser)->get("/presences/{$otherPresence->id}/edit");

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_update_another_employees_presence(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherPresence = $this->createPresence($otherEmp, [
            'date'   => '2026-06-02',
            'status' => 'present',
        ]);

        $payload = [
            'employee_id' => $otherEmp->id,
            'check_in'    => '2026-06-02 08:00:00',
            'check_out'   => '2026-06-02 17:00:00',
            'date'        => '2026-06-02',
            'status'      => 'late',
        ];

        $response = $this->actingAs($adminUser)->put("/presences/{$otherPresence->id}", $payload);

        $response->assertRedirect(route('presences.index'));
        $otherPresence->refresh();
        $this->assertSame('late', $otherPresence->status);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_reassign_employee_id_through_privileged_update(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $firstEmp] = $this->createUserWithRole('Developer');
        [, $secondEmp] = $this->createUserWithRole('Accountant');

        $presence = $this->createPresence($firstEmp, [
            'date' => '2026-06-03',
        ]);

        $payload = [
            'employee_id' => $secondEmp->id,
            'check_in'    => '2026-06-03 09:00:00',
            'check_out'   => '2026-06-03 17:00:00',
            'date'        => '2026-06-03',
            'status'      => 'present',
        ];

        $response = $this->actingAs($adminUser)->put("/presences/{$presence->id}", $payload);

        $response->assertRedirect(route('presences.index'));
        $presence->refresh();
        $this->assertSame($secondEmp->id, $presence->employee_id);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_change_attendance_fields_through_privileged_update(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $presence = $this->createPresence($otherEmp, [
            'date'   => '2026-06-04',
            'status' => 'present',
        ]);

        $payload = [
            'employee_id' => $otherEmp->id,
            'check_in'    => '2026-06-04 10:00:00',
            'check_out'   => '2026-06-04 16:00:00',
            'date'        => '2026-06-04',
            'status'      => 'absent',
        ];

        $response = $this->actingAs($adminUser)->put("/presences/{$presence->id}", $payload);

        $response->assertRedirect(route('presences.index'));
        $presence->refresh();
        $this->assertSame('2026-06-04 10:00:00', $presence->check_in->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-04 16:00:00', $presence->check_out->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-04', $presence->date->format('Y-m-d'));
        $this->assertSame('absent', $presence->status);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_delete_presence(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $presence = $this->createPresence($otherEmp);

        $response = $this->actingAs($adminUser)->delete("/presences/{$presence->id}");

        $response->assertRedirect(route('presences.index'));
        $this->assertSoftDeleted('presences', ['id' => $presence->id]);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_use_dedicated_check_in_for_own_identity(string $role): void
    {
        [$adminUser, $adminEmp] = $this->createUserWithRole($role);

        Carbon::setTestNow('2026-08-10 08:30:00');

        $response = $this->actingAs($adminUser)->post('/attendance/check-in');

        $response->assertSessionHas('status', 'checked-in');

        $presence = Presence::where('employee_id', $adminEmp->id)
            ->whereDate('date', '2026-08-10')
            ->firstOrFail();

        $this->assertSame($adminEmp->id, $presence->employee_id);
        $this->assertSame('2026-08-10', $presence->date->format('Y-m-d'));
        $this->assertSame('present', $presence->status);
    }

    // =========================================================================
    // SELF-SERVICE ROLES TESTS (Developer, Accountant, Data Entry, Animator, Marketer)
    // =========================================================================

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_access_presences_index_and_sees_only_own_presence(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createPresence($selfEmp);
        $this->createPresence($otherEmp);

        $response = $this->actingAs($selfUser)->get('/presences');

        $response->assertSuccessful();
        $response->assertSee($selfEmp->fullname);
        $response->assertDontSee($otherEmp->fullname);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_view_own_presence(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);

        $ownPresence = $this->createPresence($selfEmp);

        $response = $this->actingAs($selfUser)->get("/presences/{$ownPresence->id}");

        $response->assertSuccessful();
        $response->assertSee($selfEmp->fullname);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_view_another_employees_presence_by_direct_id(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherPresence = $this->createPresence($otherEmp);

        $response = $this->actingAs($selfUser)->get("/presences/{$otherPresence->id}");

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_open_presence_create_form(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);

        $response = $this->actingAs($selfUser)->get('/presences/create');

        $response->assertSuccessful();
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_use_resource_store_as_self_check_in_ignoring_tampered_inputs(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        Carbon::setTestNow('2026-06-15 09:15:30');

        $payload = [
            'employee_id' => $otherEmp->id,
            'check_in'    => '2020-01-01 12:00:00',
            'check_out'   => '2020-01-01 18:00:00',
            'date'        => '2020-01-01',
            'status'      => 'absent',
            'latitude'    => -6.208800,
            'longitude'   => 106.845600,
        ];

        $response = $this->actingAs($selfUser)->post('/presences', $payload);

        $response->assertRedirect(route('presences.index'));

        // Assert record created for authenticated employee with server-controlled values
        $presence = Presence::where('employee_id', $selfEmp->id)
            ->whereDate('date', '2026-06-15')
            ->firstOrFail();

        $this->assertSame($selfEmp->id, $presence->employee_id);
        $this->assertSame('2026-06-15', $presence->date->format('Y-m-d'));
        $this->assertSame('present', $presence->status);
        $this->assertNull($presence->check_out);
        $this->assertStringStartsWith('2026-06-15 09:15', $presence->check_in->format('Y-m-d H:i'));

        // Assert no record created for other employee
        $this->assertDatabaseMissing('presences', [
            'employee_id' => $otherEmp->id,
            'date'        => '2020-01-01',
        ]);
        $this->assertDatabaseMissing('presences', [
            'employee_id' => $otherEmp->id,
            'date'        => '2026-06-15',
        ]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_open_edit_form_for_own_presence(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownPresence = $this->createPresence($selfEmp);

        $response = $this->actingAs($selfUser)->get("/presences/{$ownPresence->id}/edit");

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_open_edit_form_for_another_presence(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPresence = $this->createPresence($otherEmp);

        $response = $this->actingAs($selfUser)->get("/presences/{$otherPresence->id}/edit");

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_update_own_presence(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownPresence = $this->createPresence($selfEmp, [
            'date'   => '2026-05-10',
            'status' => 'present',
        ]);

        $payload = [
            'employee_id' => $selfEmp->id,
            'check_in'    => '2026-05-10 07:00:00',
            'check_out'   => '2026-05-10 18:00:00',
            'date'        => '2026-05-10',
            'status'      => 'absent',
        ];

        $response = $this->actingAs($selfUser)->put("/presences/{$ownPresence->id}", $payload);

        $response->assertStatus(403);
        $ownPresence->refresh();
        $this->assertSame('present', $ownPresence->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_update_another_presence(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPresence = $this->createPresence($otherEmp, [
            'date'   => '2026-05-10',
            'status' => 'present',
        ]);

        $payload = [
            'employee_id' => $otherEmp->id,
            'check_in'    => '2026-05-10 07:00:00',
            'check_out'   => '2026-05-10 18:00:00',
            'date'        => '2026-05-10',
            'status'      => 'absent',
        ];

        $response = $this->actingAs($selfUser)->put("/presences/{$otherPresence->id}", $payload);

        $response->assertStatus(403);
        $otherPresence->refresh();
        $this->assertSame('present', $otherPresence->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_delete_own_presence(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownPresence = $this->createPresence($selfEmp);

        $response = $this->actingAs($selfUser)->delete("/presences/{$ownPresence->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('presences', ['id' => $ownPresence->id]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_delete_another_presence(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherPresence = $this->createPresence($otherEmp);

        $response = $this->actingAs($selfUser)->delete("/presences/{$otherPresence->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('presences', ['id' => $otherPresence->id]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_use_dedicated_check_in_ignoring_tampered_employee_id(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        Carbon::setTestNow('2026-07-20 08:30:00');

        $response = $this->actingAs($selfUser)->post('/attendance/check-in', [
            'employee_id' => $otherEmp->id,
        ]);

        $response->assertSessionHas('status', 'checked-in');

        $presence = Presence::where('employee_id', $selfEmp->id)
            ->whereDate('date', '2026-07-20')
            ->firstOrFail();

        $this->assertSame($selfEmp->id, $presence->employee_id);
        $this->assertSame('2026-07-20', $presence->date->format('Y-m-d'));
        $this->assertSame('present', $presence->status);

        $this->assertDatabaseMissing('presences', [
            'employee_id' => $otherEmp->id,
            'date'        => '2026-07-20',
        ]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_duplicate_same_day_check_in_is_rejected(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);

        Carbon::setTestNow('2026-07-21 08:30:00');

        // First check-in
        $this->actingAs($selfUser)->post('/attendance/check-in');

        // Second check-in on the same day
        $secondResponse = $this->actingAs($selfUser)->post('/attendance/check-in');
        $secondResponse->assertSessionHasErrors('attendance');

        $this->assertSame(
            1,
            Presence::where('employee_id', $selfEmp->id)
                ->whereDate('date', '2026-07-21')
                ->count()
        );
    }

    // =========================================================================
    // FAIL-CLOSED & DEFENSE-IN-DEPTH TESTS
    // =========================================================================

    public function test_unauthenticated_guest_cannot_access_any_presence_endpoint(): void
    {
        [, $emp] = $this->createUserWithRole('Developer');
        $presence = $this->createPresence($emp);

        $endpoints = [
            ['get', '/presences'],
            ['get', '/presences/create'],
            ['post', '/presences', []],
            ['get', "/presences/{$presence->id}"],
            ['get', "/presences/{$presence->id}/edit"],
            ['put', "/presences/{$presence->id}", []],
            ['delete', "/presences/{$presence->id}"],
            ['post', '/attendance/check-in', []],
        ];

        foreach ($endpoints as $entry) {
            $method = $entry[0];
            $uri = $entry[1];
            $payload = $entry[2] ?? [];

            if ($method === 'get') {
                $this->get($uri)->assertRedirect('/login');
            } elseif ($method === 'post') {
                $this->post($uri, $payload)->assertRedirect('/login');
            } elseif ($method === 'put') {
                $this->put($uri, $payload)->assertRedirect('/login');
            } elseif ($method === 'delete') {
                $this->delete($uri)->assertRedirect('/login');
            }
        }
    }

    public function test_user_with_unsupported_role_is_denied_presence_resource_and_check_in(): void
    {
        [$user, $emp] = $this->createUserWithRole('Contractor');
        $presence = $this->createPresence($emp);

        $this->actingAs($user)->get('/presences')->assertStatus(403);
        $this->actingAs($user)->get("/presences/{$presence->id}")->assertStatus(403);
        $this->actingAs($user)->get('/presences/create')->assertStatus(403);
        $this->actingAs($user)->post('/presences', [
            'check_in' => '2026-08-01 09:00:00',
            'date'     => '2026-08-01',
            'status'   => 'present',
        ])->assertStatus(403);

        $this->actingAs($user)->post('/attendance/check-in')->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_presence_index(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/presences');

        $response->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_presence_create_form(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/presences/create');

        $response->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_direct_presence_access(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');
        $presence = $this->createPresence($devEmp);

        $response = $this->actingAs($user)->get("/presences/{$presence->id}");

        $response->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_presence_store(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');

        $initialCount = Presence::count();

        $response = $this->actingAs($user)->post('/presences', [
            'employee_id' => $devEmp->id,
            'check_in'    => '2026-09-01 09:00:00',
            'date'        => '2026-09-01',
            'status'      => 'present',
        ]);

        $response->assertStatus(403);
        $this->assertSame($initialCount, Presence::count());
        $this->assertDatabaseMissing('presences', [
            'date' => '2026-09-01',
        ]);
    }

    public function test_user_without_employee_relationship_is_denied_dedicated_check_in(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $initialCount = Presence::count();

        $response = $this->actingAs($user)->post('/attendance/check-in');

        $response->assertStatus(403);
        $this->assertSame($initialCount, Presence::count());
    }
}
