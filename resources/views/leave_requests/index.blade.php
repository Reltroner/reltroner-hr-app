@extends('layouts.dashboard')
<!-- resources/views/leave_requests/index.blade.php -->

@section('content')
<header class="mb-3">
    <a href="#" class="burger-btn d-block d-xl-none">
        <i class="bi bi-justify fs-3"></i>
    </a>
</header>

@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <strong>Success:</strong> {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Leave Requests</h3>
                <p class="text-subtitle text-muted">Manage employee leave applications</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Leave Requests</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card leave-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Leave Request List</h5>
                <a href="{{ route('leave_requests.create') }}" class="btn btn-primary btn-md">New Request</a>
            </div>
            <div class="card-body">
                <!-- Desktop Table -->
                <div class="d-none d-md-block">
                    <div class="table-responsive">
                        <table class="table table-striped" id="table1">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    @if(session('role') == 'Admin' || session('role') == 'HR Manager')
                                    <th>Options</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($leave_requests as $leave)
                                    <tr>
                                        <td>{{ $leave->employee->fullname ?? '-' }}</td>
                                        <td>{{ ucfirst($leave->leave_type) }}</td>
                                        <td>{{ \Carbon\Carbon::parse($leave->start_date)->format('d M Y') }}</td>
                                        <td>{{ \Carbon\Carbon::parse($leave->end_date)->format('d M Y') }}</td>
                                        <td>
                                            @if ($leave->status === 'approved')
                                                <span class="badge bg-success">Approved</span>
                                            @elseif ($leave->status === 'rejected')
                                                <span class="badge bg-danger">Rejected</span>
                                            @elseif ($leave->status === 'pending')
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            @else
                                                <span class="badge bg-secondary">{{ ucfirst($leave->status) }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if(session('role') == 'Admin' || session('role') == 'HR Manager')
                                                <a href="{{ route('leave_requests.show', $leave->id) }}" class="btn btn-info btn-sm mb-1">View</a>
                                                @if ($leave->status === 'pending' || $leave->status === 'rejected')
                                                    <form action="{{ route('leave_requests.approve', $leave->id) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success btn-sm mb-1">Approve</button>
                                                    </form>
                                                @else
                                                    <form action="{{ route('leave_requests.reject', $leave->id) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-danger btn-sm mb-1">Rejected</button>
                                                    </form>
                                                @endif
                                                <a href="{{ route('leave_requests.edit', $leave->id) }}" class="btn btn-primary btn-sm mb-1">Edit</a>
                                                <form action="{{ route('leave_requests.destroy', $leave->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure to delete this request?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-danger btn-sm mb-1">Delete</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Mobile Card Stack -->
                <div class="d-block d-md-none">
                    @foreach ($leave_requests as $leave)
                        <div class="leave-list-card mb-3 p-3 border rounded bg-white shadow-sm">
                            <div class="fw-bold mb-1">{{ $leave->employee->fullname ?? '-' }}</div>
                            <div class="text-muted" style="font-size:14px;">
                                Type: <strong>{{ ucfirst($leave->leave_type) }}</strong>
                            </div>
                            <div style="font-size:15px;">
                                <span>Start: <b>{{ \Carbon\Carbon::parse($leave->start_date)->format('d M Y') }}</b></span><br>
                                <span>End: <b>{{ \Carbon\Carbon::parse($leave->end_date)->format('d M Y') }}</b></span><br>
                                Status:
                                @if ($leave->status === 'approved')
                                    <span class="badge bg-success">Approved</span>
                                @elseif ($leave->status === 'rejected')
                                    <span class="badge bg-danger">Rejected</span>
                                @elseif ($leave->status === 'pending')
                                    <span class="badge bg-warning text-dark">Pending</span>
                                @else
                                    <span class="badge bg-secondary">{{ ucfirst($leave->status) }}</span>
                                @endif
                            </div>
                            <div class="mt-2">
                                @if(session('role') == 'Admin' || session('role') == 'HR Manager')
                                    <a href="{{ route('leave_requests.show', $leave->id) }}" class="btn btn-info btn-sm mb-1">View</a>
                                    @if ($leave->status === 'pending' || $leave->status === 'rejected')
                                        <form action="{{ route('leave_requests.approve', $leave->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm mb-1">Approve</button>
                                        </form>
                                    @else
                                        <form action="{{ route('leave_requests.reject', $leave->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-danger btn-sm mb-1">Rejected</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('leave_requests.edit', $leave->id) }}" class="btn btn-primary btn-sm mb-1">Edit</a>
                                    <form action="{{ route('leave_requests.destroy', $leave->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure to delete this request?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-danger btn-sm mb-1">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</div>

<style>
@media (max-width: 576px) {
    .leave-card {
        margin-left: 5px;
        margin-right: 5px;
    }
    .leave-list-card {
        border: 1px solid #eee;
        border-radius: 13px;
        background: #fff;
        margin-bottom: 16px;
        box-shadow: 0 1px 8px 0 rgba(180,200,230,0.07);
    }
}
</style>
@endsection
