@extends('layouts.app')

@section('content')
<div class="container py-4">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-2">
        <h2 class="mb-0 fw-bold">Favorites</h2>
        <small class="text-muted">Starred numbers, for quick access to their conversations.</small>
    </div>

    <div class="card border-0 shadow-sm my-4">
        <div class="card-body">
            <form action="{{ route('favorites.store') }}" method="POST">
                @csrf
                <label for="number" class="form-label fw-semibold">Add a number</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-phone text-muted"></i></span>
                    <input type="text"
                           name="number"
                           id="number"
                           class="form-control @error('number') is-invalid @enderror"
                           value="{{ old('number') }}"
                           placeholder="+37060011111"
                           required>
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-star me-1"></i> Add
                    </button>
                </div>
                @error('number')
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @enderror
                <div class="form-text text-muted">Enter the exact number, as it appears on the platform.</div>
            </form>
        </div>
    </div>

    @if($favorites->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="far fa-star fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No favorites yet</h5>
                <p class="text-muted mb-0">Add a number above, or star someone from a message thread.</p>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Number</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Added</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($favorites as $favorite)
                        <tr>
                            <td class="fw-semibold">{{ $favorite->number->number }}</td>
                            <td class="text-muted small">
                                {{ collect([$favorite->number->city, $favorite->number->country])->filter()->join(', ') ?: '—' }}
                            </td>
                            <td>
                                <span class="badge {{ $favorite->number->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                    {{ ucfirst($favorite->number->status) }}
                                </span>
                            </td>
                            <td class="text-muted small">{{ $favorite->created_at->diffForHumans() }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('messages.index', ['q' => $favorite->number->number]) }}"
                                   class="btn btn-sm btn-outline-primary me-1">
                                    <i class="fas fa-comments me-1"></i> Conversation
                                </a>
                                <form action="{{ route('favorites.destroy', $favorite) }}" method="POST"
                                      class="d-inline"
                                      onsubmit="return confirm('Remove this favorite?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
