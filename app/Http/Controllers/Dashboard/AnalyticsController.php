<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Reservation;
use App\Models\Complex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', Carbon::now('Africa/Khartoum')->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now('Africa/Khartoum')->toDateString());

        return response()->json([
            'overview' => $this->getOverview($startDate, $endDate),
            'reservations' => $this->getReservationsStats($startDate, $endDate),
            'doctors' => $this->getDoctorsStats($startDate, $endDate),
            'complexes' => $this->getComplexesStats($startDate, $endDate),
            'period_counts' => $this->getReservationPeriodCounts(),
        ]);
    }

    /**
     * Reservation counts by period (for dashboard widget). Allowed for analytics.view or reservations.manage.
     */
    public function periodCounts(Request $request)
    {
        return response()->json([
            'period_counts' => $this->getReservationPeriodCounts(),
        ]);
    }

    /**
     * Reservation counts for fixed periods: today, this week, next week, current month, next month, total.
     */
    protected function getReservationPeriodCounts(): array
    {
        $tz = 'Africa/Khartoum';
        $now = Carbon::now($tz);

        $todayStart = $now->copy()->startOfDay()->toDateString();
        $todayEnd = $now->copy()->endOfDay()->toDateString();

        $thisWeekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $thisWeekEnd = $now->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $nextWeekStart = $now->copy()->addWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
        $nextWeekEnd = $now->copy()->addWeek()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $currentMonthStart = $now->copy()->startOfMonth()->toDateString();
        $currentMonthEnd = $now->copy()->endOfMonth()->toDateString();

        $nextMonthStart = $now->copy()->addMonth()->startOfMonth()->toDateString();
        $nextMonthEnd = $now->copy()->addMonth()->endOfMonth()->toDateString();

        return [
            'today' => Reservation::whereBetween('date', [$todayStart, $todayEnd])->count(),
            'this_week' => Reservation::whereBetween('date', [$thisWeekStart, $thisWeekEnd])->count(),
            'next_week' => Reservation::whereBetween('date', [$nextWeekStart, $nextWeekEnd])->count(),
            'current_month' => Reservation::whereBetween('date', [$currentMonthStart, $currentMonthEnd])->count(),
            'next_month' => Reservation::whereBetween('date', [$nextMonthStart, $nextMonthEnd])->count(),
            'total' => Reservation::count(),
        ];
    }

    protected function getOverview($startDate, $endDate)
    {
        return [
            'total_doctors' => Doctor::count(),
            'active_doctors' => Doctor::where('status', 'active')->count(),
            'total_complexes' => Complex::count(),
            'total_reservations' => Reservation::whereBetween('date', [$startDate, $endDate])->count(),
            'confirmed_reservations' => Reservation::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'confirmed')->count(),
            'pending_reservations' => Reservation::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'pending')->count(),
            'cancelled_reservations' => Reservation::whereBetween('date', [$startDate, $endDate])
                ->where('status', 'cancelled')->count(),
        ];
    }

    protected function getReservationsStats($startDate, $endDate)
    {
        // Daily reservations
        $daily = Reservation::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('DATE(date) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // Weekly reservations
        $weekly = Reservation::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('YEARWEEK(date, 1) as week, COUNT(*) as count')
            ->groupBy('week')
            ->orderBy('week')
            ->get();

        // Monthly reservations
        $monthly = Reservation::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // By status
        $byStatus = Reservation::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        return [
            'daily' => $daily,
            'weekly' => $weekly,
            'monthly' => $monthly,
            'by_status' => $byStatus,
        ];
    }

    protected function getDoctorsStats($startDate, $endDate)
    {
        // Top doctors by reservations
        $topDoctors = Reservation::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('doctor_id, COUNT(*) as reservations_count')
            ->with('doctor')
            ->groupBy('doctor_id')
            ->orderBy('reservations_count', 'desc')
            ->limit(10)
            ->get();

        // Doctors by status
        $byStatus = Doctor::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        return [
            'top_doctors' => $topDoctors,
            'by_status' => $byStatus,
        ];
    }

    protected function getComplexesStats($startDate, $endDate)
    {
        // Top complexes by reservations
        $topComplexes = Reservation::whereBetween('date', [$startDate, $endDate])
            ->selectRaw('complex_id, COUNT(*) as reservations_count')
            ->with('complex')
            ->groupBy('complex_id')
            ->orderBy('reservations_count', 'desc')
            ->limit(10)
            ->get();

        return [
            'top_complexes' => $topComplexes,
        ];
    }
}

