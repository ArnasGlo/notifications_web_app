@extends('layouts.app')

@section('content')
<div class="container py-4">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="d-flex align-items-center mb-4">
        <a href="{{ route('numbers.index') }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="mb-0 fw-bold">Assistants</h2>
            <small class="text-muted">
                <i class="fas fa-phone me-1"></i>{{ $number->number }}
            </small>
        </div>
    </div>

    {{-- Invite link --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">Invite Link</h6>
            <p class="text-muted small mb-3">
                Share this link to grant someone assistant access. You can revoke it at any time.
            </p>
            <div class="input-group">
                <input type="text" class="form-control bg-light"
                       value="{{ url('/invite/' . $number->share_token) }}"
                       id="inviteLink" readonly>
                <button class="btn btn-outline-secondary" type="button"
                        onclick="copyLink()" id="copyBtn">
                    <i class="fas fa-copy me-1"></i> Copy
                </button>
            </div>
        </div>
    </div>

    {{-- Assistants list --}}
    @if($delegates->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No assistants yet</h5>
                <p class="text-muted mb-0">Share the invite link above to grant someone access.</p>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <strong>{{ $delegates->count() }} Assistant{{ $delegates->count() !== 1 ? 's' : '' }}</strong>
            </div>
            <div class="list-group list-group-flush">
                @foreach($delegates as $delegate)
                <div class="list-group-item px-4 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold">
                                <i class="fas fa-user-circle me-2 text-muted"></i>
                                {{ $delegate->assistant->name }}
                            </div>
                            <small class="text-muted">{{ $delegate->assistant->email }}</small>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    Since {{ $delegate->created_at->format('d M Y') }}
                                </small>
                            </div>
                        </div>
                        <form action="{{ route('numbers.delegates.destroy', [$number, $delegate]) }}"
                              method="POST"
                              onsubmit="return confirm('Revoke access for {{ $delegate->assistant->name }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-user-minus me-1"></i> Revoke
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script>
function copyLink() {
    navigator.clipboard.writeText(document.getElementById('inviteLink').value).then(() => {
        const btn = document.getElementById('copyBtn');
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Copied!';
        btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
        setTimeout(() => {
            btn.innerHTML = '<i class="fas fa-copy me-1"></i> Copy';
            btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
        }, 2000);
    });
}
</script>
@endpush
@endsection
