<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h4 mb-1 fw-bold">Add Intern</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">Create the intern record and its login account in one step.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('interns.index') }}"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('interns.store') }}">
                @csrf
                @include('interns._form')
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a class="btn btn-outline-secondary" href="{{ route('interns.index') }}">Cancel</a>
                    <button class="btn btn-brand" type="submit">Create Intern</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
