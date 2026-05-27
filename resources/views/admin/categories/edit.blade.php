@extends('layouts.admin')

@section('title', 'Edit Category')

@section('content')

<div class="card">
    <div class="card-header d-flex align-items-center">
        <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h3 class="card-title mb-0">Edit Category — {{ $category->name }}</h3>
    </div>
    <div class="card-body">

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('admin.categories.update', $category) }}" method="POST">
            @csrf @method('PUT')

            <div class="form-group">
                <label for="name">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="name"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $category->name) }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="icon">FontAwesome Icon Class</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text">
                            <i class="{{ old('icon', $category->icon) }}" id="iconEl"></i>
                        </span>
                    </div>
                    <input type="text" name="icon" id="icon"
                           class="form-control @error('icon') is-invalid @enderror"
                           value="{{ old('icon', $category->icon) }}"
                           oninput="document.getElementById('iconEl').className = this.value">
                </div>
                @error('icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" name="is_active" id="is_active"
                           class="custom-control-input" value="1"
                           {{ old('is_active', $category->is_active) ? 'checked' : '' }}>
                    <label class="custom-control-label" for="is_active">Active</label>
                </div>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save Changes
                </button>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary ml-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
