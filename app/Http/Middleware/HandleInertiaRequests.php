<?php

namespace App\Http\Middleware;

use App\Http\Concerns\RendersModals;
use App\Support\Navigation\AppShell;
use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia: trang gốc `inertia.blade.php` + dữ liệu dùng chung cho mọi trang Vue.
 *
 * Shared props:
 *   shell  — khung ứng dụng (sidebar, topbar, thông báo, tab workspace, menu Cài đặt), xem App\Support\Navigation\AppShell
 *   can    — quyền của user dạng { 'lead.create': true, ... } (chỉ gồm quyền được cấp) — Vue dùng can('lead.create')
 *   flash  — thông báo sau khi chuyển trang: [{ type: success|error|warning|info, message }] — layout hiện thành toast
 *   errors — lỗi validate (Inertia tự thêm)
 * Quyền trên đối tượng cụ thể (policy, vd. sửa đúng khách này) do controller tính và truyền vào props của trang.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'inertia';

    /** Flash dạng thông báo cho người dùng; khoá khác (kết quả nhập Excel, mật khẩu tạm…) trang tự đọc qua props. */
    private const FLASH_TYPES = ['success' => ['success', 'status'], 'error' => ['error'], 'warning' => ['warning'], 'info' => ['info']];

    /**
     * Link Inertia tới trang không phải Inertia (bản in, tải file, trang lỗi 403/404…): trả 409 + X-Inertia-Location
     * để trình duyệt mở hẳn URL đó thay vì hiện HTML trong hộp lỗi. Modal (X-Remote-Modal) gặp lỗi thì giữ nguyên mã lỗi.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        if ($request->header('X-Inertia') && $request->isMethod('GET')
            && ! $response->headers->has('X-Inertia')
            && ! $response->isRedirection()
            && $response->getStatusCode() !== 409
            && ! ($response->getStatusCode() >= 400 && $request->hasHeader(RendersModals::MODAL_HEADER))) {
            return Inertia::location($request->fullUrl());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'shell' => fn () => app(AppShell::class)->for($request->user(), $request),
            'can' => fn () => $this->abilities($request),
            'flash' => fn () => $this->flash($request),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $granted = [];
        foreach (PermissionCatalog::allPermissions($user->getRoleNames()) as $permission) {
            if ($user->can($permission)) {
                $granted[$permission] = true;
            }
        }

        return $granted;
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private function flash(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $messages = [];
        foreach (self::FLASH_TYPES as $type => $keys) {
            foreach ($keys as $key) {
                $message = $request->session()->get($key);
                // Bỏ qua status dạng mã (vd. "profile-updated", "verification-link-sent") — trang tự xử lý.
                if (is_string($message) && $message !== '' && ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $message)) {
                    $messages[] = ['type' => $type, 'message' => $message];
                    break;
                }
            }
        }

        return $messages;
    }
}
