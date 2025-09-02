<?php
// app/Http/Controllers/PresenceController.php

namespace App\Http\Controllers;

use App\Models\Presence;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class PresenceController extends Controller
{
    /**
     * Display a listing of the presences.
     */
    public function index()
    {
        if(session('role') == 'Admin' || session('role') == 'HR Manager'){
            $presences = Presence::all();
        }else {
            $presences = Presence::where('employee_id', session('employee_id'))->get();
        }
        return view('presences.index', compact('presences'));
    }

    /**
     * Show the form for creating a new presence.
     */
    public function create()
    {
        $employees = Employee::all();
        return view('presences.create', compact('employees'));
    }

    /**
     * Store a newly created presence in storage.
     */
    public function store(Request $request)
    {
        if(session('role') == 'Admin' || session('role') == 'HR Manager'){

        $request->merge([
        'check_in'  => date('Y-m-d H:i:s', strtotime($request->check_in)),
        ]);

        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'check_in'    => 'required|date',
            'date'        => 'required|date',
            'status'      => 'required|in:present,absent,late,leave',
        ]);

            Presence::create([
            'employee_id' => $request->employee_id,
            'check_in'    => $request->check_in,
            'check_out'   => null, // biarkan kosong/null
            'date'        => $request->date,
            'status'      => $request->status,
        ]);
        }else {
            Presence::create([
                'employee_id' => session('employee_id'),
                'check_in'    => Carbon::now()->format('Y-m-d H:i:s'),
                'check_out'   => null, 
                'latitude'    => $request->latitude,
                'longitude'   => $request->longitude,
                'date'        => Carbon::now()->format('Y-m-d'),
                'status'      => 'present',
            ]);
        }

        return redirect()->route('presences.index')->with('success', 'Presence recorded successfully.');
    }

    /**
     * Display the specified presence.
     */
    public function show(Presence $presence)
    {
        return view('presences.show', compact('presence'));
    }

    /**
     * Show the form for editing the specified presence.
     */
    public function edit(Presence $presence)
    {
        $employees = Employee::all();
        return view('presences.edit', compact('presence', 'employees'));
    }

    /**
     * Update the specified presence in storage.
     */
    public function update(Request $request, Presence $presence)
    {
        $request->merge([
            'check_in' => date('Y-m-d H:i:s', strtotime($request->check_in)),
            'check_out' => date('Y-m-d H:i:s', strtotime($request->check_out)),
        ]);

        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'check_in'    => 'required|date_format:Y-m-d H:i:s',
            'check_out'   => 'required|date_format:Y-m-d H:i:s|after_or_equal:check_in',
            'date'        => 'required|date_format:Y-m-d',
            'status'      => 'required|in:present,absent,late,leave',
        ]);

        $presence->update($request->all());

        return redirect()->route('presences.index')->with('success', 'Presence updated successfully.');
    }

    /**
     * Remove the specified presence from storage.
     */
    public function destroy(Presence $presence)
    {
        $presence->delete();

        return redirect()->route('presences.index')->with('success', 'Presence deleted successfully.');
    }

    public function checkIn(Request $request)
    {
        $user = Auth::user();
        // kalau kamu ikat Employee via session, kita fallback ke session dulu
        $employeeId = session('employee_id');

        // fallback lain: kalau User punya relasi employee:
        if (!$employeeId && optional($user)->employee) {
            $employeeId = $user->employee->id;
        }

        if (!$employeeId) {
            return back()->withErrors(['attendance' => 'Employee context is missing.']);
        }

        // Cegah double check-in
        $today = now()->toDateString();
        $exists = \App\Models\Presence::where('employee_id',$employeeId)
            ->whereDate('date',$today)
            ->exists();

        if ($exists) {
            return back()->withErrors(['attendance' => 'You have already checked in today.']);
        }

        \App\Models\Presence::create([
            'employee_id' => $employeeId,
            'check_in'    => $today, // kolommu tipe DATE, jadi pakai Y-m-d
            'check_out'   => null,
            'date'        => $today,
            'status'      => 'present',
        ]);

        return back()->with('status', 'checked-in');
    }
}
