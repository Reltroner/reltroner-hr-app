<?php
// app/Http/Controllers/DashboardController.php
namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Presence;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Task::class);

        $employees = Employee::count();
        $departments = Department::count();
        $payrolls = Payroll::count();
        $presences = Presence::count();

        if (Gate::allows('viewAll', Task::class)) {
            $tasks = Task::all();
        } else {
            $tasks = Task::where(
                'assigned_to',
                $request->user()->employee_id
            )->get();
        }

        return view('dashboard.index', compact('employees', 'departments', 'payrolls', 'presences', 'tasks'));
    }

    public function presence()
    {
        $statuses = ['present', 'absent', 'late', 'leave'];

        $response = array_fill_keys(
            $statuses,
            array_fill(0, 12, 0)
        );

        for ($month = 1; $month <= 12; $month++) {
            $counts = Presence::query()
                ->select('status')
                ->selectRaw('COUNT(*) as total')
                ->whereIn('status', $statuses)
                ->whereMonth('date', $month)
                ->groupBy('status')
                ->pluck('total', 'status');

            foreach ($counts as $status => $total) {
                $response[$status][$month - 1] = (int) $total;
            }
        }

        return response()->json($response);
    }
}
