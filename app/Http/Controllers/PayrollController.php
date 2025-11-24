<?php
// app/Http/Controllers/PayrollController.php
namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PayrollController extends Controller
{
    public function index()
    {
        // Use session role for backward compatibility with existing app
        $role = session('role');

        if ($role === 'Admin' || $role === 'HR Manager') {
            // eager load employee to avoid n+1 when rendering views
            $payrolls = Payroll::with('employee')->orderByDesc('payment_date')->get();
        } else {
            $employeeId = session('employee_id');
            $payrolls = Payroll::with('employee')
                ->where('employee_id', $employeeId)
                ->orderByDesc('payment_date')
                ->get();
        }

        return view('payrolls.index', compact('payrolls'));
    }

    public function create()
    {
        $employees = Employee::orderBy('fullname')->get();
        return view('payrolls.create', compact('employees'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id'  => 'required|exists:employees,id',
            'salary'       => 'required|numeric|min:0',
            'bonus'        => 'nullable|numeric|min:0',
            'deduction'    => 'nullable|numeric|min:0',
            'payment_date' => 'required|date',
        ]);

        // only pick expected fields to avoid mass-assignment of unintended attributes
        $payload = $request->only(['employee_id', 'salary', 'bonus', 'deduction', 'payment_date']);

        // normalize numeric values and defaults
        $payload['salary'] = (float) $payload['salary'];
        $payload['bonus'] = isset($payload['bonus']) ? (float) $payload['bonus'] : 0;
        $payload['deduction'] = isset($payload['deduction']) ? (float) $payload['deduction'] : 0;

        // normalize date to Y-m-d to make duplicate checking consistent
        $payload['payment_date'] = Carbon::parse($payload['payment_date'])->toDateString();

        // calculate net salary
        $payload['net_salary'] = $payload['salary'] + $payload['bonus'] - $payload['deduction'];

        // Prevent duplicate payroll for same employee & date (optional business rule)
        $exists = Payroll::where('employee_id', $payload['employee_id'])
            ->whereDate('payment_date', $payload['payment_date'])
            ->exists();

        if ($exists) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['payment_date' => 'Payroll for this employee on this payment date already exists.']);
        }

        try {
            DB::transaction(function () use ($payload) {
                Payroll::create($payload);
                // if you have events/listeners e.g. PayrollCreated, dispatch them here
                // event(new \App\Events\PayrollCreated($payroll));
            });
        } catch (\Throwable $e) {
            Log::error('Failed to create payroll', ['error' => $e->getMessage(), 'payload' => $payload]);
            return redirect()->back()->withInput()->withErrors(['general' => 'Failed to create payroll. Please try again or contact admin.']);
        }

        return redirect()->route('payrolls.index')->with('success', 'Payroll record created successfully.');
    }

    public function show(Payroll $payroll)
    {
        $payroll->load('employee');
        return view('payrolls.show', compact('payroll'));
    }

    public function edit(Payroll $payroll)
    {
        $employees = Employee::orderBy('fullname')->get();
        return view('payrolls.edit', compact('payroll', 'employees'));
    }

    public function update(Request $request, Payroll $payroll)
    {
        $validated = $request->validate([
            'employee_id'  => 'required|exists:employees,id',
            'salary'       => 'required|numeric|min:0',
            'bonus'        => 'nullable|numeric|min:0',
            'deduction'    => 'nullable|numeric|min:0',
            'payment_date' => 'required|date',
        ]);

        $payload = $request->only(['employee_id', 'salary', 'bonus', 'deduction', 'payment_date']);
        $payload['salary'] = (float) $payload['salary'];
        $payload['bonus'] = isset($payload['bonus']) ? (float) $payload['bonus'] : 0;
        $payload['deduction'] = isset($payload['deduction']) ? (float) $payload['deduction'] : 0;
        $payload['payment_date'] = Carbon::parse($payload['payment_date'])->toDateString();
        $payload['net_salary'] = $payload['salary'] + $payload['bonus'] - $payload['deduction'];

        // check duplicate possibility (another record for same employee/date)
        $exists = Payroll::where('employee_id', $payload['employee_id'])
            ->whereDate('payment_date', $payload['payment_date'])
            ->where('id', '!=', $payroll->id)
            ->exists();

        if ($exists) {
            return redirect()->back()->withInput()->withErrors(['payment_date' => 'Another payroll for this employee on this payment date already exists.']);
        }

        try {
            DB::transaction(function () use ($payroll, $payload) {
                $payroll->update($payload);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to update payroll', ['error' => $e->getMessage(), 'payload' => $payload, 'payroll_id' => $payroll->id]);
            return redirect()->back()->withInput()->withErrors(['general' => 'Failed to update payroll. Please try again or contact admin.']);
        }

        return redirect()->route('payrolls.index')->with('success', 'Payroll record updated successfully.');
    }

    public function destroy(Payroll $payroll)
    {
        try {
            DB::transaction(function () use ($payroll) {
                $payroll->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Failed to delete payroll', ['error' => $e->getMessage(), 'payroll_id' => $payroll->id]);
            return redirect()->route('payrolls.index')->withErrors(['general' => 'Failed to delete payroll.']);
        }

        return redirect()->route('payrolls.index')->with('success', 'Payroll record deleted successfully.');
    }
}
