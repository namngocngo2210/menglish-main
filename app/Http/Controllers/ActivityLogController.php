<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = Activity::query()
            ->with('causer')
            ->latest();

        // 1. Filter by Module (log_name)
        if ($request->filled('log_name')) {
            $query->where('log_name', $request->input('log_name'));
        }

        // 2. Filter by Event
        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        // 3. Filter by User / Causer
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->input('causer_id'));
        }

        // 4. Filter by Date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // 5. Search keyword
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($sub) use ($search) {
                $sub->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhereHas('causer', function ($c) use ($search) {
                        $c->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $logs = $query->paginate(request()->perPage(25))->withQueryString();

        // Module choices & Users for filter dropdowns
        $logNames = [
            'CRM & Leads',
            'Học phí & Thu chi',
            'Học viên & Lớp học',
            'Khảo sát & Đề thi',
            'Giáo trình & Syllabus',
            'Bảng lương & Chấm công',
            'Quản lý công việc',
            'Ticket hỗ trợ',
            'Quản lý Media',
            'Cấu hình hệ thống',
            'Người dùng & Phân quyền',
            'Tài khoản & Hồ sơ',
        ];

        // Merge any extra log names from DB
        $dbLogNames = Activity::query()->whereNotNull('log_name')->distinct()->pluck('log_name')->toArray();
        $allLogNames = array_unique(array_merge($logNames, $dbLogNames));

        $events = Activity::query()->whereNotNull('event')->distinct()->pluck('event');
        $users = User::select('id', 'name', 'email')->orderBy('name')->get();

        // Quick Stats
        $totalLogsToday = Activity::whereDate('created_at', today())->count();
        $totalLogsCount = Activity::count();
        $activeUsersToday = Activity::whereDate('created_at', today())->distinct('causer_id')->count('causer_id');

        return view('activity-logs.index', compact(
            'logs',
            'allLogNames',
            'events',
            'users',
            'totalLogsToday',
            'totalLogsCount',
            'activeUsersToday'
        ));
    }
}
