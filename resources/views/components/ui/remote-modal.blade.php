{{--
    <x-ui.remote-modal> — host modal DUY NHẤT cho nội dung tải từ server (htmx), đặt 1 lần trong layouts/app.blade.php.
    Không dùng trực tiếp: mở bằng <x-ui.button :href="..." modal="md"> (xem button.blade.php) hoặc bất kỳ phần tử nào có
    hx-get + hx-target="#remote-modal-body" + data-modal-size. Nội dung server trả về bọc bằng <x-ui.modal-frame>.
    JS điều khiển (skeleton, cỡ, đóng/toast theo HX-Trigger): resources/js/components/remote-modal.js
--}}
<x-ui.modal name="remote" bare>
    <div id="remote-modal-body" class="flex min-h-0 flex-1 flex-col" aria-live="polite"></div>

    {{-- Skeleton hiển thị trong lúc tải --}}
    <template id="remote-modal-skeleton">
        <div class="flex min-h-0 flex-1 flex-col" aria-busy="true">
            <span id="modal-remote-title" class="sr-only">Đang tải…</span>
            <div class="flex shrink-0 items-center justify-between gap-md border-b border-surface-container px-lg py-md">
                <div class="h-6 w-1/2 animate-pulse rounded bg-surface-container-high"></div>
                <button type="button" class="rounded-lg p-xs text-on-surface-variant hover:bg-surface-container-high" @click="close(true)" aria-label="Đóng">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="space-y-md px-lg py-md">
                @foreach ([1, 2, 3] as $i)
                    <div class="space-y-xs">
                        <div class="h-3 w-1/4 animate-pulse rounded bg-surface-container-high"></div>
                        <div class="h-10 w-full animate-pulse rounded-lg bg-surface-container"></div>
                    </div>
                @endforeach
            </div>
        </div>
    </template>
</x-ui.modal>
