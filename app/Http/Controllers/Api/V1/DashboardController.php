<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InternDocumentResource;
use App\Http\Resources\V1\InternResource;
use App\Models\Company;
use App\Models\Intern;
use App\Services\InternWorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->get('year', date('Y'));
        $month = $request->get('month');

        $today = now();
        $todayStr = $today->toDateString();
        $calDateStr = (string) $request->get('cal_date', $todayStr);
        $calTimestamp = strtotime($calDateStr) ?: strtotime($todayStr);

        $calendarDate = date('Y-m-01', $calTimestamp);
        $calendarYear = date('Y', strtotime($calendarDate));
        $calendarMonth = date('m', strtotime($calendarDate));
        $calendarMonthName = date('F', strtotime($calendarDate));

        $isCurrentMonth = $calendarYear === $today->format('Y')
            && $calendarMonth === $today->format('m');
        $pillDate = $isCurrentMonth ? $todayStr : $calendarDate;

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

        $user = $request->user();
        $showInternStats = $user && method_exists($user, 'hasRole')
            ? $user->hasRole('admin', 'peso_manager')
            : false;
        $isInternUser = $user && method_exists($user, 'isIntern') && $user->isIntern();

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
            $internSelfModel = $user->intern?->load(['user', 'partnerAgency', 'internDocuments.documentType']);
            if ($internSelfModel) {
                $docs = $internSelfModel->internDocuments;
                $internStats['total_docs'] = $docs->count();
                $internStats['approved_docs'] = $docs->where('review_status', 'approved')->count();
                $internStats['pending_docs'] = $docs->where('review_status', 'pending')->count();
                $internStats['rejected_docs'] = $docs->where('review_status', 'rejected')->count();
                $internDocuments = $docs->sortByDesc('created_at')->take(5)->values();
                $workCalendar = app(InternWorkCalendar::class)
                    ->month($internSelfModel, $request->get('work_month'));
                // Counts are already computed above; do not resend every
                // document inside `intern_self` (only the five most recent are
                // returned separately as `intern_documents`).
                $internSelf = $internSelfModel->unsetRelation('internDocuments');
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
                ->whereYear('created_at', $year)
                ->get(['created_at'])
                ->groupBy(fn (Intern $intern) => (int) $intern->created_at->format('n'))
                ->sortKeys()
                ->map(fn ($group, $month) => [
                    'month' => (int) $month,
                    'count' => $group->count(),
                ])
                ->values();
        }

        return response()->json([
            'year' => $year,
            'month' => $month,
            'calendar' => [
                'data' => $calendarData,
                'date' => $calendarDate,
                'pill_date' => $pillDate,
                'is_current_month' => $isCurrentMonth,
                'month_name' => $calendarMonthName,
                'year' => $calendarYear,
            ],
            'show_intern_stats' => $showInternStats,
            'is_intern_user' => $isInternUser,
            'intern_self' => $internSelf ? new InternResource($internSelf) : null,
            'intern_documents' => InternDocumentResource::collection($internDocuments),
            'intern_stats' => $internStats,
            'work_calendar' => $workCalendar,
            'total_interns' => $totalInterns,
            'active_interns' => $activeInterns,
            'inactive_interns' => $inactiveInterns,
            'completed_interns' => $completedInterns,
            'expiring_soon_count' => $expiringSoonCount,
            'intern_status_distribution' => $internStatusDistribution,
            'partner_agency_distribution' => $partnerAgencyDistribution,
            'interns_trend' => $internsTrend,
            'expiring_contracts' => InternResource::collection($expiringContracts),
        ]);
    }
}
