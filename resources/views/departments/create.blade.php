<!-- resources/views/departments/create.blade.php -->

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
                <h3>Create Department</h3>
                <p class="text-subtitle text-muted">You can register a new department here</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('departments.index') }}">Departments</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Create</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">New Department Form</h5>
            </div>

            <div class="card-body">
                <form action="{{ route('departments.store') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label">Department Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                            name="name" id="name" value="{{ old('name') }}">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description (optional)</label>
                        <textarea name="description" id="description" rows="3"
                            class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status" class="form-select @error('status') is-invalid @enderror">
                            <option value="active" {{ old('status') == 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end">
                        <a href="{{ route('departments.index') }}" class="btn btn-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Department</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
<style>
    /* Untuk semua tombol (Cancel dan Create Presence) di form presence */
.d-flex .btn {
    display: flex !important;
    align-items: center;
    justify-content: center;
    height: 55px;
    font-size: 1rem;
    padding: 0 24px;
    min-width: 120px;
}

/* Extra: Buat tombol lebih proporsional di mobile */
@media (max-width: 576px) {
    .d-flex .btn {
        width: 32vw;
        min-width: unset;
        font-size: 1rem;
    }
}   
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    if(form){
        form.addEventListener('submit', function () {
            const submitButton = form.querySelector('button[type="submit"]');
            if(submitButton){
                submitButton.disabled = true;
                submitButton.innerHTML = "Processing...";
            }
        });
    }
});
</script>
@endsection
