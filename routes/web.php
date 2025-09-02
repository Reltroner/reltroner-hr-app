<?php
// hrm.reltroner.com: routes/web.php
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use App\Models\Employee;

/**
 * Redirect root URL to tasks index
 */
Route::get('/', function () {
    return redirect()->route('login');
});

/**
 * Dashboard page (now uses controller to send data)
 */
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer'])
    ->name('dashboard');

Route::get('/dashboard/presence', [DashboardController::class, 'presence']);
/**
 * Authenticated routes group
 */
Route::middleware(['auth'])->group(function () {

    /**
     * Profile management routes
     */
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /**
     * Employee management routes (CRUD)
     */
    Route::resource('/departments', DepartmentController::class)->middleware(['role:Admin,HR Manager']);
    Route::resource('/employees', EmployeeController::class)->middleware(['role:Admin,HR Manager']);

    /**
     * Leave requests routes
     */
    Route::resource('/leave_requests', LeaveRequestController::class)
        ->middleware(['role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer']);
    Route::get('/leave_requests/approve/{id}', [LeaveRequestController::class, 'approve'])
        ->name('leave_requests.approve')
        ->middleware(['role:Admin,HR Manager']);
    Route::get('/leave_requests/reject/{id}', [LeaveRequestController::class, 'reject'])
        ->name('leave_requests.reject')
        ->middleware(['role:Admin,HR Manager']);

    /**
     * Payroll and presence routes
     */
    Route::resource('/payrolls', PayrollController::class)->middleware(['role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer']);
    Route::resource('/presences', PresenceController::class)->middleware(['role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer']);

    /**
     * Role management routes
     */
    Route::resource('/roles', RoleController::class)->middleware(['role:Admin,HR Manager']);

    /**
     * Task management routes
     */
    Route::resource('tasks', TaskController::class)->middleware(['role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer']);
    Route::get('/tasks/{task}/mark-complete', [TaskController::class, 'markComplete'])->name('tasks.markComplete')->middleware(['role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer']);
    Route::get('/tasks/{task}/mark-pending', [TaskController::class, 'markPending'])->name('tasks.markPending')->middleware(['role:Admin,HR Manager,Developer,Accountant,Data Entry,Animator,Marketer']);
});

Route::get('/api/public-employees', function () {
    return Response::json(Employee::select('id', 'fullname', 'email', 'department_id')->get());
});

// routes/web.php (dalam group auth)
Route::post('/attendance/check-in', [PresenceController::class, 'checkIn'])
    ->name('attendance.checkin')
    ->middleware(['auth']);

require __DIR__.'/auth.php';
