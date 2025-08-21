<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Employee;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    /**
     * Display a listing of the leave requests.
     */
    public function index()
    {
        if (session('role') === 'Admin' || session('role') === 'HR Manager') {
            $leave_requests = LeaveRequest::all();
        } else {
            $leave_requests = LeaveRequest::where('employee_id', session('employee_id'))->get();
        }

        return view('leave_requests.index', compact('leave_requests'));
    }

    /**
     * Show the form for creating a new leave request.
     */
    public function create()
    {
        $employees = Employee::all();
        return view('leave_requests.create', compact('employees'));
    }

    /**
     * Store a newly created leave request in storage.
     */
    public function store(Request $request)
    {
        $isPrivileged = session('role') === 'Admin' || session('role') === 'HR Manager';

        $request->validate([
            'leave_type'  => 'required|string|max:255',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'employee_id' => $isPrivileged ? 'required|exists:employees,id' : '',
            'status'      => $isPrivileged ? 'required|in:pending,approved,rejected' : '',
        ]);

        $data = [
            'employee_id' => $isPrivileged ? $request->employee_id : session('employee_id'),
            'leave_type'  => $request->leave_type,
            'start_date'  => $request->start_date,
            'end_date'    => $request->end_date,
            'status'      => $isPrivileged ? $request->status : 'pending',
        ];

        LeaveRequest::create($data);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request submitted successfully.');
    }

    /**
     * Display the specified leave request.
     */
    public function show(LeaveRequest $leave_request)
    {
        return view('leave_requests.show', compact('leave_request'));
    }

    /**
     * Approve the specified leave request.
     */
    public function approve(int $id)
    {
        $leave_request = LeaveRequest::findOrFail($id);
        $leave_request->update(['status' => 'approved']);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request approved successfully.');
    }

    /**
     * Reject the specified leave request.
     */
    public function reject(int $id)
    {
        $leave_request = LeaveRequest::findOrFail($id);
        $leave_request->update(['status' => 'rejected']);

        return redirect()->route('leave_requests.index')->with('success', 'Leave request rejected successfully.');
    }

    /**
     * Show the form for editing the specified leave request.
     */
    public function edit(LeaveRequest $leave_request)
    {
        $employees = Employee::all();
        return view('leave_requests.edit', compact('leave_request', 'employees'));
    }

    /**
     * Update the specified leave request in storage.
     */
    public function update(Request $request, LeaveRequest $leave_request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type'  => 'required|string|max:255',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'status'      => 'required|in:pending,approved,rejected',
        ]);

        $leave_request->update($request->all());

        return redirect()->route('leave_requests.index')->with('success', 'Leave request updated successfully.');
    }

    /**
     * Remove the specified leave request from storage.
     */
    public function destroy(LeaveRequest $leave_request)
    {
        $leave_request->delete();

        return redirect()->route('leave_requests.index')->with('success', 'Leave request deleted successfully.');
    }
}
