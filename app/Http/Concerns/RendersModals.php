<?php

namespace App\Http\Concerns;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Form / chi tiết mở trong modal mà vẫn là trang đầy đủ khi mở trực tiếp URL.
 *
 *   public function edit(Holiday $holiday) { return $this->modalPage('Holidays/Form', [...]); }
 *   public function update(...)            { ...; return $this->modalSaved('Đã lưu.', route('holidays.index')); }
 *   - Trang Vue bọc nội dung bằng <UiModalFrame>: mở từ <UiButton modal> → khung modal; mở thẳng URL → khung trang.
 *   - Request từ modal mang header X-Remote-Modal (resources/js/lib/remoteModal.js, <UiForm> trong modal).
 *   - Lưu xong từ modal: quay lại trang đang mở (back) kèm thông báo → <UiForm> đóng modal, trang nền có dữ liệu mới.
 */
trait RendersModals
{
    public const MODAL_HEADER = 'X-Remote-Modal';

    /** Request đến từ modal (UiForm / openRemoteModal gửi header X-Remote-Modal). */
    protected function isModalRequest(): bool
    {
        return request()->hasHeader(self::MODAL_HEADER);
    }

    /** Trang Inertia dùng được cả trong modal lẫn trang đầy đủ; prop `asModal` báo cho trang biết đang mở kiểu nào. */
    protected function modalPage(string $component, array $props = []): InertiaResponse
    {
        return Inertia::render($component, [...$props, 'asModal' => $this->isModalRequest()]);
    }

    /**
     * Lưu thành công. Từ modal: về lại trang đang mở + flash; trang thường: redirect $fallbackUrl + flash `$flashKey`
     * (mặc định `status`; module dùng `success` thì truyền 'success').
     */
    protected function modalSaved(string $message, string $fallbackUrl, string $flashKey = 'status'): RedirectResponse
    {
        return $this->isModalRequest()
            ? back()->with($flashKey, $message)
            : redirect($fallbackUrl)->with($flashKey, $message);
    }

    /**
     * Thao tác từ modal bị từ chối vì lý do nghiệp vụ → đóng modal + thông báo lỗi; trang thường: back()->withErrors như cũ.
     */
    protected function modalFailed(string $message, string $errorKey): RedirectResponse
    {
        return $this->isModalRequest()
            ? back()->with('error', $message)
            : back()->withErrors([$errorKey => $message]);
    }
}
