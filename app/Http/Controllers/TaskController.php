<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Task::class);

        if (Gate::allows('viewAll', Task::class)) {
            $tasks = Task::all();
        } else {
            $tasks = Task::where(
                'assigned_to',
                $request->user()->employee_id
            )->get();
        }

        return view('tasks.index', compact('tasks'));
    }

    public function create()
    {
        Gate::authorize('create', Task::class);

        $employees = Employee::all();

        return view('tasks.create', compact('employees'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Task::class);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'required|exists:employees,id',
            'due_date' => 'required|date|after_or_equal:today',
            'status' => 'required|in:pending,in_progress,completed',
        ]);

        Task::create($validated);

        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }

    public function show(Task $task)
    {
        Gate::authorize('view', $task);

        return view('tasks.show', compact('task'));
    }

    public function edit(Task $task)
    {
        Gate::authorize('update', $task);

        $employees = Employee::all();

        return view('tasks.edit', compact('task', 'employees'));
    }

    public function update(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'required|exists:employees,id',
            'due_date' => 'required|date|after_or_equal:today',
            'status' => 'required|in:pending,in_progress,completed',
        ]);

        $task->update($validated);

        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    public function destroy(Task $task)
    {
        Gate::authorize('delete', $task);

        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    public function markComplete(Task $task)
    {
        Gate::authorize('transitionStatus', $task);

        $task->update(['status' => 'completed']);

        return redirect()->route('tasks.index')->with('success', 'Task marked as completed.');
    }

    public function markPending(Task $task)
    {
        Gate::authorize('transitionStatus', $task);

        $task->update(['status' => 'pending']);

        return redirect()->route('tasks.index')->with('success', 'Task marked as pending.');
    }
}
