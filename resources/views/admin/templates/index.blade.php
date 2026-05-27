@extends('layouts.admin')

@section('title', 'Message Templates')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        {{ session('success') }}
    </div>
@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">Message Templates</h3>
        <a href="{{ route('admin.templates.create') }}" class="btn btn-primary btn-sm">
            <i class="fas fa-plus mr-1"></i> New Template
        </a>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>Category</th>
                    <th>Message Body</th>
                    <th>Type</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($templates as $template)
                <tr>
                    <td>{{ $template->id }}</td>
                    <td>
                        <i class="{{ $template->category->icon ?? 'fas fa-tag' }} mr-1"></i>
                        {{ $template->category->name }}
                    </td>
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
                    <td colspan="6" class="text-center text-muted py-4">No templates found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($templates->hasPages())
    <div class="card-footer">
        {{ $templates->links() }}
    </div>
    @endif
</div>

@endsection
