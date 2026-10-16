<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h4 mb-1 fw-bold">Edit Intern</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">Update {{ $intern->name }}'s record and login account.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('interns.show', $intern) }}"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('interns.update', $intern) }}">
                @csrf
                @method('PUT')
                @include('interns._form', ['intern' => $intern])
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a class="btn btn-outline-secondary" href="{{ route('interns.show', $intern) }}">Cancel</a>
                    <button class="btn btn-brand" type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
