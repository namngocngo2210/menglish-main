<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminNotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isGlobalViewer = !$user || $user->hasRole('admin') || $user->hasRole('manager') || $user->roles->isEmpty();

        // Tự động quét để có dữ liệu mới nhất (nếu là admin/manager)
        if ($isGlobalViewer) {
            $this->notificationService->scanAndSyncStaleLeads();
        }

        $query = AdminNotification::latest();

        // Phân quyền hiển thị thông báo theo luồng
        if ($user) {
            if ($isGlobalViewer) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                });
            } else {
                $query->where('user_id', $user->id);
            }
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($request->has('unread')) {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate($request->perPage(15))->withQueryString();

        $baseQuery = AdminNotification::query();
        if ($user) {
            if ($isGlobalViewer) {
                $baseQuery->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                });
            } else {
                $baseQuery->where('user_id', $user->id);
            }
        }

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'unread' => (clone $baseQuery)->where('is_read', false)->count(),
            'stale_leads' => (clone $baseQuery)->where('type', 'stale_lead_24h')->where('is_read', false)->count(),
        ];

        return view('notifications.index', compact('notifications', 'stats'));
    }

    public function dropdown(Request $request)
    {
        $user = $request->user();

        $unreadCount = $this->notificationService->getUnreadCount($user);
        $notifications = $this->notificationService->getUserNotifications($user, 8);

        return response()->json([
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        $this->notificationService->markAsRead($id);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('status', 'Đã đánh dấu thông báo là đã đọc.');
    }

    public function markAllAsRead(Request $request)
    {
        $this->notificationService->markAllAsRead($request->user());

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('status', 'Đã đánh dấu tất cả thông báo là đã đọc.');
    }

    public function scan(Request $request)
    {
        $count = $this->notificationService->scanAndSyncStaleLeads();

        return redirect()->back()->with('status', "Đã quét hệ thống CRM: Phát hiện {$count} Lead bị sót quá 24h.");
    }
}
