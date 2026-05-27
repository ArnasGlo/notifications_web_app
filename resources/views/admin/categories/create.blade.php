@extends('layouts.admin')

@section('title', 'Create Category')

@section('content')

<div class="card">
    <div class="card-header d-flex align-items-center">
        <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h3 class="card-title mb-0">Create Category</h3>
    </div>
    <div class="card-body">

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('admin.categories.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="name">Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="name"
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}" placeholder="e.g. Meeting" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="icon">FontAwesome Icon Class</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="iconPreview">
                            <i class="{{ old('icon', 'fas fa-tag') }}" id="iconEl"></i>
                        </span>
                    </div>
                    <input type="text" name="icon" id="icon"
                           class="form-control @error('icon') is-invalid @enderror"
                           value="{{ old('icon', 'fas fa-tag') }}"
                           placeholder="fas fa-calendar"
                           oninput="document.getElementById('iconEl').className = this.value">
                </div>
                <small class="form-text text-muted">
                    Use any <a href="https://fontawesome.com/icons" target="_blank">FontAwesome 5</a> class,
                    e.g. <code>fas fa-calendar</code>, <code>fas fa-exclamation-triangle</code>
                </small>
                @error('icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox" name="is_active" id="is_active"
                           class="custom-control-input" value="1"
                           {{ old('is_active', true) ? 'checked' : '' }}>
                    <label class="custom-control-label" for="is_active">Active</label>
                </div>
                <small class="form-text text-muted">Inactive categories won't appear in the compose screen.</small>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Create Category
                </button>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary ml-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
