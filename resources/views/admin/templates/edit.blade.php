@extends('layouts.admin')

@section('title', 'Edit Template')

@section('content')

<div class="card">
    <div class="card-header d-flex align-items-center">
        <a href="{{ route('admin.templates.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h3 class="card-title mb-0">Edit Template</h3>
    </div>
    <div class="card-body">

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('admin.templates.update', $template) }}" method="POST">
            @csrf @method('PUT')

            <div class="form-group">
                <label for="category_id">Category <span class="text-danger">*</span></label>
                <select name="category_id" id="category_id"
                        class="form-control @error('category_id') is-invalid @enderror" required>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}"
                            {{ old('category_id', $template->category_id) == $cat->id ? 'selected' : '' }}>
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
                       value="{{ old('body', $template->body) }}" maxlength="255" required>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" name="is_reply" id="is_reply"
                               class="custom-control-input" value="1"
                               {{ old('is_reply', $template->is_reply) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="is_reply">Reply only</label>
                    </div>
                </div>
                <div class="form-group col-md-6">
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" name="is_active" id="is_active"
                               class="custom-control-input" value="1"
                               {{ old('is_active', $template->is_active) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save Changes
                </button>
                <a href="{{ route('admin.templates.index') }}" class="btn btn-secondary ml-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
