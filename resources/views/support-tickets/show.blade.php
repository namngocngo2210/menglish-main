{{-- Chi tiết ticket: mở từ danh sách → modal 3xl (htmx, đẩy URL /tickets/{id}) gồm hội thoại + ô trả lời + thông tin;
     gửi phản hồi / đổi trạng thái / phân công trong modal → server trả lại nội dung modal mới + toast.
     Mở thẳng URL → trang đầy đủ (có lightbox xem ảnh). --}}
@if ($asModal)
    <x-ui.modal-frame :title="'#'.$ticket->code.' · '.$ticket->title" :description="'Tạo bởi '.($ticket->creator?->name ?? 'Hệ thống').' lúc '.$ticket->created_at->format('d/m/Y H:i').' · '.$ticket->category_label" cancel="Đóng">
        <div class="space-y-md" data-testid="ticket-conversation">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                @include('support-tickets.partials.status-form')
                <x-ui.button variant="ghost" size="sm" icon="open_in_new" :href="route('tickets.show', $ticket->id)" hx-boost="false">Mở trang đầy đủ</x-ui.button>
            </div>
            <div class="grid grid-cols-1 gap-md lg:grid-cols-3">
                <div class="space-y-md lg:col-span-2">
                    @include('support-tickets.partials.messages')
                </div>
                @include('support-tickets.partials.info')
            </div>
            @include('support-tickets.partials.reply-form')
        </div>
    </x-ui.modal-frame>
@else
<x-app-layout>
    <x-ui.page-header :title="$ticket->title" :back="route('tickets.index')">
        <x-slot:badges>
            <span class="inline-flex items-center px-2.5 py-1 rounded-xl bg-orange-100 text-primary border border-orange-300 font-mono font-bold text-xs shadow-2xs">#{{ $ticket->code }}</span>
        </x-slot:badges>
        <x-slot:meta>Tạo bởi {{ $ticket->creator?->name }} vào lúc {{ $ticket->created_at->format('d/m/Y H:i') }} · {{ $ticket->category_label }}</x-slot:meta>
        {{-- Status & Assignee Quick Actions --}}
        <x-slot:actions>
            @include('support-tickets.partials.status-form')
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="{ lightboxOpen: false, lightboxImg: '' }">
        {{-- Main Conversation Stream --}}
        <div class="lg:col-span-2 space-y-6">
            @include('support-tickets.partials.messages')
            @include('support-tickets.partials.reply-form')
        </div>

        @include('support-tickets.partials.info')

        {{-- Lightbox Modal for Attachment Zoom --}}
        <div x-show="lightboxOpen" 
             x-cloak 
             @click="lightboxOpen = false" 
             @keydown.escape.window="lightboxOpen = false"
             class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
            <div class="relative max-w-5xl max-h-[90vh] bg-transparent rounded-2xl overflow-hidden shadow-2xl flex flex-col items-center" @click.stop>
                <button @click="lightboxOpen = false" class="absolute top-3 right-3 w-9 h-9 rounded-full bg-black/60 text-white flex items-center justify-center hover:bg-black/80 transition z-10">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
                <img :src="lightboxImg" class="max-w-full max-h-[85vh] rounded-xl object-contain shadow-lg" />
                <div class="mt-2 text-center">
                    <a :href="lightboxImg" target="_blank" download class="inline-flex items-center gap-1.5 text-xs text-white bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg font-semibold transition">
                        <span class="material-symbols-outlined text-sm">download</span>
                        <span>Mở ảnh gốc trong tab mới</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
@endif
