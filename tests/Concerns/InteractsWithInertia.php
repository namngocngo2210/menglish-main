<?php

namespace Tests\Concerns;

/**
 * Trang Inertia (Vue) trong test:
 *   - request thường → HTML thật (render phía server, xem Tests\Support\InertiaSsrServer) → assertSee như trang Blade;
 *     props của trang → ->assertInertia(fn (AssertableInertia $page) => $page->component('Holidays/Index')->where(...))
 *   - mở trong modal (UiButton modal / UiForm trong modal) → header X-Remote-Modal (self::MODAL):
 *     trang nhận prop asModal = true; lưu xong server quay lại trang đang mở (->from(...)) kèm thông báo.
 */
trait InteractsWithInertia
{
    protected const MODAL = ['X-Remote-Modal' => 'true'];
}
