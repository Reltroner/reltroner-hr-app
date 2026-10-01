<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LeaveRequestController extends Controller
{
    /**
     * Display a listing of the leave requests.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        if (Gate::allows('viewAll', LeaveRequest::class)) {
            $leave_requests = LeaveRequest::all();
        } else {
            $leave_requests = LeaveRequest::where('employee_id', $request->user()->employee_id)->get();
        }

        return view('leave_requests.index', compact('leave_requests'));
    }

    /**
     * Show the form for creating a new leave request.
     */
    public function create()
    {
        Gate::authorize('create', LeaveRequest::class);

        $isAdministrator = Gate::allows('administer', LeaveRequest::class);
        $employees = $isAdministrator ? Employee::all() : collect();

        return view('leave_requests.create', compact('employees'));
    }

    /**
     * Store a newly created leave request in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', LeaveRequest::class);

        $isAdministrator = Gate::allows('administer', LeaveRequest::class);

        $rules = [
            'leave_type' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ];

        if ($isAdministrator) {
            $rules['employee_id'] = 'required|exists:employees,id';
            $rules['status']      = 'required|in:pending,approved,rejected';
        }

        $validated = $request->validate($rules);

        if ($isAdministrator) {
            $data = [
                'employee_id' => $validated['employee_id'],
                'leave_type'  => $validated['leave_type'],
                'start_date'  => $validated['start_date'],
                'end_date'    => $validated['end_date'],
                'status'      => $validated['status'],
            ];
        } else {
            $data = [
                'employee_id' => $request->user()->employee_id,
                'leave_type'  => $validated['leave_type'],
                'start_date'  => $validated['start_date'],
                'end_date'    => $validated['end_date'],
                'status'      => 'pending',
            ];
        }

        LeaveRequest::create($data);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request submitted successfully.');
    }

    /**
     * Display the specified leave request.
     */
    public function show(LeaveRequest $leave_request)
    {
        Gate::authorize('view', $leave_request);

        return view('leave_requests.show', compact('leave_request'));
    }

    /**
     * Approve the specified leave request.
     */
    public function approve(int $id)
    {
        $leave_request = LeaveRequest::findOrFail($id);

        Gate::authorize('approve', $leave_request);

        $leave_request->update(['status' => 'approved']);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request approved successfully.');
    }

    /**
     * Reject the specified leave request.
     */
    public function reject(int $id)
    {
        $leave_request = LeaveRequest::findOrFail($id);

        Gate::authorize('reject', $leave_request);

        $leave_request->update(['status' => 'rejected']);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request rejected successfully.');
    }

    /**
     * Show the form for editing the specified leave request.
     */
    public function edit(LeaveRequest $leave_request)
    {
        Gate::authorize('update', $leave_request);

        $isAdministrator = Gate::allows('administer', LeaveRequest::class);
        $employees = $isAdministrator ? Employee::all() : collect();

        return view('leave_requests.edit', compact('leave_request', 'employees'));
    }

    /**
     * Update the specified leave request in storage.
     */
    public function update(Request $request, LeaveRequest $leave_request)
    {
        Gate::authorize('update', $leave_request);

        $isAdministrator = Gate::allows('administer', LeaveRequest::class);

        $rules = [
            'leave_type' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ];

        if ($isAdministrator) {
            $rules['employee_id'] = 'required|exists:employees,id';
            $rules['status']      = 'required|in:pending,approved,rejected';
        }

        $validated = $request->validate($rules);

        if ($isAdministrator) {
            $data = [
                'employee_id' => $validated['employee_id'],
                'leave_type'  => $validated['leave_type'],
                'start_date'  => $validated['start_date'],
                'end_date'    => $validated['end_date'],
                'status'      => $validated['status'],
            ];
        } else {
            $data = [
                'leave_type' => $validated['leave_type'],
                'start_date' => $validated['start_date'],
                'end_date'   => $validated['end_date'],
            ];
        }

        $leave_request->update($data);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request updated successfully.');
    }

    /**
     * Remove the specified leave request from storage.
     */
    public function destroy(LeaveRequest $leave_request)
    {
        Gate::authorize('delete', $leave_request);

        $leave_request->delete();

        return redirect()->route('leave_requests.index')->with('success', 'Leave request deleted successfully.');
    }
}
