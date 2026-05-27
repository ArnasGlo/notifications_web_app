@extends('layouts.admin')

@section('title', 'User: ' . $user->name)

@section('content')

<div class="row">
    {{-- User details --}}
    <div class="col-md-4">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary mr-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h3 class="card-title mb-0">User Details</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">ID</dt>
                    <dd class="col-sm-8">{{ $user->id }}</dd>

                    <dt class="col-sm-4">Name</dt>
                    <dd class="col-sm-8">{{ $user->name }}</dd>

                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8">{{ $user->email }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge
                            {{ $user->status === 'active' ? 'badge-success' : '' }}
                            {{ $user->status === 'busy'   ? 'badge-warning' : '' }}
                            {{ $user->status === 'dnd'    ? 'badge-danger'  : '' }}">
                            {{ ucfirst($user->status) }}
                        </span>
                    </dd>

                    <dt class="col-sm-4">Role</dt>
                    <dd class="col-sm-8">
                        @if($user->is_admin)
                            <span class="badge badge-primary">Admin</span>
                        @else
                            <span class="badge badge-secondary">User</span>
                        @endif
                    </dd>

                    <dt class="col-sm-4">Joined</dt>
                    <dd class="col-sm-8">{{ $user->created_at->format('d M Y') }}</dd>
                </dl>
            </div>
            <div class="card-footer">
                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-edit mr-1"></i> Edit
                </a>
            </div>
        </div>
    </div>

    {{-- User's numbers --}}
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Numbers ({{ $user->numbers->count() }})</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Number</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Share Token</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($user->numbers as $number)
                        <tr>
                            <td class="font-weight-bold">{{ $number->number }}</td>
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
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">No numbers assigned.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
