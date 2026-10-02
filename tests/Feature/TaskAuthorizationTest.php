<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSequence = 1;
    private static int $taskSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 08:30:00');
        self::$userSequence = 1;
        self::$taskSequence = 1;
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
     * Deterministic task record creator
     */
    private function createTask(Employee $employee, array $overrides = []): Task
    {
        $seq = self::$taskSequence++;

        return Task::create(array_merge([
            'title' => "Task {$seq} for {$employee->fullname}",
            'description' => "Detailed task description {$seq}",
            'assigned_to' => $employee->id,
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * Data provider for privileged task administrator roles
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
    public function test_privileged_user_can_list_all_tasks(string $role): void
    {
        [$adminUser, $adminEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createTask($adminEmp, ['title' => "Own Task {$role}"]);
        $this->createTask($otherEmp, ['title' => "Other Colleague Task {$role}"]);

        $response = $this->actingAs($adminUser)->get('/tasks');

        $response->assertSuccessful();
        $response->assertSee("Own Task {$role}");
        $response->assertSee("Other Colleague Task {$role}");
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_view_another_employees_task(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp, ['title' => "Confidential Task {$role}"]);

        $response = $this->actingAs($adminUser)->get("/tasks/{$otherTask->id}");

        $response->assertSuccessful();
        $response->assertSee("Confidential Task {$role}");
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_task_create_form(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);

        $response = $this->actingAs($adminUser)->get('/tasks/create');

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_create_task_assigned_to_another_employee_with_explicit_fields(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $dueDate = Carbon::now()->addDays(7)->toDateString();
        $payload = [
            'title' => "Assigned Task by {$role}",
            'description' => "Detailed assignment description by {$role}",
            'assigned_to' => $otherEmp->id,
            'due_date' => $dueDate,
            'status' => 'in_progress',
        ];

        $response = $this->actingAs($adminUser)->post('/tasks', $payload);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'title' => "Assigned Task by {$role}",
            'description' => "Detailed assignment description by {$role}",
            'assigned_to' => $otherEmp->id,
            'due_date' => $dueDate,
            'status' => 'in_progress',
        ]);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_open_task_edit_form(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp);

        $response = $this->actingAs($adminUser)->get("/tasks/{$otherTask->id}/edit");

        $response->assertSuccessful();
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_update_all_task_fields(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $firstEmp] = $this->createUserWithRole('Developer');
        [, $secondEmp] = $this->createUserWithRole('Accountant');

        $task = $this->createTask($firstEmp, [
            'title' => "Original Task {$role}",
            'description' => 'Original description',
            'status' => 'pending',
        ]);

        $updatedDueDate = Carbon::now()->addDays(14)->toDateString();
        $payload = [
            'title' => "Updated Task {$role}",
            'description' => 'Reassigned and updated description',
            'assigned_to' => $secondEmp->id,
            'due_date' => $updatedDueDate,
            'status' => 'completed',
        ];

        $response = $this->actingAs($adminUser)->put("/tasks/{$task->id}", $payload);

        $response->assertRedirect(route('tasks.index'));
        $task->refresh();
        $this->assertSame("Updated Task {$role}", $task->title);
        $this->assertSame('Reassigned and updated description', $task->description);
        $this->assertSame($secondEmp->id, $task->assigned_to);
        $this->assertSame($updatedDueDate, $task->due_date);
        $this->assertSame('completed', $task->status);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_delete_task(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp);

        $response = $this->actingAs($adminUser)->delete("/tasks/{$otherTask->id}");

        $response->assertRedirect(route('tasks.index'));
        $this->assertSoftDeleted('tasks', ['id' => $otherTask->id]);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_mark_complete_any_task(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp, ['status' => 'pending']);

        $response = $this->actingAs($adminUser)->post("/tasks/{$otherTask->id}/mark-complete");

        $response->assertRedirect(route('tasks.index'));
        $otherTask->refresh();
        $this->assertSame('completed', $otherTask->status);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_can_mark_pending_any_task(string $role): void
    {
        [$adminUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp, ['status' => 'completed']);

        $response = $this->actingAs($adminUser)->post("/tasks/{$otherTask->id}/mark-pending");

        $response->assertRedirect(route('tasks.index'));
        $otherTask->refresh();
        $this->assertSame('pending', $otherTask->status);
    }

    #[DataProvider('privilegedRolesProvider')]
    public function test_privileged_user_dashboard_exposes_all_tasks(string $role): void
    {
        [$adminUser, $adminEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createTask($adminEmp, [
            'title' => "Own Admin Task {$role}",
        ]);
        $this->createTask($otherEmp, [
            'title' => "Other Employee Task {$role}",
        ]);

        $response = $this->actingAs($adminUser)->get('/dashboard');

        $response->assertSuccessful();
        $response->assertSee("Own Admin Task {$role}");
        $response->assertSee("Other Employee Task {$role}");
        $response->assertSee($otherEmp->fullname);
    }

    // =========================================================================
    // SELF-SERVICE ROLES TESTS (Developer, Accountant, Data Entry, Animator, Marketer)
    // =========================================================================

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_access_tasks_index_and_sees_only_own_tasks(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createTask($selfEmp, ['title' => "Own Self Task {$role}"]);
        $this->createTask($otherEmp, ['title' => "Other Colleague Task {$role}"]);

        $response = $this->actingAs($selfUser)->get('/tasks');

        $response->assertSuccessful();
        $response->assertSee("Own Self Task {$role}");
        $response->assertDontSee("Other Colleague Task {$role}");
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_view_own_task(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);

        $ownTask = $this->createTask($selfEmp, ['title' => "Own Task {$role}"]);

        $response = $this->actingAs($selfUser)->get("/tasks/{$ownTask->id}");

        $response->assertSuccessful();
        $response->assertSee("Own Task {$role}");
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_view_another_employees_task_by_direct_id(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp, ['title' => "Restricted Task {$role}"]);

        $response = $this->actingAs($selfUser)->get("/tasks/{$otherTask->id}");

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_open_task_create_form(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);

        $response = $this->actingAs($selfUser)->get('/tasks/create');

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_create_task(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $payload = [
            'title' => "Tampered Task {$role}",
            'description' => "Unauthorized creation attempt by {$role}",
            'assigned_to' => $otherEmp->id,
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'status' => 'completed',
        ];

        $response = $this->actingAs($selfUser)->post('/tasks', $payload);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('tasks', [
            'title' => "Tampered Task {$role}",
        ]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_open_edit_form_for_own_task(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownTask = $this->createTask($selfEmp);

        $response = $this->actingAs($selfUser)->get("/tasks/{$ownTask->id}/edit");

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_open_edit_form_for_another_task(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherTask = $this->createTask($otherEmp);

        $response = $this->actingAs($selfUser)->get("/tasks/{$otherTask->id}/edit");

        $response->assertStatus(403);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_update_own_task_via_resource_update(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $ownTask = $this->createTask($selfEmp, [
            'title' => "Original Own Title {$role}",
            'description' => 'Original description',
            'status' => 'pending',
        ]);

        $payload = [
            'title' => "Tampered Title {$role}",
            'description' => 'Tampered description',
            'assigned_to' => $otherEmp->id,
            'due_date' => Carbon::now()->addDays(10)->toDateString(),
            'status' => 'completed',
        ];

        $response = $this->actingAs($selfUser)->put("/tasks/{$ownTask->id}", $payload);

        $response->assertStatus(403);
        $ownTask->refresh();
        $this->assertSame("Original Own Title {$role}", $ownTask->title);
        $this->assertSame('Original description', $ownTask->description);
        $this->assertSame($selfEmp->id, $ownTask->assigned_to);
        $this->assertSame('pending', $ownTask->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_update_another_task_via_resource_update(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $otherTask = $this->createTask($otherEmp, [
            'title' => "Other Colleague Task {$role}",
            'description' => 'Original description',
            'status' => 'pending',
        ]);

        $payload = [
            'title' => "Hacked Title {$role}",
            'description' => 'Hacked description',
            'assigned_to' => $otherEmp->id,
            'due_date' => Carbon::now()->addDays(10)->toDateString(),
            'status' => 'completed',
        ];

        $response = $this->actingAs($selfUser)->put("/tasks/{$otherTask->id}", $payload);

        $response->assertStatus(403);
        $otherTask->refresh();
        $this->assertSame("Other Colleague Task {$role}", $otherTask->title);
        $this->assertSame('Original description', $otherTask->description);
        $this->assertSame('pending', $otherTask->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_delete_own_task(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownTask = $this->createTask($selfEmp);

        $response = $this->actingAs($selfUser)->delete("/tasks/{$ownTask->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('tasks', ['id' => $ownTask->id]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_delete_another_task(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherTask = $this->createTask($otherEmp);

        $response = $this->actingAs($selfUser)->delete("/tasks/{$otherTask->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('tasks', ['id' => $otherTask->id]);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_mark_complete_own_task(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownTask = $this->createTask($selfEmp, ['status' => 'pending']);

        $response = $this->actingAs($selfUser)->post("/tasks/{$ownTask->id}/mark-complete");

        $response->assertRedirect(route('tasks.index'));
        $ownTask->refresh();
        $this->assertSame('completed', $ownTask->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_can_mark_pending_own_task(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        $ownTask = $this->createTask($selfEmp, ['status' => 'completed']);

        $response = $this->actingAs($selfUser)->post("/tasks/{$ownTask->id}/mark-pending");

        $response->assertRedirect(route('tasks.index'));
        $ownTask->refresh();
        $this->assertSame('pending', $ownTask->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_mark_complete_another_task(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherTask = $this->createTask($otherEmp, ['status' => 'pending']);

        $response = $this->actingAs($selfUser)->post("/tasks/{$otherTask->id}/mark-complete");

        $response->assertStatus(403);
        $otherTask->refresh();
        $this->assertSame('pending', $otherTask->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_cannot_mark_pending_another_task(string $role): void
    {
        [$selfUser] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');
        $otherTask = $this->createTask($otherEmp, ['status' => 'completed']);

        $response = $this->actingAs($selfUser)->post("/tasks/{$otherTask->id}/mark-pending");

        $response->assertStatus(403);
        $otherTask->refresh();
        $this->assertSame('completed', $otherTask->status);
    }

    #[DataProvider('selfServiceRolesProvider')]
    public function test_self_service_user_dashboard_sees_only_own_task_scope(string $role): void
    {
        [$selfUser, $selfEmp] = $this->createUserWithRole($role);
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $this->createTask($selfEmp, [
            'title' => "Own Task Scope {$role}",
        ]);
        $this->createTask($otherEmp, [
            'title' => "Other Confidential Scope {$role}",
        ]);

        $response = $this->actingAs($selfUser)->get('/dashboard');

        $response->assertSuccessful();
        $response->assertSee("Own Task Scope {$role}");
        $response->assertDontSee("Other Confidential Scope {$role}");
        $response->assertDontSee($otherEmp->fullname);
    }

    // =========================================================================
    // FAIL-CLOSED & DEFENSE-IN-DEPTH TESTS
    // =========================================================================

    public function test_unauthenticated_guest_cannot_access_any_task_endpoint(): void
    {
        [, $emp] = $this->createUserWithRole('Developer');
        $task = $this->createTask($emp);

        $endpoints = [
            ['get', '/tasks'],
            ['get', '/tasks/create'],
            ['post', '/tasks', []],
            ['get', "/tasks/{$task->id}"],
            ['get', "/tasks/{$task->id}/edit"],
            ['put', "/tasks/{$task->id}", []],
            ['delete', "/tasks/{$task->id}"],
            ['post', "/tasks/{$task->id}/mark-complete", []],
            ['post', "/tasks/{$task->id}/mark-pending", []],
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

    public function test_user_with_unsupported_role_is_denied_task_access(): void
    {
        [$contractorUser] = $this->createUserWithRole('Contractor');
        [, $devEmp] = $this->createUserWithRole('Developer');
        $task = $this->createTask($devEmp);

        $this->actingAs($contractorUser)->get('/tasks')->assertStatus(403);
        $this->actingAs($contractorUser)->get('/tasks/create')->assertStatus(403);
        $this->actingAs($contractorUser)->post('/tasks', [])->assertStatus(403);
        $this->actingAs($contractorUser)->get("/tasks/{$task->id}")->assertStatus(403);
        $this->actingAs($contractorUser)->get("/tasks/{$task->id}/edit")->assertStatus(403);
        $this->actingAs($contractorUser)->put("/tasks/{$task->id}", [])->assertStatus(403);
        $this->actingAs($contractorUser)->delete("/tasks/{$task->id}")->assertStatus(403);
        $this->actingAs($contractorUser)->post("/tasks/{$task->id}/mark-complete")->assertStatus(403);
        $this->actingAs($contractorUser)->post("/tasks/{$task->id}/mark-pending")->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_task_index(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/tasks');

        $response->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_task_create_form(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');

        $response = $this->actingAs($user)->get('/tasks/create');

        $response->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_direct_task_access(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');
        $task = $this->createTask($devEmp);

        $response = $this->actingAs($user)->get("/tasks/{$task->id}");

        $response->assertStatus(403);
    }

    public function test_user_without_employee_relationship_is_denied_task_store(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');

        $initialCount = Task::count();

        $response = $this->actingAs($user)->post('/tasks', [
            'title' => 'Unlinked Task',
            'description' => 'Should be denied',
            'assigned_to' => $devEmp->id,
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'status' => 'pending',
        ]);

        $response->assertStatus(403);
        $this->assertSame($initialCount, Task::count());
        $this->assertDatabaseMissing('tasks', [
            'title' => 'Unlinked Task',
        ]);
    }

    public function test_user_without_employee_relationship_is_denied_mark_complete(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');
        $task = $this->createTask($devEmp, ['status' => 'pending']);

        $response = $this->actingAs($user)->post("/tasks/{$task->id}/mark-complete");

        $response->assertStatus(403);
        $task->refresh();
        $this->assertSame('pending', $task->status);
    }

    public function test_user_without_employee_relationship_is_denied_mark_pending(): void
    {
        $user = $this->createUnlinkedUserWithLegacyRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');
        $task = $this->createTask($devEmp, ['status' => 'completed']);

        $response = $this->actingAs($user)->post("/tasks/{$task->id}/mark-pending");

        $response->assertStatus(403);
        $task->refresh();
        $this->assertSame('completed', $task->status);
    }

    public function test_privileged_user_cannot_mutate_task_via_legacy_get_mark_complete(): void
    {
        [$adminUser] = $this->createUserWithRole('Admin');
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $task = $this->createTask($otherEmp, ['status' => 'pending']);

        $response = $this->actingAs($adminUser)->get("/tasks/{$task->id}/mark-complete");

        $response->assertStatus(405);
        $task->refresh();
        $this->assertSame('pending', $task->status);
    }

    public function test_privileged_user_cannot_mutate_task_via_legacy_get_mark_pending(): void
    {
        [$adminUser] = $this->createUserWithRole('Admin');
        [, $otherEmp] = $this->createUserWithRole('Developer');

        $task = $this->createTask($otherEmp, ['status' => 'completed']);

        $response = $this->actingAs($adminUser)->get("/tasks/{$task->id}/mark-pending");

        $response->assertStatus(405);
        $task->refresh();
        $this->assertSame('completed', $task->status);
    }

    public function test_task_index_renders_transition_controls_as_csrf_protected_post_forms(): void
    {
        [$adminUser, $adminEmp] = $this->createUserWithRole('Admin');
        [, $devEmp] = $this->createUserWithRole('Developer');

        $pendingTask = $this->createTask($devEmp, ['status' => 'pending']);
        $completedTask = $this->createTask($devEmp, ['status' => 'completed']);

        $response = $this->actingAs($adminUser)->get('/tasks');

        $response->assertSuccessful();

        $content = $response->getContent();

        $markCompleteUrl = route('tasks.markComplete', $pendingTask->id);
        $markPendingUrl = route('tasks.markPending', $completedTask->id);

        // Prove mark-complete action points to named route and uses POST
        $this->assertStringContainsString('action="' . $markCompleteUrl . '"', $content);
        $this->assertMatchesRegularExpression('/<form[^>]+action="' . preg_quote($markCompleteUrl, '/') . '"[^>]+method="POST"/i', $content);

        // Prove mark-pending action points to named route and uses POST
        $this->assertStringContainsString('action="' . $markPendingUrl . '"', $content);
        $this->assertMatchesRegularExpression('/<form[^>]+action="' . preg_quote($markPendingUrl, '/') . '"[^>]+method="POST"/i', $content);

        // Prove request-token hidden input generated by @csrf is present
        $this->assertMatchesRegularExpression('/<input[^>]+type="hidden"[^>]+name="_token"/i', $content);

        // Prove submit controls with expected labels are present
        $this->assertStringContainsString('Mark Complete', $content);
        $this->assertStringContainsString('Mark Pending', $content);

        // Prove no state-changing mark-complete/mark-pending anchor tags remain
        $this->assertStringNotContainsString('<a href="' . $markCompleteUrl . '"', $content);
        $this->assertStringNotContainsString('<a href="' . $markPendingUrl . '"', $content);
        $this->assertDoesNotMatchRegularExpression('/<a\s+[^>]*href=["\'][^"\']*\/tasks\/\d+\/(mark-complete|mark-pending)/i', $content);
    }
}
