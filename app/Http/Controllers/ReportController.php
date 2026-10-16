<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Intern;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Show the intern report generation interface.
     */
    public function internIndex()
    {
        $agencies = Company::query()->where('is_partner_agency', true)->orderBy('name')->get();

        return view('reports.intern', compact('agencies'));
    }

    /**
     * Export the intern roster to CSV.
     */
    public function exportInterns(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Intern::STATUSES)],
            'partner_agency_id' => ['nullable', 'integer', 'exists:companies,id'],
            'expiring' => ['nullable', 'in:all,soon,expired'],
        ]);

        $query = Intern::with(['user', 'partnerAgency']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['partner_agency_id'])) {
            $query->where('partner_agency_id', (int) $validated['partner_agency_id']);
        }

        $expiring = $validated['expiring'] ?? 'all';
        if ($expiring === 'soon') {
            $query->expiringSoon(30);
        } elseif ($expiring === 'expired') {
            $query->whereNotNull('contract_end_date')
                ->whereDate('contract_end_date', '<', now()->startOfDay());
        }

        $interns = $query->orderBy('id')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="intern_master_list_'.now()->format('Y-m-d_His').'.csv"',
        ];

        return response()->stream(function () use ($interns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Name', 'Username', 'Partner Agency', 'Type', 'Batch',
                'Contract Start', 'Contract End', 'Required Hours', 'Rendered Hours',
                'Progress %', 'Status',
            ]);

            foreach ($interns as $intern) {
                fputcsv($file, [
                    $intern->name,
                    $intern->user?->username ?? '',
                    $intern->partnerAgency?->name ?? '',
                    $intern->intern_type ?? '',
                    $intern->batch ?? '',
                    $intern->contract_start_date?->format('Y-m-d') ?? '',
                    $intern->contract_end_date?->format('Y-m-d') ?? '',
                    $intern->required_hours,
                    $intern->rendered_hours,
                    $intern->progress_percent,
                    ucfirst($intern->status),
                ]);
            }

            fclose($file);
        }, 200, $headers);
    }

    /**
     * Export the intern hours monitoring summary to CSV.
     */
    public function exportInternHours(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Intern::STATUSES)],
        ]);

        $query = Intern::with(['partnerAgency']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $interns = $query->orderByDesc('rendered_hours')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="intern_hours_summary_'.now()->format('Y-m-d_His').'.csv"',
        ];

        return response()->stream(function () use ($interns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Intern', 'Partner Agency', 'Required Hours', 'Rendered Hours', 'Remaining Hours', 'Progress %', 'Status']);

            $totalRequired = 0;
            $totalRendered = 0;

            foreach ($interns as $intern) {
                $totalRequired += (int) $intern->required_hours;
                $totalRendered += (int) $intern->rendered_hours;

                fputcsv($file, [
                    $intern->name,
                    $intern->partnerAgency?->name ?? '',
                    $intern->required_hours,
                    $intern->rendered_hours,
                    $intern->hours_remaining,
                    $intern->progress_percent,
                    ucfirst($intern->status),
                ]);
            }

            fputcsv($file, ['TOTAL', '', $totalRequired, $totalRendered, max(0, $totalRequired - $totalRendered), '', '']);

            fclose($file);
        }, 200, $headers);
    }

    /**
     * Export the partner-agency summary (interns per agency) to CSV.
     */
    public function exportInternAgencySummary(): StreamedResponse
    {
        $agencies = Company::query()
            ->where('is_partner_agency', true)
            ->with('interns')
            ->orderBy('name')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="intern_agency_summary_'.now()->format('Y-m-d_His').'.csv"',
        ];

        return response()->stream(function () use ($agencies) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Partner Agency', 'Total Interns', 'Active', 'Inactive', 'Completed',
                'Total Required Hours', 'Total Rendered Hours', 'Avg Progress %',
            ]);

            foreach ($agencies as $agency) {
                $interns = $agency->interns;
                $count = $interns->count();

                fputcsv($file, [
                    $agency->name,
                    $count,
                    $interns->where('status', 'active')->count(),
                    $interns->where('status', 'inactive')->count(),
                    $interns->where('status', 'completed')->count(),
                    $interns->sum('required_hours'),
                    $interns->sum('rendered_hours'),
                    $count > 0 ? round($interns->avg(fn ($intern) => $intern->progress_percent), 2) : 0,
                ]);
            }

            fclose($file);
        }, 200, $headers);
    }

    /**
     * Export the intern contract expiry report to CSV.
     */
    public function exportInternExpiry(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'expiry_status' => ['nullable', 'in:all,expired,expiring,valid'],
        ]);

        $status = $validated['expiry_status'] ?? 'all';
        $now = now()->startOfDay();
        $thirtyDays = now()->addDays(30)->endOfDay();

        $query = Intern::with(['partnerAgency'])
            ->whereNotNull('contract_end_date');

        if ($status === 'expired') {
            $query->whereDate('contract_end_date', '<', $now);
        } elseif ($status === 'expiring') {
            $query->whereBetween('contract_end_date', [$now, $thirtyDays]);
        } elseif ($status === 'valid') {
            $query->whereDate('contract_end_date', '>', $thirtyDays);
        }

        $interns = $query->orderBy('contract_end_date')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="intern_contract_expiry_'.$status.'_'.now()->format('Y-m-d').'.csv"',
        ];

        return response()->stream(function () use ($interns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Intern', 'Partner Agency', 'Contract End', 'Days Remaining', 'Expiry Status', 'Status']);

            foreach ($interns as $intern) {
                $expiry = 'Valid';
                if ($intern->isExpired()) {
                    $expiry = 'EXPIRED';
                } elseif ($intern->isExpiringSoon(30)) {
                    $expiry = 'Expiring Soon';
                }

                fputcsv($file, [
                    $intern->name,
                    $intern->partnerAgency?->name ?? '',
                    $intern->contract_end_date?->format('Y-m-d') ?? '',
                    $intern->days_remaining,
                    $expiry,
                    ucfirst($intern->status),
                ]);
            }

            fclose($file);
        }, 200, $headers);
    }
}
