@extends('layouts.admin')

@section('title', 'Numbers')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        {{ session('error') }}
    </div>
@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">All Numbers</h3>
        <a href="{{ route('admin.numbers.create') }}" class="btn btn-primary btn-sm">
            <i class="fas fa-plus mr-1"></i> New Number
        </a>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>Number</th>
                    <th>Owner</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Share Token</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($numbers as $number)
                <tr>
                    <td>{{ $number->id }}</td>
                    <td class="font-weight-bold">{{ $number->number }}</td>
                    <td>
                        <a href="{{ route('admin.users.show', $number->user) }}">
                            {{ $number->user->name }}
                        </a>
                    </td>
                    <td>{{ collect([$number->city, $number->country])->filter()->implode(', ') ?: '—' }}</td>
                    <td>
                        <span class="badge {{ $number->status === 'active' ? 'badge-success' : 'badge-secondary' }}">
                            {{ ucfirst($number->status) }}
                        </span>
                    </td>
                    <td>
                        <code class="small">{{ substr($number->share_token, 0, 8) }}...</code>
                    </td>
                    <td>{{ $number->created_at->format('d M Y') }}</td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('admin.numbers.show', $number) }}"
                               class="btn btn-outline-info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="{{ route('admin.numbers.edit', $number) }}"
                               class="btn btn-outline-primary" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.numbers.destroy', $number) }}" method="POST"
                                  onsubmit="return confirm('Delete number {{ addslashes($number->number) }}?')">
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
                    <td colspan="8" class="text-center text-muted py-4">No numbers found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($numbers->hasPages())
    <div class="card-footer">
        {{ $numbers->links() }}
    </div>
    @endif
</div>

@endsection
