<?php

namespace App\Http\Concerns;

use App\Support\Htmx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Form / chi tiết mở trong modal mà vẫn là trang đầy đủ khi mở trực tiếp URL.
 *
 * Inertia (Vue):
 *   public function edit(Holiday $holiday) { return $this->modalPage('Holidays/Form', [...]); }
 *   public function update(...)            { ...; return $this->modalSaved('Đã lưu.', 'holidays-changed', route('holidays.index')); }
 *   - Trang Vue bọc nội dung bằng <UiModalFrame>: mở từ <UiButton modal> → khung modal; mở thẳng URL → khung trang.
 *   - Request từ modal mang header X-Remote-Modal (resources/js/lib/remoteModal.js, <UiForm> trong modal).
 *   - Lưu xong từ modal: quay lại trang đang mở (back) kèm thông báo → <UiForm> đóng modal, trang nền có dữ liệu mới.
 *
 * htmx (trang Blade chưa chuyển): như cũ — fragment `$asModal`, 204 + HX-Trigger, lỗi validate 422 (App\Support\Htmx).
 */
trait RendersModals
{
    public const MODAL_HEADER = 'X-Remote-Modal';

    /** Request đến từ modal: modal Inertia (X-Remote-Modal) hoặc modal htmx cũ (HX-Request). */
    protected function isModalRequest(): bool
    {
        return request()->hasHeader(self::MODAL_HEADER) || Htmx::isRequest();
    }

    /** Trang Inertia dùng được cả trong modal lẫn trang đầy đủ; prop `asModal` báo cho trang biết đang mở kiểu nào. */
    protected function modalPage(string $component, array $props = []): InertiaResponse
    {
        return Inertia::render($component, [...$props, 'asModal' => $this->isModalRequest()]);
    }

    protected function modalView(string $view, array $data = []): Response
    {
        return response()
            ->view($view, [...$data, 'asModal' => $this->isModalRequest()])
            // Cùng URL trả 2 dạng (fragment / trang đầy đủ) → cache trình duyệt phải tách theo header.
            ->header('Vary', 'HX-Request');
    }

    /**
     * Lưu thành công. Modal Inertia: về lại trang đang mở + flash; htmx: 204 + HX-Trigger {close-modal, toast, <refreshEvent>};
     * trang thường: redirect $fallbackUrl + flash `$flashKey` (mặc định `status`; module dùng `success` thì truyền 'success').
     */
    protected function modalSaved(string $message, string $refreshEvent, string $fallbackUrl, string $flashKey = 'status'): Response|RedirectResponse
    {
        if (Htmx::isRequest()) {
            return response()->noContent()->header('HX-Trigger', json_encode([
                'close-modal' => true,
                'toast' => ['message' => $message, 'type' => 'success'],
                $refreshEvent => true,
            ]));
        }

        return $this->isModalRequest()
            ? back()->with($flashKey, $message)
            : redirect($fallbackUrl)->with($flashKey, $message);
    }

    /**
     * Thao tác từ modal bị từ chối vì lý do nghiệp vụ → đóng modal + thông báo lỗi; trang thường: back()->withErrors như cũ.
     */
    protected function modalFailed(string $message, string $errorKey): Response|RedirectResponse
    {
        if (Htmx::isRequest()) {
            return response()->noContent()->header('HX-Trigger', json_encode([
                'close-modal' => true,
                'toast' => ['message' => $message, 'type' => 'error'],
            ]));
        }

        return $this->isModalRequest()
            ? back()->with('error', $message)
            : back()->withErrors([$errorKey => $message]);
    }

    /**
     * Kết thúc luồng bằng chuyển sang trang khác (vd. nhập Excel xong → danh sách kèm kết quả).
     * htmx: 204 + HX-Redirect; Inertia / trang thường: trả nguyên $redirect (modal tự đóng khi chuyển trang).
     */
    protected function modalRedirect(RedirectResponse $redirect): Response|RedirectResponse
    {
        return Htmx::isRequest()
            ? response()->noContent()->header('HX-Redirect', $redirect->getTargetUrl())
            : $redirect;
    }

    /**
     * Lỗi nghiệp vụ cần hiện lại form kèm lỗi (thay `redirect()->back()->withErrors($errors)`).
     * htmx: ném ValidationException → render lại form trong modal (422); Inertia / thường: back()->withErrors().
     *
     * @param  array<string, string>  $errors
     */
    protected function modalBack(array $errors): RedirectResponse
    {
        if (Htmx::isRequest()) {
            throw ValidationException::withMessages($errors);
        }

        return back()->withErrors($errors);
    }

    /**
     * Thao tác trong modal xong nhưng giữ modal mở với nội dung mới (vd. gửi phản hồi ticket → hội thoại cập nhật).
     * htmx: gắn HX-Trigger {toast, <refreshEvent>} vào fragment vừa render.
     */
    protected function modalUpdated(Response $fragment, string $message, ?string $refreshEvent = null): Response
    {
        return $fragment->header('HX-Trigger', json_encode(array_filter([
            'toast' => ['message' => $message, 'type' => 'success'],
            $refreshEvent => $refreshEvent ? true : null,
        ])));
    }
}
