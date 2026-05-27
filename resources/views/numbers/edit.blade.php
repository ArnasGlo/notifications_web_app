@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('numbers.index') }}" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="mb-0 fw-bold">Edit Number</h2>
                    <small class="text-muted">{{ $number->number }}</small>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0 ps-3">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('numbers.update', $number) }}" method="POST">
                        @csrf
                        @method('PUT')

                        {{-- Read-only number display --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <input type="text" class="form-control bg-light" value="{{ $number->number }}" disabled>
                            <div class="form-text">The number itself cannot be changed.</div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="country" class="form-label fw-semibold">Country</label>
                                <input type="text"
                                       name="country"
                                       id="country"
                                       class="form-control @error('country') is-invalid @enderror"
                                       value="{{ old('country', $number->country) }}"
                                       placeholder="Lithuania">
                                @error('country')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6">
                                <label for="city" class="form-label fw-semibold">City</label>
                                <input type="text"
                                       name="city"
                                       id="city"
                                       class="form-control @error('city') is-invalid @enderror"
                                       value="{{ old('city', $number->city) }}"
                                       placeholder="Vilnius">
                                @error('city')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="status" class="form-label fw-semibold">Status</label>
                            <select name="status" id="status"
                                    class="form-select @error('status') is-invalid @enderror">
                                <option value="active"   {{ old('status', $number->status) === 'active'   ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status', $number->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Inactive numbers cannot send or receive messages.</div>
                        </div>

                        {{-- Invite link (read-only, just for reference) --}}
                        <div class="mb-4 p-3 bg-light rounded">
                            <label class="form-label text-muted small fw-semibold mb-1">INVITE LINK</label>
                            <div class="input-group input-group-sm">
                                <input type="text"
                                       class="form-control bg-white"
                                       value="{{ url('/invite/' . $number->share_token) }}"
                                       id="inviteLink"
                                       readonly>
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="copyInvite()" title="Copy">
                                    <i class="fas fa-copy" id="copyIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="fas fa-save me-2"></i> Save Changes
                            </button>
                            <a href="{{ route('numbers.index') }}" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Danger zone --}}
            <div class="card border-danger border-0 shadow-sm mt-3">
                <div class="card-body p-4">
                    <h6 class="text-danger fw-bold mb-1">Danger Zone</h6>
                    <p class="text-muted small mb-3">Deleting this number will remove all its contacts and messages permanently.</p>
                    <form action="{{ route('numbers.destroy', $number) }}" method="POST"
                          onsubmit="return confirm('Are you sure? This cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-trash me-1"></i> Delete this number
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

@push('scripts')
<script>
function copyInvite() {
    const val = document.getElementById('inviteLink').value;
    navigator.clipboard.writeText(val).then(() => {
        const icon = document.getElementById('copyIcon');
        icon.className = 'fas fa-check';
        setTimeout(() => icon.className = 'fas fa-copy', 2000);
    });
}
</script>
@endpush
@endsection
