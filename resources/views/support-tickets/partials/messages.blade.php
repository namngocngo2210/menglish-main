{{-- Luồng hội thoại ticket — dùng chung trang và modal. Biến: $ticket (messages.user), $asModal. --}}
@php
    $asModal = $asModal ?? false;
@endphp
{{-- Messages Timeline --}}
<div class="space-y-4">
    @foreach ($ticket->messages->reverse() as $msg)
        <div class="bg-surface-container-lowest rounded-2xl border {{ $msg->is_internal_note ? 'border-warning/30 bg-warning-container/20' : 'border-surface-container-highest' }} shadow-sm p-5 space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-primary-container/10 text-primary font-bold text-xs flex items-center justify-center">
                        {{ substr($msg->user?->name ?? 'U', 0, 1) }}
                    </div>
                    <div>
                        <div class="font-bold text-xs text-on-surface flex items-center gap-2">
                            <span>{{ $msg->user?->name }}</span>
                            @if ($msg->is_internal_note)
                                <x-ui.badge color="warning">Ghi chú nội bộ</x-ui.badge>
                            @endif
                        </div>
                        <div class="text-[10px] text-on-surface-variant/70 font-mono">{{ $msg->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                </div>
            </div>

            <div class="text-xs text-on-surface leading-relaxed whitespace-pre-line pl-10">
                {{ $msg->message }}
            </div>

            {{-- Attachments Display --}}
            @if (!empty($msg->attachment_list))
                <div class="pl-10 pt-2">
                    <div class="text-[11px] font-bold text-on-surface-variant mb-2 flex items-center gap-1">
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
                                        <span class="text-[10px] font-medium text-on-surface-variant truncate max-w-[120px]">{{ basename($file) }}</span>
                                        <span class="material-symbols-outlined text-xs text-on-surface-variant/70 group-hover:text-primary">zoom_in</span>
                                    </div>
                                </a>
                            @else
                                <a href="{{ $fileUrl }}" target="_blank" hx-boost="false" class="flex items-center gap-2 p-2.5 rounded-xl border border-surface-container-highest bg-surface-container-lowest hover:bg-surface-container-low transition shadow-sm group">
                                    <div class="w-8 h-8 rounded-lg bg-primary-container/10 text-primary flex items-center justify-center font-bold text-[10px] shrink-0">
                                        {{ strtoupper($ext) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-[11px] font-bold text-on-surface truncate group-hover:text-primary">{{ basename($file) }}</div>
                                        <div class="text-[9px] text-on-surface-variant/70">Nhấn để tải về</div>
                                    </div>
                                    <span class="material-symbols-outlined text-sm text-on-surface-variant/70 group-hover:text-primary">download</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
