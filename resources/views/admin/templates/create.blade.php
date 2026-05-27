@extends('layouts.admin')

@section('title', 'Create Template')

@section('content')

<div class="card">
    <div class="card-header d-flex align-items-center">
        <a href="{{ route('admin.templates.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h3 class="card-title mb-0">Create Template</h3>
    </div>
    <div class="card-body">

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('admin.templates.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="category_id">Category <span class="text-danger">*</span></label>
                <select name="category_id" id="category_id"
                        class="form-control @error('category_id') is-invalid @enderror" required>
                    <option value="">— Select category —</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name }}
                        </option>
                    @endforeach
                </select>
                @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="body">Message Body <span class="text-danger">*</span></label>
                <input type="text" name="body" id="body"
                       class="form-control @error('body') is-invalid @enderror"
                       value="{{ old('body') }}" placeholder="e.g. Can you talk?" maxlength="255" required>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">Max 255 characters.</small>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" name="is_reply" id="is_reply"
                               class="custom-control-input" value="1"
                               {{ old('is_reply') ? 'checked' : '' }}>
                        <label class="custom-control-label" for="is_reply">Reply only</label>
                    </div>
                    <small class="form-text text-muted">
                        Check this if the message can only be used as a reply, not as an initial message.
                    </small>
                </div>
                <div class="form-group col-md-6">
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" name="is_active" id="is_active"
                               class="custom-control-input" value="1"
                               {{ old('is_active', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="is_active">Active</label>
                    </div>
                    <small class="form-text text-muted">Inactive templates won't appear for users.</small>
                </div>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Create Template
                </button>
                <a href="{{ route('admin.templates.index') }}" class="btn btn-secondary ml-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
