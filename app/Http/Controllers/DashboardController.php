<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Intern;
use App\Services\InternWorkCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->get('year', date('Y'));
        $month = $request->get('month');

        // ── Calendar Logic ──
        $calDateStr = $request->get('cal_date', date('Y-m-d'));
        $calendarDate = date('Y-m-01', strtotime($calDateStr));
        $calendarYear = date('Y', strtotime($calendarDate));
        $calendarMonth = date('m', strtotime($calendarDate));
        $calendarMonthName = date('F', strtotime($calendarDate));

        $daysInMonth = date('t', strtotime($calendarDate));
        $dayOfWeekOfFirst = date('w', strtotime($calendarDate));

        $calendarData = [];

        $prevMonthLastDay = date('t', strtotime('-1 month', strtotime($calendarDate)));
        for ($i = $dayOfWeekOfFirst - 1; $i >= 0; $i--) {
            $calendarData[] = [
                'day' => $prevMonthLastDay - $i,
                'current_month' => false,
                'today' => false,
            ];
        }

        $todayStr = date('Y-m-d');
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDateStr = sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $day);
            $calendarData[] = [
                'day' => $day,
                'current_month' => true,
                'today' => ($currentDateStr == $todayStr),
            ];
        }

        $remaining = 42 - count($calendarData);
        for ($day = 1; $day <= $remaining; $day++) {
            $calendarData[] = [
                'day' => $day,
                'current_month' => false,
                'today' => false,
            ];
        }

        // ── Role-based dashboard data ──
        $user = $request->user();

        $showInternStats = $user && method_exists($user, 'hasRole')
            ? $user->hasRole('admin', 'peso_manager')
            : false;

        $isInternUser = $user && method_exists($user, 'isIntern') && $user->isIntern();

        // ── Intern self-service dashboard data ──
        $internSelf = null;
        $internDocuments = collect();
        $workCalendar = null;
        $internStats = [
            'total_docs' => 0,
            'approved_docs' => 0,
            'pending_docs' => 0,
            'rejected_docs' => 0,
        ];

        if ($isInternUser) {
            $internSelf = $user->intern?->load(['partnerAgency', 'internDocuments.documentType']);
            if ($internSelf) {
                $docs = $internSelf->internDocuments;
                $internStats['total_docs'] = $docs->count();
                $internStats['approved_docs'] = $docs->where('review_status', 'approved')->count();
                $internStats['pending_docs'] = $docs->where('review_status', 'pending')->count();
                $internStats['rejected_docs'] = $docs->where('review_status', 'rejected')->count();
                $internDocuments = $docs->sortByDesc('created_at')->take(5);

                $workCalendar = app(InternWorkCalendar::class)
                    ->month($internSelf, $request->get('work_month'));
            }
        }

        $totalInterns = 0;
        $activeInterns = 0;
        $inactiveInterns = 0;
        $completedInterns = 0;
        $expiringSoonCount = 0;
        $internStatusDistribution = collect();
        $partnerAgencyDistribution = collect();
        $internsTrend = collect();
        $expiringContracts = collect();

        if ($showInternStats) {
            $totalInterns = Intern::count();
            $activeInterns = Intern::where('status', 'active')->count();
            $inactiveInterns = Intern::where('status', 'inactive')->count();
            $completedInterns = Intern::where('status', 'completed')->count();

            $expiringContracts = Intern::expiringSoon(30)
                ->with(['user', 'partnerAgency'])
                ->orderBy('contract_end_date')
                ->get();
            $expiringSoonCount = $expiringContracts->count();

            $internStatusDistribution = Intern::query()
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get();

            $partnerAgencyDistribution = Company::query()
                ->where('is_partner_agency', true)
                ->withCount('interns')
                ->orderByDesc('interns_count')
                ->get()
                ->map(fn ($company) => [
                    'name' => $company->name,
                    'count' => $company->interns_count,
                ])
                ->values();

            $internsTrend = Intern::query()
                ->select(
                    DB::raw('EXTRACT(MONTH FROM created_at) as month'),
                    DB::raw('count(*) as count')
                )
                ->whereYear('created_at', $year)
                ->groupBy('month')
                ->orderBy('month')
                ->get();
        }

        return view('dashboard', compact(
            'year',
            'month',
            'calendarData',
            'calendarDate',
            'calendarMonthName',
            'calendarYear',
            'showInternStats',
            'isInternUser',
            'internSelf',
            'internDocuments',
            'internStats',
            'workCalendar',
            'totalInterns',
            'activeInterns',
            'inactiveInterns',
            'completedInterns',
            'expiringSoonCount',
            'internStatusDistribution',
            'partnerAgencyDistribution',
            'internsTrend',
            'expiringContracts'
        ));
    }
}
