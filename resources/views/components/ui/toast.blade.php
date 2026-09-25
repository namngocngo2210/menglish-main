{{--
    <x-ui.toast> — vùng hiển thị toast toàn cục (đã đặt 1 lần trong layouts/app.blade.php, KHÔNG thêm lại trong trang).
    Tự hiện flash session: success/status (thành công), error, warning, info.
    Hiện toast từ JS / Alpine:
      window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Đã lưu', type: 'success' } }))
      $dispatch('toast', { message: 'Có lỗi xảy ra', type: 'error' })      // type: success | error | warning | info
    An toàn XSS: message được truyền qua @js (JSON-encode) và render bằng x-text.
--}}
@php
    $flashes = collect([
        ['success', session('success') ?? session('status')],
        ['error', session('error')],
        ['warning', session('warning')],
        ['info', session('info')],
    ])
      // Bỏ qua status dạng mã (vd. "profile-updated", "verification-link-sent") — trang tự xử lý.
      ->filter(fn ($f) => is_string($f[1]) && $f[1] !== '' && ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/', $f[1]))
      ->map(fn ($f) => ['type' => $f[0], 'message' => $f[1]])
      ->values()
      ->all();
@endphp

<div x-data="{
        toasts: [],
        add(message, type = 'success') {
            if (!message) return;
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: String(message), type });
            setTimeout(() => this.remove(id), 5000);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
        icon(type) { return { success: 'check_circle', error: 'error', warning: 'warning', info: 'info' }[type] || 'info'; },
     }"
     x-init="(@js($flashes)).forEach(f => add(f.message, f.type))"
     @toast.window="add($event.detail?.message, $event.detail?.type || 'success')"
     class="pointer-events-none fixed bottom-lg right-lg z-[70] flex w-full max-w-sm flex-col gap-sm"
     aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="pointer-events-auto flex items-center gap-md rounded-xl bg-inverse-surface p-md text-inverse-on-surface shadow-level-3"
             :role="toast.type === 'error' ? 'alert' : 'status'">
            <span class="material-symbols-outlined shrink-0"
                  :class="{ 'text-tertiary-fixed': toast.type === 'success', 'text-error-container': toast.type === 'error', 'text-amber-300': toast.type === 'warning', 'text-secondary-fixed-dim': toast.type === 'info' }"
                  x-text="icon(toast.type)"></span>
            <span class="flex-1 font-body-medium text-body-medium" x-text="toast.message"></span>
            <button type="button" class="shrink-0 rounded p-0.5 text-inverse-on-surface/70 hover:text-inverse-on-surface" @click="remove(toast.id)" aria-label="Đóng thông báo">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        </div>
    </template>
</div>
