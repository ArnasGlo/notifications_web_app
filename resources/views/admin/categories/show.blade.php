@extends('layouts.admin')

@section('title', 'Category: ' . $category->name)

@section('content')

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="{{ route('admin.categories.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h3 class="card-title mb-0">
                <i class="{{ $category->icon ?? 'fas fa-tag' }} mr-2"></i>
                {{ $category->name }}
                @if(!$category->is_active)
                    <span class="badge badge-secondary ml-2">Inactive</span>
                @endif
            </h3>
        </div>
        <div>
            <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-primary btn-sm mr-2">
                <i class="fas fa-edit mr-1"></i> Edit Category
            </a>
            <a href="{{ route('admin.templates.create') }}" class="btn btn-success btn-sm">
                <i class="fas fa-plus mr-1"></i> Add Template
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>Message Body</th>
                    <th>Type</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($category->templates as $template)
                <tr>
                    <td>{{ $template->id }}</td>
                    <td>{{ $template->body }}</td>
                    <td>
                        @if($template->is_reply)
                            <span class="badge badge-info">Reply only</span>
                        @else
                            <span class="badge badge-primary">Sendable</span>
                        @endif
                    </td>
                    <td>
                        @if($template->is_active)
                            <span class="badge badge-success">Yes</span>
                        @else
                            <span class="badge badge-secondary">No</span>
                        @endif
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('admin.templates.edit', $template) }}"
                               class="btn btn-outline-primary" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.templates.destroy', $template) }}" method="POST"
                                  onsubmit="return confirm('Delete this template?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        No templates yet.
                        <a href="{{ route('admin.templates.create') }}">Add one.</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
