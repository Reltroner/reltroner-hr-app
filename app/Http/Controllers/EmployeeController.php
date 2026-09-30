<?php
// app/Http/Controllers/EmployeeController.php
namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Department;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Employee::class);

        $employees = Employee::with(['department', 'role'])->latest()->get();
        return view('employees.index', compact('employees'));
    }

    public function create()
    {
        Gate::authorize('create', Employee::class);

        $departments = Department::all();
        $roles = Role::all();

        return view('employees.create', compact('departments', 'roles'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Employee::class);

        $validated = $request->validate([
            'fullname'      => 'required|string|max:255',
            'email'         => 'required|email|unique:employees,email',
            'phone'         => 'required|string|max:20',
            'address'       => 'required|string',
            'birth_date'    => 'required|date',
            'hire_date'     => 'required|date',
            'department_id' => 'required|exists:departments,id',
            'role_id'       => 'required|exists:roles,id',
            'status'        => 'required|in:active,inactive',
            'salary'        => 'required|numeric|min:0',
        ]);

        Employee::create($validated);

        return redirect()->route('employees.index')->with('success', 'Employee created successfully.');
    }

    public function show(Employee $employee)
    {
        Gate::authorize('view', $employee);

        return view('employees.show', compact('employee'));
    }

    public function edit(Employee $employee)
    {
        Gate::authorize('update', $employee);

        $departments = Department::all();
        $roles = Role::all();

        return view('employees.edit', compact('employee', 'departments', 'roles'));
    }

    public function update(Request $request, Employee $employee)
    {
        Gate::authorize('update', $employee);

        $validated = $request->validate([
            'fullname'      => 'required|string|max:255',
            'email'         => 'required|email|unique:employees,email,' . $employee->id,
            'phone'         => 'required|string|max:20',
            'address'       => 'required|string',
            'birth_date'    => 'required|date',
            'hire_date'     => 'required|date',
            'department_id' => 'required|exists:departments,id',
            'role_id'       => 'required|exists:roles,id',
            'status'        => 'required|in:active,inactive',
            'salary'        => 'required|numeric|min:0',
        ]);

        $employee->update($validated);

        return redirect()->route('employees.index')->with('success', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee)
    {
        Gate::authorize('delete', $employee);

        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'Employee deleted successfully.');
    }
}
