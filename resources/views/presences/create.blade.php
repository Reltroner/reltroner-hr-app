<!-- resources/views/presences/create.blade.php -->
 
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
                <h3>Create Presence</h3>
                <p class="text-subtitle text-muted">Record a new presence entry</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('presences.index') }}">Presences</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Create</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">New Presence Form</h5>
            </div>

            <div class="card-body">

            @if(session('role') == 'Admin' || session('role') == 'HR Manager')
                <form action="{{ route('presences.store') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label for="employee_id" class="form-label">Employee</label>
                        <select class="form-select @error('employee_id') is-invalid @enderror" name="employee_id" id="employee_id">
                            <option value="">-- Select Employee --</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}" {{ old('employee_id') == $employee->id ? 'selected' : '' }}>
                                    {{ $employee->fullname }}
                                </option>
                            @endforeach
                        </select>
                        @error('employee_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="check_in" class="form-label">Check In</label>
                        <input type="text" class="form-control datetime @error('check_in') is-invalid @enderror"
                               name="check_in" id="check_in" value="{{ old('check_in') }}">
                        @error('check_in')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="check_out" class="form-label">Check Out</label>
                        <input type="text" class="form-control datetime @error('check_out') is-invalid @enderror"
                               name="check_out" id="check_out" value="{{ old('check_out') }}">
                        @error('check_out')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="date" class="form-label">Date</label>
                        <input type="text" class="form-control date @error('date') is-invalid @enderror"
                               name="date" id="date" value="{{ old('date') }}">
                        @error('date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select @error('status') is-invalid @enderror" name="status" id="status">
                            <option value="present" {{ old('status') == 'present' ? 'selected' : '' }}>Present</option>
                            <option value="absent" {{ old('status') == 'absent' ? 'selected' : '' }}>Absent</option>
                            <option value="late" {{ old('status') == 'late' ? 'selected' : '' }}>Late</option>
                            <option value="leave" {{ old('status') == 'leave' ? 'selected' : '' }}>Leave</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end">
                        <a href="{{ route('presences.index') }}" class="btn btn-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Presence</button>
                    </div>
                </form>
            @else

            <form action="{{ route('presences.store') }}" method="POST">
                @csrf
                <div class="mb-3"><b>Note</b> : Please turn on location permission on your device.</div>
                <div class="mb-3">
                    <label for="latitude" class="form-label">Latitude</label>
                    <input type="text" readonly class="form-control"
                           name="latitude" id="latitude" value="{{ old('latitude') }}" required>
                </div>
                <div class="mb-3">
                    <label for="longitude" class="form-label">Longitude</label>
                    <input type="text" readonly class="form-control"
                           name="longitude" id="longitude" value="{{ old('longitude') }}" required>
                </div>
                <div class="mb-3">
                    <iframe width="100%" height="200" style="border:0;" loading="lazy" frameborder="0" 
                    scrolling="no" marginheight="0" marginwidth="0" allowfullscreen 
                    referrerpolicy="no-referrer-when-downgrade"
                    src=""></iframe>
                </div>
                <!-- <button class="btn btn-primary" type="submit" id="btnPresence" disabled>Submit</button> -->
                <button class="btn btn-primary" type="submit" id="btnPresence" >Submit</button>
            </form>
            @endif
            </div>
        </div>
    </section>
</div>
<script>
    const iframe = document.querySelector('iframe');
    const officeLat = -6.175392;
    const officeLng = 106.827153;
    const threshold = 0.01;
    navigator.geolocation.getCurrentPosition(function(position){
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        iframe.src = `https://www.google.com/maps?q=${lat},${lng}&z=15&output=embed`;
    });

    document.addEventListener('DOMContentLoaded', (event) => {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position){
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
                
                //compare
                const distance = Math.sqrt(Math.pow(lat - officeLat, 2) + Math.pow(lng - officeLng, 2));
                if (distance <= threshold) {
                    //in office
                    alert('You are in office');
                    document.getElementById('btnPresence').removeAttribute('disabled');
                }else{
                    //out of office
                    alert('You are out of office');
                }
            });
        }
    });
</script>
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
    if (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerText = 'Processing...';
            }
        });
    }
});
</script>
@endsection
