<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSequence = 1;
    private static int $customRoleSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        self::$userSequence = 1;
        self::$customRoleSequence = 1;
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
     * Helper to create or fetch a standard department.
     */
    private function createDepartment(): Department
    {
        return Department::firstOrCreate(
            ['name' => 'General Operations'],
            ['status' => 'active']
        );
    }

    /**
     * Helper to create or fetch a standard role.
     */
    private function createRole(string $title): Role
    {
        return Role::firstOrCreate(
            ['title' => $title],
            ['description' => "{$title} role"]
        );
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

        $user->setAttribute('role', $role);

        $this->assertNull($user->employee);

        return $user;
    }

    // =========================================================================
    // DATA PROVIDERS
    // =========================================================================

    public static function privilegedRolesProvider(): array
    {
        return [
            'Admin' => ['Admin'],
            'HR Manager' => ['HR Manager'],
        ];
    }

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

    public static function canonicalProtectedRolesProvider(): array
    {
        return [
            'Admin' => ['Admin'],
            'HR Manager' => ['HR Manager'],
            'Developer' => ['Developer'],
            'Accountant' => ['Accountant'],
            'Data Entry' => ['Data Entry'],
            'Animator' => ['Animator'],
            'Marketer' => ['Marketer'],
        ];
    }

    public static function privilegedActorsAndProtectedRolesProvider(): array
    {
        $actors = ['Admin', 'HR Manager'];
        $roles = ['Admin', 'HR Manager', 'Developer', 'Accountant', 'Data Entry', 'Animator', 'Marketer'];
        $data = [];
        foreach ($actors as $actor) {
            foreach ($roles as $role) {
                $data["{$actor} -> {$role}"] = [$actor, $role];
            }
        }
        return $data;
    }

    public static function privilegedActorsAndReservedAliasesProvider(): array
    {
        $actors = ['Admin', 'HR Manager'];
        $aliases = [
            'spaced admin' => ' admin ',
            'upper admin' => 'ADMIN',
            'mixed hr manager' => ' hr MANAGER ',
            'spaced developer' => ' developer ',
            'upper data entry' => 'DATA ENTRY',
            'spaced animator' => ' animator ',
            'upper marketer' => 'MARKETER',
        ];
        $data = [];
        foreach ($actors as $actor) {
            foreach ($aliases as $key => $alias) {
                $data["{$actor} -> {$key}"] = [$actor, $alias];
            }
        }
        return $data;
    }

    public static function privilegedActorsAndRenameAliasesProvider(): array
    {
        $actors = ['Admin', 'HR Manager'];
        $aliases = [
            'spaced admin' => ' admin ',
            'upper hr manager' => 'HR MANAGER',
            'spaced developer' => ' Developer ',
        ];
        $data = [];
        foreach ($actors as $actor) {
            foreach ($aliases as $key => $alias) {
                $data["{$actor} -> {$key}"] = [$actor, $alias];
            }
        }
        return $data;
    }

    // =========================================================================
    // 1. PRIVILEGED EMPLOYEE ADMINISTRATION (Admin, HR Manager)
    // =========================================================================

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_access_employees_index(string $actorRole): void
    {
        [$actorUser, $actorEmp] = $this->createUserWithRole($actorRole);
        [, $colleague] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($actorUser)->get('/employees');

        $response->assertSuccessful();
        $response->assertSee($actorEmp->fullname);
        $response->assertSee($colleague->fullname);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_view_employee(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        [, $targetEmp] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($actorUser)->get("/employees/{$targetEmp->id}");

        $response->assertSuccessful();
        $response->assertSee($targetEmp->fullname);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_employee_create_form(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $response = $this->actingAs($actorUser)->get('/employees/create');

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_create_employee_with_explicit_privileged_fields(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $department = $this->createDepartment();
        $targetRole = $this->createRole('Developer');

        $payload = [
            'fullname' => "New Hire by {$actorRole}",
            'email' => 'newhire.' . strtolower(str_replace(' ', '', $actorRole)) . '@example.com',
            'phone' => '081299990001',
            'address' => 'Bandung, Indonesia',
            'birth_date' => '1992-05-15',
            'hire_date' => '2026-02-01',
            'department_id' => $department->id,
            'role_id' => $targetRole->id,
            'status' => 'active',
            'salary' => 7500000.00,
        ];

        $response = $this->actingAs($actorUser)->post('/employees', $payload);

        $response->assertRedirect(route('employees.index'));
        $this->assertDatabaseHas('employees', [
            'fullname' => "New Hire by {$actorRole}",
            'email' => $payload['email'],
            'role_id' => $targetRole->id,
            'status' => 'active',
            'salary' => 7500000.00,
        ]);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_employee_edit_form(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        [, $targetEmp] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($actorUser)->get("/employees/{$targetEmp->id}/edit");

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_update_another_employee_all_fields(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        [, $targetEmp] = $this->createUserWithRole('Developer');
        $newDept = $this->createDepartment();
        $newRole = $this->createRole('Accountant');

        $payload = [
            'fullname' => "Updated Fullname by {$actorRole}",
            'email' => 'updated.' . strtolower(str_replace(' ', '', $actorRole)) . '@example.com',
            'phone' => '081299990002',
            'address' => 'Surabaya, Indonesia',
            'birth_date' => '1988-11-20',
            'hire_date' => '2024-03-01',
            'department_id' => $newDept->id,
            'role_id' => $newRole->id,
            'status' => 'inactive',
            'salary' => 9000000.00,
        ];

        $response = $this->actingAs($actorUser)->put("/employees/{$targetEmp->id}", $payload);

        $response->assertRedirect(route('employees.index'));
        $targetEmp->refresh();
        $this->assertSame("Updated Fullname by {$actorRole}", $targetEmp->fullname);
        $this->assertSame($payload['email'], $targetEmp->email);
        $this->assertSame('081299990002', $targetEmp->phone);
        $this->assertSame('Surabaya, Indonesia', $targetEmp->address);
        $this->assertSame('1988-11-20', $targetEmp->birth_date->format('Y-m-d'));
        $this->assertSame('2024-03-01', $targetEmp->hire_date->format('Y-m-d'));
        $this->assertSame($newDept->id, $targetEmp->department_id);
        $this->assertSame($newRole->id, $targetEmp->role_id);
        $this->assertSame('inactive', $targetEmp->status);
        $this->assertEquals(9000000.00, (float) $targetEmp->salary);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_delete_another_employee(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        [, $colleague] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($actorUser)->delete("/employees/{$colleague->id}");

        $response->assertRedirect(route('employees.index'));
        $this->assertSoftDeleted('employees', ['id' => $colleague->id]);
    }

    // =========================================================================
    // 2. AUTHORITATIVE SELF-DELETE BOUNDARY (Admin, HR Manager)
    // =========================================================================

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_cannot_delete_own_employee_even_when_emails_differ(string $actorRole): void
    {
        [$actorUser, $actorEmp] = $this->createUserWithRole($actorRole);

        // Explicitly assert that User.email and Employee.email differ
        $this->assertNotSame($actorUser->email, $actorEmp->email);
        $this->assertSame($actorUser->employee_id, $actorEmp->id);

        $response = $this->actingAs($actorUser)->delete("/employees/{$actorEmp->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('employees', ['id' => $actorEmp->id]);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_delete_different_employee_with_same_email_as_actor_user(string $actorRole): void
    {
        [$actorUser, $actorEmp] = $this->createUserWithRole($actorRole);
        $department = $this->createDepartment();
        $devRole = $this->createRole('Developer');

        // Create a different Employee whose email matches actorUser's email
        $differentEmp = Employee::create([
            'fullname' => 'Colleague With Same Email',
            'email' => $actorUser->email,
            'phone' => '081299991111',
            'address' => 'Surabaya, Indonesia',
            'birth_date' => '1991-03-10',
            'hire_date' => '2025-06-01',
            'department_id' => $department->id,
            'role_id' => $devRole->id,
            'status' => 'active',
            'salary' => 4500000.00,
        ]);

        $this->assertNotSame($actorEmp->id, $differentEmp->id);
        $this->assertSame($actorUser->email, $differentEmp->email);

        $response = $this->actingAs($actorUser)->delete("/employees/{$differentEmp->id}");

        $response->assertRedirect(route('employees.index'));
        $this->assertSoftDeleted('employees', ['id' => $differentEmp->id]);
    }

    // =========================================================================
    // 3. NON-PRIVILEGED EMPLOYEE ACCESS (Developer, Accountant, Data Entry, etc.)
    // =========================================================================

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_is_denied_employee_operations(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        [, $targetEmp] = $this->createUserWithRole('Developer');

        // GET /employees
        $this->actingAs($actorUser)->get('/employees')->assertStatus(403);

        // GET /employees/{employee}
        $this->actingAs($actorUser)->get("/employees/{$targetEmp->id}")->assertStatus(403);

        // GET /employees/create
        $this->actingAs($actorUser)->get('/employees/create')->assertStatus(403);

        // POST /employees
        $postResponse = $this->actingAs($actorUser)->post('/employees', [
            'fullname' => 'Unauthorized Member',
            'email' => 'unauth@example.com',
            'phone' => '081233334444',
            'address' => 'Test',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $targetEmp->department_id,
            'role_id' => $targetEmp->role_id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);
        $postResponse->assertStatus(403);
        $this->assertDatabaseMissing('employees', ['email' => 'unauth@example.com']);

        // GET /employees/{employee}/edit
        $this->actingAs($actorUser)->get("/employees/{$targetEmp->id}/edit")->assertStatus(403);

        // PUT /employees/{employee}
        $putResponse = $this->actingAs($actorUser)->put("/employees/{$targetEmp->id}", [
            'fullname' => 'Mutated Fullname',
            'email' => $targetEmp->email,
            'phone' => '081299990000',
            'address' => $targetEmp->address,
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $targetEmp->department_id,
            'role_id' => $targetEmp->role_id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);
        $putResponse->assertStatus(403);
        $targetEmp->refresh();
        $this->assertNotSame('Mutated Fullname', $targetEmp->fullname);

        // DELETE /employees/{employee}
        $delResponse = $this->actingAs($actorUser)->delete("/employees/{$targetEmp->id}");
        $delResponse->assertStatus(403);
        $this->assertNotSoftDeleted('employees', ['id' => $targetEmp->id]);
    }

    // =========================================================================
    // 4. EMPLOYEE FAIL-CLOSED PRINCIPAL (Unlinked User)
    // =========================================================================

    public function test_unlinked_user_is_denied_employee_index(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/employees');

        $response->assertStatus(403);
    }

    public function test_unlinked_user_is_denied_employee_create_form(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/employees/create');

        $response->assertStatus(403);
    }

    public function test_unlinked_user_is_denied_direct_employee_access(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $targetEmp] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($user)->get("/employees/{$targetEmp->id}");

        $response->assertStatus(403);
    }

    public function test_unlinked_user_is_denied_employee_store(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        $department = $this->createDepartment();
        $devRole = $this->createRole('Developer');

        $initialCount = Employee::count();

        $response = $this->actingAs($user)->post('/employees', [
            'fullname' => 'Unlinked Employee',
            'email' => 'unlinked.employee@example.com',
            'phone' => '081299990003',
            'address' => 'Jakarta',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'role_id' => $devRole->id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);

        $response->assertStatus(403);
        $this->assertSame($initialCount, Employee::count());
        $this->assertDatabaseMissing('employees', [
            'email' => 'unlinked.employee@example.com',
        ]);
    }

    public function test_unlinked_user_is_denied_employee_update(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $targetEmp] = $this->createUserWithRole('Developer');

        $originalFullname = $targetEmp->fullname;

        $response = $this->actingAs($user)->put("/employees/{$targetEmp->id}", [
            'fullname' => 'Hacked by Unlinked User',
            'email' => $targetEmp->email,
            'phone' => $targetEmp->phone,
            'address' => $targetEmp->address,
            'birth_date' => $targetEmp->birth_date->format('Y-m-d'),
            'hire_date' => $targetEmp->hire_date->format('Y-m-d'),
            'department_id' => $targetEmp->department_id,
            'role_id' => $targetEmp->role_id,
            'status' => $targetEmp->status,
            'salary' => $targetEmp->salary,
        ]);

        $response->assertStatus(403);
        $targetEmp->refresh();
        $this->assertSame($originalFullname, $targetEmp->fullname);
    }

    public function test_unlinked_user_is_denied_employee_delete(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $targetEmp] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($user)->delete("/employees/{$targetEmp->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('employees', ['id' => $targetEmp->id]);
    }

    // =========================================================================
    // 5. PRIVILEGED ROLE ADMINISTRATION (Admin, HR Manager)
    // =========================================================================

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_access_roles_index(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $this->createRole('Operations Coordinator');

        $response = $this->actingAs($actorUser)->get('/roles');

        $response->assertSuccessful();
        $response->assertSee('Operations Coordinator');
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_view_role_details(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $customRole = Role::firstOrCreate(
            ['title' => 'Facilities Specialist'],
            ['description' => 'Facilities management']
        );

        $response = $this->actingAs($actorUser)->get("/roles/{$customRole->id}");

        $response->assertSuccessful();
        $response->assertSee('Facilities Specialist');
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_role_create_form(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $response = $this->actingAs($actorUser)->get('/roles/create');

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_create_custom_role(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $seq = self::$customRoleSequence++;
        $title = "Operations Coordinator {$seq}";

        $payload = [
            'title' => $title,
            'description' => 'Manages operational workflows',
        ];

        $response = $this->actingAs($actorUser)->post('/roles', $payload);

        $response->assertRedirect(route('roles.index'));
        $this->assertDatabaseHas('roles', [
            'title' => $title,
            'description' => 'Manages operational workflows',
        ]);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_custom_role_edit_form(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $seq = self::$customRoleSequence++;
        $customRole = Role::create([
            'title' => "Support Specialist {$seq}",
            'description' => 'Support duties',
        ]);

        $response = $this->actingAs($actorUser)->get("/roles/{$customRole->id}/edit");

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_update_custom_role(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $seq = self::$customRoleSequence++;
        $customRole = Role::create([
            'title' => "Logistics Assistant {$seq}",
            'description' => 'Assists logistics',
        ]);

        $payload = [
            'title' => "Senior Logistics Coordinator {$seq}",
            'description' => 'Oversees logistics and coordination',
        ];

        $response = $this->actingAs($actorUser)->put("/roles/{$customRole->id}", $payload);

        $response->assertRedirect(route('roles.index'));
        $customRole->refresh();
        $this->assertSame("Senior Logistics Coordinator {$seq}", $customRole->title);
        $this->assertSame('Oversees logistics and coordination', $customRole->description);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_delete_custom_role(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $seq = self::$customRoleSequence++;
        $customRole = Role::create([
            'title' => "Temporary Dispatcher {$seq}",
            'description' => 'Temporary dispatch role',
        ]);

        $response = $this->actingAs($actorUser)->delete("/roles/{$customRole->id}");

        $response->assertRedirect(route('roles.index'));
        $this->assertSoftDeleted('roles', ['id' => $customRole->id]);
    }

    // =========================================================================
    // 6. PROTECTED ROLE IMMUTABILITY (Admin, HR Manager x Canonical Roles)
    // =========================================================================

    #[DataProvider('privilegedActorsAndProtectedRolesProvider')]
    public function test_privileged_user_can_view_protected_role(string $actorRole, string $protectedRoleTitle): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $protectedRole = $this->createRole($protectedRoleTitle);

        $response = $this->actingAs($actorUser)->get("/roles/{$protectedRole->id}");

        $response->assertSuccessful();
        $response->assertSee($protectedRoleTitle);
    }

    #[DataProvider('privilegedActorsAndProtectedRolesProvider')]
    public function test_privileged_user_cannot_open_edit_form_for_protected_role(string $actorRole, string $protectedRoleTitle): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $protectedRole = $this->createRole($protectedRoleTitle);

        $response = $this->actingAs($actorUser)->get("/roles/{$protectedRole->id}/edit");

        $response->assertStatus(403);
    }

    #[DataProvider('privilegedActorsAndProtectedRolesProvider')]
    public function test_privileged_user_cannot_update_protected_role_via_direct_put(string $actorRole, string $protectedRoleTitle): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $protectedRole = $this->createRole($protectedRoleTitle);

        $originalDescription = $protectedRole->description;
        $payload = [
            'title' => "Modified {$protectedRoleTitle}",
            'description' => 'Unauthorized update attempt',
        ];

        $response = $this->actingAs($actorUser)->put("/roles/{$protectedRole->id}", $payload);

        $response->assertStatus(403);
        $protectedRole->refresh();
        $this->assertSame($protectedRoleTitle, $protectedRole->title);
        $this->assertSame($originalDescription, $protectedRole->description);
    }

    #[DataProvider('privilegedActorsAndProtectedRolesProvider')]
    public function test_privileged_user_cannot_delete_protected_role(string $actorRole, string $protectedRoleTitle): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $protectedRole = $this->createRole($protectedRoleTitle);

        $response = $this->actingAs($actorUser)->delete("/roles/{$protectedRole->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('roles', ['id' => $protectedRole->id]);
    }

    // =========================================================================
    // 7. NORMALIZED RESERVED ROLE NAMESPACE
    // =========================================================================

    #[DataProvider('privilegedActorsAndReservedAliasesProvider')]
    public function test_privileged_user_cannot_create_normalized_reserved_role_alias(string $actorRole, string $reservedAlias): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $payload = [
            'title' => $reservedAlias,
            'description' => 'Attempting to create reserved alias',
        ];

        $response = $this->actingAs($actorUser)->post('/roles', $payload);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('roles', [
            'title' => $reservedAlias,
        ]);
    }

    #[DataProvider('privilegedActorsAndRenameAliasesProvider')]
    public function test_privileged_user_cannot_rename_custom_role_to_normalized_reserved_alias(string $actorRole, string $reservedAlias): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);

        $seq = self::$customRoleSequence++;
        $customRole = Role::create([
            'title' => "Custom Workflow Role {$seq}",
            'description' => "Original description {$seq}",
        ]);

        $payload = [
            'title' => $reservedAlias,
            'description' => 'Attempting to rename to reserved alias',
        ];

        $response = $this->actingAs($actorUser)->put("/roles/{$customRole->id}", $payload);

        $response->assertStatus(403);
        $customRole->refresh();
        $this->assertSame("Custom Workflow Role {$seq}", $customRole->title);
        $this->assertSame("Original description {$seq}", $customRole->description);
    }

    // =========================================================================
    // 8. NON-PRIVILEGED ROLE ACCESS (Developer, Accountant, Data Entry, etc.)
    // =========================================================================

    #[DataProvider('nonPrivilegedRolesProvider')]
    public function test_non_privileged_user_is_denied_role_operations(string $actorRole): void
    {
        [$actorUser] = $this->createUserWithRole($actorRole);
        $customRole = Role::create([
            'title' => 'Operations Coordinator',
            'description' => 'Ordinary custom role',
        ]);

        // GET /roles
        $this->actingAs($actorUser)->get('/roles')->assertStatus(403);

        // GET /roles/{role}
        $this->actingAs($actorUser)->get("/roles/{$customRole->id}")->assertStatus(403);

        // GET /roles/create
        $this->actingAs($actorUser)->get('/roles/create')->assertStatus(403);

        // POST /roles
        $postResponse = $this->actingAs($actorUser)->post('/roles', [
            'title' => 'Self Service Unauthorized Role',
            'description' => 'Forbidden creation',
        ]);
        $postResponse->assertStatus(403);
        $this->assertDatabaseMissing('roles', ['title' => 'Self Service Unauthorized Role']);

        // GET /roles/{role}/edit
        $this->actingAs($actorUser)->get("/roles/{$customRole->id}/edit")->assertStatus(403);

        // PUT /roles/{role}
        $putResponse = $this->actingAs($actorUser)->put("/roles/{$customRole->id}", [
            'title' => 'Tampered Title',
            'description' => 'Tampered description',
        ]);
        $putResponse->assertStatus(403);
        $customRole->refresh();
        $this->assertSame('Operations Coordinator', $customRole->title);

        // DELETE /roles/{role}
        $delResponse = $this->actingAs($actorUser)->delete("/roles/{$customRole->id}");
        $delResponse->assertStatus(403);
        $this->assertNotSoftDeleted('roles', ['id' => $customRole->id]);
    }

    // =========================================================================
    // 9. ROLE FAIL-CLOSED PRINCIPAL (Unlinked User)
    // =========================================================================

    public function test_unlinked_user_is_denied_roles_index(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/roles');

        $response->assertStatus(403);
    }

    public function test_unlinked_user_is_denied_role_create_form(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/roles/create');

        $response->assertStatus(403);
    }

    public function test_unlinked_user_is_denied_direct_role_access(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        $customRole = Role::create([
            'title' => 'Warehouse Lead',
            'description' => 'Warehouse duties',
        ]);

        $response = $this->actingAs($user)->get("/roles/{$customRole->id}");

        $response->assertStatus(403);
    }

    public function test_unlinked_user_is_denied_role_store(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $initialCount = Role::count();

        $response = $this->actingAs($user)->post('/roles', [
            'title' => 'Unlinked Created Role',
            'description' => 'Should be denied',
        ]);

        $response->assertStatus(403);
        $this->assertSame($initialCount, Role::count());
        $this->assertDatabaseMissing('roles', [
            'title' => 'Unlinked Created Role',
        ]);
    }

    public function test_unlinked_user_is_denied_custom_role_update(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        $customRole = Role::create([
            'title' => 'Purchasing Agent',
            'description' => 'Purchasing duties',
        ]);

        $response = $this->actingAs($user)->put("/roles/{$customRole->id}", [
            'title' => 'Hacked Purchasing Agent',
            'description' => 'Hacked description',
        ]);

        $response->assertStatus(403);
        $customRole->refresh();
        $this->assertSame('Purchasing Agent', $customRole->title);
    }

    public function test_unlinked_user_is_denied_custom_role_delete(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        $customRole = Role::create([
            'title' => 'Inventory Clerk',
            'description' => 'Inventory duties',
        ]);

        $response = $this->actingAs($user)->delete("/roles/{$customRole->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('roles', ['id' => $customRole->id]);
    }

    // =========================================================================
    // 10. GUEST ACCESS
    // =========================================================================

    public function test_unauthenticated_guest_cannot_access_employee_endpoints(): void
    {
        [, $emp] = $this->createUserWithRole('Developer');

        $endpoints = [
            ['get', '/employees'],
            ['get', '/employees/create'],
            ['post', '/employees', []],
            ['get', "/employees/{$emp->id}"],
            ['get', "/employees/{$emp->id}/edit"],
            ['put', "/employees/{$emp->id}", []],
            ['delete', "/employees/{$emp->id}"],
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

    public function test_unauthenticated_guest_cannot_access_role_endpoints(): void
    {
        $role = $this->createRole('Developer');

        $endpoints = [
            ['get', '/roles'],
            ['get', '/roles/create'],
            ['post', '/roles', []],
            ['get', "/roles/{$role->id}"],
            ['get', "/roles/{$role->id}/edit"],
            ['put', "/roles/{$role->id}", []],
            ['delete', "/roles/{$role->id}"],
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
}
