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
            <h2 class="mb-0 fw-bold">{{ $number->number }}</h2>
            <small class="text-muted">
                @if($isOwner)
                    <span class="badge bg-success me-1">Owner</span>
                @else
                    <span class="badge bg-info text-dark me-1">Assistant</span>
                @endif
                @if($number->city || $number->country)
                    <i class="fas fa-map-marker-alt me-1"></i>
                    {{ collect([$number->city, $number->country])->filter()->implode(', ') }}
                @endif
            </small>
        </div>
    </div>

    @if($conversations->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No conversations on this number</h5>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="list-group list-group-flush" id="conversationList">
                @foreach($conversations as $conversation)
                    @include('partials.conversation-row')
                @endforeach
            </div>
        </div>
        <div class="mt-4 d-flex justify-content-center">{{ $conversations->links() }}</div>
    @endif
</div>

@include('partials.poller')

@push('scripts')
<script>
@if($conversations->onFirstPage())
(function () {
    const list = document.getElementById('conversationList');
    let since = @json($syncedAt->toIso8601String());

    window.startPolling({
        url: @json(route('messages.updates')),
        // number_id scopes "mine" to this one number, as the page itself does.
        params: () => ({ since: since, number_id: @json($number->id) }),
        onData(data) {
            since = data.server_time;
            if (!data.conversations.length) return;
            if (!list) return window.location.reload();

            data.conversations.slice().reverse().forEach(function (row) {
                list.querySelector('[data-conversation-id="' + row.id + '"]')?.remove();
                list.insertAdjacentHTML('afterbegin', row.html);
            });
        },
    });
})();
@endif
</script>
@endpush
@endsection
