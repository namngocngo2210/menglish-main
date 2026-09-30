<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminNotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isGlobalViewer = ! $user || NotificationService::seesSystemNotifications($user);

        // Tự động quét để có dữ liệu mới nhất (nếu là admin/manager)
        if ($isGlobalViewer) {
            $this->notificationService->scanAndSyncStaleLeads();
        }

        $query = AdminNotification::latest();

        // Phân quyền hiển thị thông báo theo luồng
        if ($user) {
            $query->forRecipient($user);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($request->has('unread')) {
            $query->where('is_read', false);
        }

        $notifications = $query->paginate($request->perPage(15))->withQueryString()
            ->through(fn (AdminNotification $notif) => [
                'id' => $notif->id,
                'title' => $notif->title,
                'message' => $notif->message,
                'type_label' => $notif->type_label,
                'icon' => $notif->icon,
                'badge_color' => $notif->badge_color,
                'is_read' => (bool) $notif->is_read,
                // Lead tồn đọng: thông tin khách + nút "Xử lý Lead ngay".
                'lead' => $notif->data && isset($notif->data['customer_id']) ? [
                    'customer_id' => $notif->data['customer_id'],
                    'customer_name' => $notif->data['customer_name'] ?? null,
                    'customer_phone' => $notif->data['customer_phone'] ?? null,
                    'assigned_user' => $notif->data['assigned_user'] ?? null,
                    'hours_elapsed' => $notif->data['hours_elapsed'] ?? null,
                ] : null,
                'created_at' => $notif->created_at->toIso8601String(),
                'created_ago' => $notif->created_at->diffForHumans(),
            ]);

        $baseQuery = AdminNotification::query();
        if ($user) {
            $baseQuery->forRecipient($user);
        }

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'unread' => (clone $baseQuery)->where('is_read', false)->count(),
            'stale_leads' => (clone $baseQuery)->where('type', 'stale_lead_24h')->where('is_read', false)->count(),
        ];

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'stats' => $stats,
            'filters' => ['type' => $request->input('type'), 'unread' => (bool) $request->input('unread')],
        ]);
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
