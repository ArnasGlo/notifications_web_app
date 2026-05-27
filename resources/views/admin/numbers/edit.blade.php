@extends('layouts.admin')

@section('title', 'Edit Number')

@section('content')

<div class="card">
    <div class="card-header d-flex align-items-center">
        <a href="{{ route('admin.numbers.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h3 class="card-title mb-0">Edit Number — {{ $number->number }}</h3>
    </div>
    <div class="card-body">

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('admin.numbers.update', $number) }}" method="POST">
            @csrf @method('PUT')

            <div class="form-group">
                <label for="user_id">Owner <span class="text-danger">*</span></label>
                <select name="user_id" id="user_id"
                        class="form-control @error('user_id') is-invalid @enderror" required>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}"
                            {{ old('user_id', $number->user_id) == $user->id ? 'selected' : '' }}>
                            {{ $user->name }} ({{ $user->email }})
                        </option>
                    @endforeach
                </select>
                @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" class="form-control bg-light" value="{{ $number->number }}" disabled>
                <small class="form-text text-muted">The number itself cannot be changed.</small>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="country">Country</label>
                    <input type="text" name="country" id="country"
                           class="form-control @error('country') is-invalid @enderror"
                           value="{{ old('country', $number->country) }}" placeholder="Lithuania">
                    @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group col-md-6">
                    <label for="city">City</label>
                    <input type="text" name="city" id="city"
                           class="form-control @error('city') is-invalid @enderror"
                           value="{{ old('city', $number->city) }}" placeholder="Vilnius">
                    @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" class="form-control @error('status') is-invalid @enderror">
                    <option value="active"   {{ old('status', $number->status) === 'active'   ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $number->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save Changes
                </button>
                <a href="{{ route('admin.numbers.index') }}" class="btn btn-secondary ml-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
