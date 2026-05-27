@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('numbers.index') }}" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h2 class="mb-0 fw-bold">Add Number</h2>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0 ps-3">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('numbers.store') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="number" class="form-label fw-semibold">
                                Phone Number <span class="text-danger">*</span>
                            </label>
                            <input type="text"
                                   name="number"
                                   id="number"
                                   class="form-control @error('number') is-invalid @enderror"
                                   value="{{ old('number') }}"
                                   placeholder="+37060000001"
                                   required>
                            @error('number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Must be unique across the platform.</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="country" class="form-label fw-semibold">Country</label>
                                <input type="text"
                                       name="country"
                                       id="country"
                                       class="form-control @error('country') is-invalid @enderror"
                                       value="{{ old('country') }}"
                                       placeholder="Lithuania">
                                @error('country')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6">
                                <label for="city" class="form-label fw-semibold">City</label>
                                <input type="text"
                                       name="city"
                                       id="city"
                                       class="form-control @error('city') is-invalid @enderror"
                                       value="{{ old('city') }}"
                                       placeholder="Vilnius">
                                @error('city')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-plus me-2"></i> Add Number
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <p class="text-muted small text-center mt-3">
                After adding, you'll get a unique invite link to share with contacts.
            </p>
        </div>
    </div>
</div>
@endsection
