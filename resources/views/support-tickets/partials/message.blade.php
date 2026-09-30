{{-- Một bình luận trong hội thoại ticket — dùng cho danh sách (messages.blade.php) và cho phản hồi JSON sau khi gửi.
     Biến: $msg (kèm user), $ticket, $asModal; $pending = true → khung mẫu (<template>) của bình luận đang gửi:
     ticket-reply.js điền nội dung vào [data-message-body] và đổi data-state (sending → failed) để hiện "Đang gửi…"
     hoặc dòng "Chưa gửi được" + nút Gửi lại / Bỏ. --}}
@php
    $asModal = $asModal ?? false;
    $pending = $pending ?? false;
@endphp
<div @class([
        'group/msg bg-surface-container-lowest rounded-2xl border shadow-sm p-5 space-y-3 transition-opacity',
        'border-warning/30 bg-warning-container/20' => $msg->is_internal_note,
        'border-surface-container-highest' => ! $msg->is_internal_note,
        'data-[state=sending]:opacity-70 data-[state=failed]:border-error/40' => $pending,
     ])
     @if ($pending) data-message-pending data-state="sending" @else data-message-id="{{ $msg->id }}" @endif>
    <div class="flex items-center justify-between gap-sm">
        <div class="flex items-center gap-2.5">
            <x-ui.avatar :name="$msg->user?->name ?? '?'" size="sm" />
            <div>
                <div class="font-bold text-xs text-on-surface flex items-center gap-2">
                    <span>{{ $msg->user?->name }}</span>
                    @if ($msg->is_internal_note)
                        <x-ui.badge color="warning">Ghi chú nội bộ</x-ui.badge>
                    @endif
                </div>
                <div class="text-xs text-on-surface-subtle font-mono">{{ $pending ? 'Vừa xong' : $msg->created_at->format('d/m/Y H:i') }}</div>
            </div>
        </div>
        @if ($pending)
            <span class="hidden items-center gap-1 text-xs font-medium text-on-surface-subtle group-data-[state=sending]/msg:inline-flex" role="status">
                <span class="material-symbols-outlined animate-spin text-[16px]" aria-hidden="true">progress_activity</span>
                <span data-message-sending-label>Đang gửi…</span>
            </span>
        @endif
    </div>

    <div class="text-xs text-on-surface leading-relaxed whitespace-pre-line pl-10" @if ($pending) data-message-body @endif>{{ $msg->message }}</div>

    @if ($pending)
        <div class="hidden flex-wrap items-center gap-sm pl-10 text-xs font-medium text-error group-data-[state=failed]/msg:flex" role="alert">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">error</span>
            <span class="flex-1" data-message-error>Chưa gửi được.</span>
            <x-ui.button size="sm" variant="secondary" icon="refresh" data-message-retry>Gửi lại</x-ui.button>
            <x-ui.button size="sm" variant="ghost" data-message-discard>Bỏ</x-ui.button>
        </div>
    @endif

    {{-- Attachments Display --}}
    @if (!empty($msg->attachment_list))
        <div class="pl-10 pt-2">
            <div class="text-xs font-bold text-on-surface-variant mb-2 flex items-center gap-1">
                <span class="material-symbols-outlined text-[15px] text-primary">attach_file</span>
                <span>Tệp / Hình ảnh đính kèm ({{ count($msg->attachment_list) }}):</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                @foreach ($msg->attachment_list as $file)
                    @php
                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
                        $fileUrl = route('tickets.attachment', ['id' => $ticket->id, 'path' => $file]);
                    @endphp
                    @if ($isImg)
                        {{-- Trang: phóng to bằng lightbox; modal: mở ảnh ở tab mới (không lồng lớp phủ trong modal) --}}
                        <a href="{{ $fileUrl }}" target="_blank" rel="noopener" hx-boost="false"
                           class="group relative block rounded-xl border border-surface-container-highest overflow-hidden bg-surface-container-low hover:shadow-md transition cursor-pointer"
                           @unless ($asModal) @click.prevent="lightboxImg = @js($fileUrl); lightboxOpen = true" @endunless>
                            <div class="h-28 overflow-hidden bg-surface-container flex items-center justify-center">
                                <img src="{{ $fileUrl }}" alt="Attachment" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" />
                            </div>
                            <div class="p-1.5 bg-surface-container-lowest flex items-center justify-between">
                                <span class="text-xs font-medium text-on-surface-variant truncate max-w-[120px]">{{ basename($file) }}</span>
                                <span class="material-symbols-outlined text-xs text-on-surface-subtle group-hover:text-primary">zoom_in</span>
                            </div>
                        </a>
                    @else
                        <a href="{{ $fileUrl }}" target="_blank" hx-boost="false" class="flex items-center gap-2 p-2.5 rounded-xl border border-surface-container-highest bg-surface-container-lowest hover:bg-surface-container-low transition shadow-sm group">
                            <div class="w-8 h-8 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                {{ strtoupper($ext) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-bold text-on-surface truncate group-hover:text-primary">{{ basename($file) }}</div>
                                <div class="text-xs text-on-surface-subtle">Nhấn để tải về</div>
                            </div>
                            <span class="material-symbols-outlined text-sm text-on-surface-subtle group-hover:text-primary">download</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>
