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
            <h2 class="mb-0 fw-bold">My Numbers</h2>
            <a href="{{ route('numbers.create') }}" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> Add Number
            </a>
        </div>

        @if($numbers->isEmpty())
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-phone-slash fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No numbers yet</h5>
                    <p class="text-muted mb-4">Add your first number to start sending and receiving messages.</p>
                    <a href="{{ route('numbers.create') }}" class="btn btn-primary">Add Number</a>
                </div>
            </div>
        @else
            <div class="row g-3">
                @foreach($numbers as $number)
                    <div class="col-md-6 col-lg-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="fw-bold mb-1">{{ $number->number }}</h5>
                                        <small class="text-muted">
                                            @if($number->city || $number->country)
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                {{ collect([$number->city, $number->country])->filter()->implode(', ') }}
                                            @else
                                                <span class="fst-italic">No location set</span>
                                            @endif
                                        </small>
                                    </div>
                                    <span class="badge {{ $number->status === 'active' ? 'bg-success' : 'bg-secondary' }}">
                                        {{ ucfirst($number->status) }}
                                    </span>
                                </div>

                                @php $assistantCount = $number->delegates()->count(); @endphp
                                @if($assistantCount > 0)
                                    <div class="mb-3">
                                        <span class="badge bg-info text-dark">
                                            <i class="fas fa-users me-1"></i>
                                            {{ $assistantCount }} assistant{{ $assistantCount !== 1 ? 's' : '' }}
                                        </span>
                                    </div>
                                @endif

                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-semibold">DELEGATION INVITE LINK</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control form-control-sm bg-light"
                                            value="{{ url('/invite/' . $number->share_token) }}" id="token-{{ $number->id }}"
                                            readonly>
                                        <button class="btn btn-outline-secondary" type="button"
                                            onclick="copyToken('token-{{ $number->id }}', this)" title="Copy invite link">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Share to grant assistant access.</small>
                                </div>

                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="{{ route('numbers.edit', $number) }}"
                                        class="btn btn-sm btn-outline-primary flex-grow-1">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </a>
                                    <a href="{{ route('numbers.delegates.index', $number) }}"
                                        class="btn btn-sm btn-outline-info flex-grow-1">
                                        <i class="fas fa-users me-1"></i> Assistants
                                    </a>
                                    <a href="{{ route('numbers.blocks.index', $number) }}"
                                        class="btn btn-sm btn-outline-warning flex-grow-1">
                                        <i class="fas fa-ban me-1"></i> Blocks
                                    </a>
                                    <form action="{{ route('numbers.destroy', $number) }}" method="POST"
                                        onsubmit="return confirm('Delete this number? All related data will be lost.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Numbers the user is assisting --}}
        @php
            $delegatedNumbers = auth()->user()->delegations()->with('number.user')->latest()->get();
        @endphp
        @if($delegatedNumbers->isNotEmpty())
            <div class="mt-5">
                <h4 class="fw-bold mb-3">
                    <i class="fas fa-user-check me-2 text-info"></i> Numbers I Assist
                </h4>
                <p class="text-muted small mb-3">Numbers owned by others where you have assistant access.</p>
                <div class="row g-3">
                    @foreach($delegatedNumbers as $delegation)
                        @php $num = $delegation->number; @endphp
                        <div class="col-md-6 col-lg-4">
                            <div class="card border-0 shadow-sm border-start border-info border-3 h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h5 class="fw-bold mb-1">{{ $num->number }}</h5>
                                            <small class="text-muted">Owned by <strong>{{ $num->user->name }}</strong></small>
                                        </div>
                                        <span class="badge bg-info text-dark">Assistant</span>
                                    </div>
                                    @if($num->city || $num->country)
                                        <small class="text-muted">
                                            <i class="fas fa-map-marker-alt me-1"></i>
                                            {{ collect([$num->city, $num->country])->filter()->implode(', ') }}
                                        </small>
                                    @endif
                                    <div class="mt-3">
                                        <a href="{{ route('numbers.messages', $num) }}" class="btn btn-sm btn-outline-info w-100">
                                            <i class="fas fa-inbox me-1"></i> View Messages
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    @push('scripts')
        <script>
            function copyToken(inputId, btn) {
                navigator.clipboard.writeText(document.getElementById(inputId).value).then(() => {
                    const icon = btn.querySelector('i');
                    icon.className = 'fas fa-check';
                    btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
                    setTimeout(() => {
                        icon.className = 'fas fa-copy';
                        btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
                    }, 2000);
                });
            }
        </script>
    @endpush
@endsection