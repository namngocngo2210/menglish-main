<?php

namespace App\Http\Concerns;

use App\Support\Htmx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Render form/chi tiết trong modal (htmx) mà vẫn giữ trang đầy đủ khi mở trực tiếp URL.
 *
 *   public function edit(Holiday $holiday) { return $this->modalView('holidays.form', [...]); }
 *   public function update(...)            { ...; return $this->modalSaved('Đã lưu.', 'holidays-changed', route('holidays.index')); }
 *
 * View nhận biến `$asModal` (bool): true → chỉ render `<x-ui.modal-frame>` bọc form partial; false → trang `<x-app-layout>`.
 * Validate lỗi khi gọi từ modal: xử lý chung ở bootstrap/app.php (App\Support\Htmx::renderValidationForm) → 422 + form kèm lỗi.
 * Lỗi nghiệp vụ (không phải validate, vd. không xoá được vì đang dùng): modalFailed(). Kết thúc bằng chuyển trang: modalRedirect().
 */
trait RendersModals
{
    protected function isModalRequest(): bool
    {
        return Htmx::isRequest();
    }

    protected function modalView(string $view, array $data = []): Response
    {
        return response()
            ->view($view, [...$data, 'asModal' => $this->isModalRequest()])
            // Cùng URL trả 2 dạng (fragment / trang đầy đủ) → cache trình duyệt phải tách theo header.
            ->header('Vary', 'HX-Request');
    }

    /**
     * Lưu thành công. htmx: 204 + HX-Trigger {close-modal, toast, <refreshEvent>}; thường: redirect + flash `status` như cũ.
     */
    protected function modalSaved(string $message, string $refreshEvent, string $fallbackUrl): Response|RedirectResponse
    {
        if (! $this->isModalRequest()) {
            return redirect($fallbackUrl)->with('status', $message);
        }

        return response()->noContent()->header('HX-Trigger', json_encode([
            'close-modal' => true,
            'toast' => ['message' => $message, 'type' => 'success'],
            $refreshEvent => true,
        ]));
    }

    /**
     * Thao tác từ modal bị từ chối vì lý do nghiệp vụ. htmx: 204 + đóng modal + toast lỗi; thường: back()->withErrors như cũ.
     */
    protected function modalFailed(string $message, string $errorKey): Response|RedirectResponse
    {
        if (! $this->isModalRequest()) {
            return back()->withErrors([$errorKey => $message]);
        }

        return response()->noContent()->header('HX-Trigger', json_encode([
            'close-modal' => true,
            'toast' => ['message' => $message, 'type' => 'error'],
        ]));
    }

    /**
     * Kết thúc luồng bằng chuyển sang trang khác (vd. nhập Excel xong → danh sách kèm kết quả).
     * htmx: 204 + HX-Redirect (tải trang đầy đủ, flash đã gắn vào $redirect vẫn còn); thường: trả nguyên $redirect.
     */
    protected function modalRedirect(RedirectResponse $redirect): Response|RedirectResponse
    {
        return $this->isModalRequest()
            ? response()->noContent()->header('HX-Redirect', $redirect->getTargetUrl())
            : $redirect;
    }
}
