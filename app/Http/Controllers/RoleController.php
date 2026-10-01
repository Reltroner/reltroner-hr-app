<?php
// app/Http/Controllers/RoleController.php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    /**
     * Display a listing of the roles.
     */
    public function index()
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::latest()->get();
        return view('roles.index', compact('roles'));
    }

    /**
     * Show the form for creating a new role.
     */
    public function create()
    {
        Gate::authorize('create', Role::class);

        return view('roles.create');
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Role::class);

        $validated = $request->validate([
            'title'       => 'required|string|max:255|unique:roles,title',
            'description' => 'nullable|string',
        ]);

        Gate::authorize('create', [Role::class, $validated['title']]);

        Role::create($validated);

        return redirect()->route('roles.index')
            ->with('success', 'Role created successfully.');
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role)
    {
        Gate::authorize('view', $role);

        return view('roles.show', compact('role'));
    }

    /**
     * Show the form for editing the specified role.
     */
    public function edit(Role $role)
    {
        Gate::authorize('update', $role);

        return view('roles.edit', compact('role'));
    }

    /**
     * Update the specified role in storage.
     */
    public function update(Request $request, Role $role)
    {
        Gate::authorize('update', $role);

        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        Gate::authorize('update', [$role, $validated['title']]);

        $role->update($validated);

        return redirect()->route('roles.index')->with('success', 'Role updated successfully.');
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(Role $role)
    {
        Gate::authorize('delete', $role);

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role deleted successfully.');
    }
}
