<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthorizationSurfaceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private static int $userSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        self::$userSequence = 1;
    }

    /**
     * Deterministic fixture builder: Department -> Role -> Employee -> User.employee_id
     *
     * @return array{0: User, 1: Employee, 2: Role, 3: Department}
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

        $this->assertNotNull($user->employee);
        $this->assertSame($roleTitle, $user->employee->role->title);

        return [$user, $employee, $role, $department];
    }

    // =========================================================================
    // A. PUBLIC EMPLOYEE ENDPOINT REMOVAL CONTRACT
    // =========================================================================

    public function test_public_employee_endpoint_is_removed_and_returns_404_without_pii_disclosure(): void
    {
        $department = Department::firstOrCreate(
            ['name' => 'Confidential Operations'],
            ['status' => 'active']
        );
        $role = Role::firstOrCreate(
            ['title' => 'Developer'],
            ['description' => 'Developer role']
        );

        $distinctiveName = 'Secret Identity Person 9876';
        $distinctiveEmail = 'distinctive.secret.pii.9876@example.com';

        Employee::create([
            'fullname' => $distinctiveName,
            'email' => $distinctiveEmail,
            'phone' => '081299998888',
            'address' => 'Secret Location',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 7000000.00,
        ]);

        $response = $this->get('/api/public-employees');

        $response->assertStatus(404);
        $response->assertDontSee($distinctiveName);
        $response->assertDontSee($distinctiveEmail);
    }

    public function test_public_employee_route_is_not_registered_in_route_collection(): void
    {
        $registeredUris = array_map(
            fn ($route) => $route->uri(),
            Route::getRoutes()->getRoutes()
        );

        $this->assertNotContains(
            'api/public-employees',
            $registeredUris,
            'Route api/public-employees must not be registered in the route collection.'
        );
    }

    // =========================================================================
    // B. STALE SESSION MUST NOT ELEVATE PRIVILEGE
    // =========================================================================

    public function test_stale_admin_session_does_not_elevate_developer_to_access_departments(): void
    {
        [$developerUser, $developerEmployee] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($developerUser)
            ->withSession([
                'role' => 'Admin',
                'employee_id' => 'tampered-or-stale-99999',
            ])
            ->get('/departments');

        $response->assertStatus(403);

        $this->assertSame('Developer', session('role'));
        $this->assertSame($developerEmployee->id, session('employee_id'));
    }

    // =========================================================================
    // C. STALE SESSION MUST NOT DOWNGRADE AUTHORITATIVE ADMIN
    // =========================================================================

    public function test_stale_developer_session_does_not_downgrade_genuine_admin_access_to_departments(): void
    {
        [$adminUser, $adminEmployee] = $this->createUserWithRole('Admin');

        $response = $this->actingAs($adminUser)
            ->withSession([
                'role' => 'Developer',
                'employee_id' => 'stale-developer-emp-99999',
            ])
            ->get('/departments');

        $response->assertStatus(200);

        $this->assertSame('Admin', session('role'));
        $this->assertSame($adminEmployee->id, session('employee_id'));
    }

    // =========================================================================
    // D. users.role MUST NOT GRANT ROUTE PRIVILEGE
    // =========================================================================

    public function test_unlinked_user_with_legacy_role_admin_cannot_access_departments(): void
    {
        $user = User::factory()->create([
            'employee_id' => '0',
        ]);

        $this->assertNull($user->employee);

        $user->setAttribute('role', 'Admin');

        $this->assertSame('Admin', $user->role);

        $this->actingAs($user)
            ->get('/departments')
            ->assertStatus(403);
    }

    // =========================================================================
    // E. SESSION ROLE MUST NOT RESCUE MISSING EMPLOYEE RELATION
    // =========================================================================

    public function test_session_role_admin_does_not_rescue_unlinked_user_from_denial(): void
    {
        $user = User::factory()->create([
            'name' => 'Unlinked User Without Role',
            'email' => 'unlinked.session.test@example.com',
            'employee_id' => '0',
            'email_verified_at' => now(),
        ]);

        $this->assertNull($user->employee);

        $response = $this->actingAs($user)
            ->withSession([
                'role' => 'Admin',
                'employee_id' => 'fabricated-emp-99999',
            ])
            ->get('/departments');

        $response->assertStatus(403);
    }

    // =========================================================================
    // F. MISSING EMPLOYEE ROLE MUST FAIL CLOSED
    // =========================================================================

    public function test_missing_employee_role_with_stale_admin_session_fails_closed(): void
    {
        $department = Department::firstOrCreate(
            ['name' => 'General Operations'],
            ['status' => 'active']
        );

        $role = Role::create([
            'title' => 'Temporary Role To Delete',
            'description' => 'Temporary role',
        ]);

        $employee = Employee::create([
            'fullname' => 'Orphan Role Member',
            'email' => 'orphan.role@example.com',
            'phone' => '081200000002',
            'address' => 'Jakarta, Indonesia',
            'birth_date' => '1990-01-01',
            'hire_date' => '2025-01-01',
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'salary' => 5000000.00,
        ]);

        $user = User::factory()->create([
            'name' => 'Orphan Role User',
            'email' => 'orphan.user@example.com',
            'email_verified_at' => now(),
            'employee_id' => $employee->id,
        ]);

        // Soft-delete the Role so that employee->role resolves to null via SoftDeletingScope
        $role->delete();

        $employee->unsetRelation('role');
        $user->unsetRelation('employee');

        $this->assertNotNull($user->employee);
        $this->assertNull($user->employee->role);

        $response = $this->actingAs($user)
            ->withSession([
                'role' => 'Admin',
                'employee_id' => $employee->id,
            ])
            ->get('/departments');

        $response->assertStatus(403);
    }

    // =========================================================================
    // G. NORMAL AUTHORITATIVE PRINCIPALS REMAIN COMPATIBLE
    // =========================================================================

    public function test_genuine_admin_can_access_departments(): void
    {
        [$adminUser] = $this->createUserWithRole('Admin');

        $response = $this->actingAs($adminUser)->get('/departments');

        $response->assertStatus(200);
    }

    public function test_genuine_hr_manager_can_access_departments(): void
    {
        [$hrManagerUser] = $this->createUserWithRole('HR Manager');

        $response = $this->actingAs($hrManagerUser)->get('/departments');

        $response->assertStatus(200);
    }

    public function test_genuine_developer_is_denied_departments(): void
    {
        [$developerUser] = $this->createUserWithRole('Developer');

        $response = $this->actingAs($developerUser)->get('/departments');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_guest_is_redirected_to_login_from_departments(): void
    {
        $response = $this->get('/departments');

        $response->assertRedirect('/login');
    }

    // =========================================================================
    // H. DERIVED SESSION COMPATIBILITY REMAINS
    // =========================================================================

    public function test_genuine_admin_populates_derived_session_role_and_employee_id(): void
    {
        [$adminUser, $adminEmployee] = $this->createUserWithRole('Admin');

        $response = $this->actingAs($adminUser)->get('/departments');

        $response->assertStatus(200);
        $this->assertSame('Admin', session('role'));
        $this->assertSame($adminEmployee->id, session('employee_id'));
    }

    // =========================================================================
    // I. EMPTY ALLOWED-ROLE DECLARATION FAILS CLOSED
    // =========================================================================

    public function test_check_role_with_empty_allowed_roles_fails_closed(): void
    {
        [$adminUser] = $this->createUserWithRole('Admin');

        $middleware = new CheckRole();
        $request = Request::create('/departments', 'GET');
        $request->setUserResolver(fn () => $adminUser);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return response('OK', 200);
        };

        try {
            $response = $middleware->handle($request, $next);
            $this->assertFalse(
                $nextCalled,
                'CheckRole middleware called $next when allowed roles list was empty.'
            );
            $this->assertSame(403, $response->getStatusCode());
        } catch (HttpException $e) {
            $this->assertFalse(
                $nextCalled,
                'CheckRole middleware called $next when allowed roles list was empty.'
            );
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
