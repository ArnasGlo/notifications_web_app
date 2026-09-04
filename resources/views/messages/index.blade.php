@extends('layouts.app')

@section('content')
<div class="container py-4">

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0 fw-bold">Messages</h2>
            <small class="text-muted">
                @if(request('q'))
                    Conversations with <strong>{{ request('q') }}</strong>
                @else
                    Your conversations, most recent first
                @endif
            </small>
        </div>
        <a href="{{ route('messages.compose') }}" class="btn btn-primary">
            <i class="fas fa-paper-plane me-1"></i> Compose
        </a>
    </div>

    <div class="row g-2 mb-4">
        {{-- Quick jump straight to a thread --}}
        <div class="col-md-6">
            <select class="form-select" id="conversationJump" aria-label="Jump to a conversation">
                <option value="">Jump to a conversation…</option>
                @foreach($jumpList as $item)
                    @php $other = $item->counterpartFor($accessibleIds); @endphp
                    <option value="{{ route('conversations.show', $item) }}">
                        {{ $other?->number ?? 'Unknown' }}@if($item->last_message_at) — {{ $item->last_message_at->diffForHumans() }}@endif
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Exact-number filter (also what the Favorites "Conversation" button uses) --}}
        <div class="col-md-6">
            <form method="GET" action="{{ route('messages.index') }}">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control"
                           placeholder="Filter by number, e.g. +37060011111">
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                    @if(request('q'))
                        <a href="{{ route('messages.index') }}" class="btn btn-outline-secondary" title="Clear filter">
                            <i class="fas fa-times"></i>
                        </a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    @if($conversations->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                @if(request('q'))
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No conversations with that number</h5>
                    <p class="text-muted mb-4">Nothing matches <strong>{{ request('q') }}</strong>.</p>
                    <a href="{{ route('messages.index') }}" class="btn btn-outline-secondary">Clear filter</a>
                @else
                    <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No conversations yet</h5>
                    <p class="text-muted mb-4">Send your first message or share your invite link to get started.</p>
                    <a href="{{ route('messages.compose') }}" class="btn btn-primary">Send First Message</a>
                @endif
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="list-group list-group-flush">
                @foreach($conversations as $conversation)
                    @include('partials.conversation-row')
                @endforeach
            </div>
        </div>
        <div class="mt-4 d-flex justify-content-center">{{ $conversations->links() }}</div>
    @endif
</div>

@push('scripts')
<script>
document.getElementById('conversationJump')?.addEventListener('change', function () {
    if (this.value) window.location = this.value;
});
</script>
@endpush
@endsection
