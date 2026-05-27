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

    <div class="d-flex align-items-center mb-2">
        <a href="{{ route('numbers.index') }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="mb-0 fw-bold">Block Rules</h2>
            <small class="text-muted">Number: <strong>{{ $number->number }}</strong></small>
        </div>
        <a href="{{ route('numbers.blocks.create', $number) }}" class="btn btn-primary ms-auto">
            <i class="fas fa-plus me-1"></i> Add Block
        </a>
    </div>

    <p class="text-muted small mb-4">
        Messages matching any rule below will be rejected before delivery.
    </p>

    @if($blocks->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-shield-alt fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No block rules yet</h5>
                <p class="text-muted mb-4">Add rules to block messages by number, user, city, or country.</p>
                <a href="{{ route('numbers.blocks.create', $number) }}" class="btn btn-primary">Add Block Rule</a>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Added</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($blocks as $block)
                        <tr>
                            <td>
                                @php
                                    $icons = ['number' => 'fa-phone', 'user' => 'fa-user', 'city' => 'fa-city', 'country' => 'fa-globe'];
                                    $labels = ['number' => 'Number', 'user' => 'User', 'city' => 'City', 'country' => 'Country'];
                                @endphp
                                <span class="badge bg-secondary">
                                    <i class="fas {{ $icons[$block->type] ?? 'fa-ban' }} me-1"></i>
                                    {{ $labels[$block->type] ?? ucfirst($block->type) }}
                                </span>
                            </td>
                            <td class="fw-semibold">{{ $block->value }}</td>
                            <td class="text-muted small">{{ $block->created_at->diffForHumans() }}</td>
                            <td class="text-end">
                                <form action="{{ route('numbers.blocks.destroy', [$number, $block]) }}" method="POST"
                                      onsubmit="return confirm('Remove this block rule?')">
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
