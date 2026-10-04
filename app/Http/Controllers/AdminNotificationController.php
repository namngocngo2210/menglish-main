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

        // Quét lead tồn đọng chạy theo lịch (crm:scan-stale-leads, hằng giờ) và nút "Quét" thủ công, không quét khi mở trang.
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
                // Tin cần gửi phụ huynh thủ công (khách chưa có email): nút sao chép nội dung + mở hồ sơ liên quan.
                'copy_text' => $notif->data['copy_text'] ?? null,
                'link' => $notif->data['link'] ?? null,
                'created_at' => $notif->created_at->toIso8601String(),
                'created_ago' => $notif->created_at->diffForHumans(),
            ]);

        $baseQuery = AdminNotification::query();
        if ($user) {
            $baseQuery->forRecipient($user);
        }

        $counts = $baseQuery->toBase()->selectRaw(
            "COUNT(*) as total, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread, SUM(CASE WHEN is_read = 0 AND type = 'stale_lead_24h' THEN 1 ELSE 0 END) as stale_leads"
        )->first();

        $stats = [
            'total' => (int) $counts->total,
            'unread' => (int) $counts->unread,
            'stale_leads' => (int) $counts->stale_leads,
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
        $this->notificationService->markAsRead((int) $id, $request->user());

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
