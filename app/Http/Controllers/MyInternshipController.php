<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyInternshipController extends Controller
{
    /**
     * Self-service dashboard for the currently logged-in intern.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isIntern()) {
            return redirect()->route('dashboard');
        }

        $intern = $user->intern;

        if (! $intern) {
            return view('my-internship.unassigned');
        }

        $intern->load(['partnerAgency']);

        $documents = $intern->internDocuments()
            ->with(['documentType', 'reviewer'])
            ->latest()
            ->get();

        $documentTypes = DocumentType::query()
            ->where('is_intern_document', true)
            ->orderBy('name')
            ->get();

        return view('my-internship.index', compact('intern', 'documents', 'documentTypes'));
    }
}
