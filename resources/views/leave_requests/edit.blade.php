<!-- resources/views/leave_requests/edit.blade.php -->

@extends('layouts.dashboard')

@section('content')
<header class="mb-3">
    <a href="#" class="burger-btn d-block d-xl-none">
        <i class="bi bi-justify fs-3"></i>
    </a>
</header>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Edit Leave Request</h3>
                <p class="text-subtitle text-muted">Update details of the selected leave request</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('leave_requests.index') }}">Leave Requests</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Edit Leave Request Form</h5>
            </div>

            <div class="card-body">
                <form action="{{ route('leave_requests.update', $leave_request->id) }}" method="POST">
                    @csrf
                    @method('PUT')

                    {{-- Employee selector only for Admin / HR --}}
                    @if(session('role') === 'Admin' || session('role') === 'HR Manager')
                        <div class="mb-3">
                            <label for="employee_id" class="form-label">Employee</label>
                            <select name="employee_id" id="employee_id" class="form-select @error('employee_id') is-invalid @enderror">
                                <option value="">-- Select Employee --</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" {{ old('employee_id', $leave_request->employee_id) == $employee->id ? 'selected' : '' }}>
                                        {{ $employee->fullname }}
                                    </option>
                                @endforeach
                            </select>
                            @error('employee_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @else
                        {{-- For normal users, keep employee_id hidden --}}
                        <input type="hidden" name="employee_id" value="{{ $leave_request->employee_id }}">
                    @endif

                    {{-- Leave Type --}}
                    <div class="mb-3">
                        <label for="leave_type" class="form-label">Leave Type</label>
                        <input type="text" name="leave_type" id="leave_type" class="form-control @error('leave_type') is-invalid @enderror" value="{{ old('leave_type', $leave_request->leave_type) }}">
                        @error('leave_type')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Start Date --}}
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control date @error('start_date') is-invalid @enderror" value="{{ old('start_date', optional($leave_request->start_date)->format('Y-m-d')) }}">
                        @error('start_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- End Date --}}
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" name="end_date" id="end_date" class="form-control date @error('end_date') is-invalid @enderror" value="{{ old('end_date', optional($leave_request->end_date)->format('Y-m-d')) }}">
                        @error('end_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Status selector only for Admin / HR --}}
                    @if(session('role') === 'Admin' || session('role') === 'HR Manager')
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select name="status" id="status" class="form-select @error('status') is-invalid @enderror">
                                <option value="pending"  {{ old('status', $leave_request->status) == 'pending'  ? 'selected' : '' }}>Pending</option>
                                <option value="approved" {{ old('status', $leave_request->status) == 'approved' ? 'selected' : '' }}>Approved</option>
                                <option value="rejected" {{ old('status', $leave_request->status) == 'rejected' ? 'selected' : '' }}>Rejected</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @else
                        {{-- Keep current status hidden for normal users --}}
                        <input type="hidden" name="status" value="{{ $leave_request->status }}">
                    @endif

                    <div class="d-flex justify-content-end">
                        <a href="{{ route('leave_requests.index') }}" class="btn btn-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Leave Request</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

{{-- Button styling (reused from presence form) --}}
<style>
.d-flex .btn {
    display: flex !important;
    align-items: center;
    justify-content: center;
    height: 80px;
    font-size: 1rem;
    padding: 0 24px;
    min-width: 120px;
}

@media (max-width: 576px) {
    .d-flex .btn {
        width: 32vw;
        min-width: unset;
        font-size: 1rem;
    }
}
</style>
@endsection
