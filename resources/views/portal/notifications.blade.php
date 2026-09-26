<x-app-layout>
    <x-ui.page-header title="Thông báo" icon="notifications" :back="route('portal.student.home', ['studentId' => $student?->id])">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="cottage" :href="route('portal.student.home', ['studentId' => $student?->id])">Về Trang chủ</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    

    {{-- Mobile Frame for Notifications --}}
    <div class="max-w-[430px] mx-auto bg-surface-container-lowest min-h-[844px] shadow-2xl rounded-3xl border border-surface-container-highest overflow-hidden flex flex-col relative pb-24 my-4"
         x-data="{ unreadOnly: false }">

        {{-- Header Partial --}}
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Thông báo',
            'showBack' => true,
            'backUrl' => route('portal.student.home', ['studentId' => $student?->id])
        ])

        {{-- Contextual Subheader --}}
        <div class="px-4 py-3 bg-surface-container-low/80 border-b border-surface-container-highest flex items-center justify-between">
            <span class="text-xs font-bold text-on-surface">Tất cả thông báo</span>
            <form action="{{ route('portal.student.notifications.read') }}" method="POST">
                @csrf
                <x-ui.button type="submit" variant="ghost" size="sm" class="text-primary">
                    Đánh dấu tất cả đã đọc
                </x-ui.button>
            </form>
        </div>

        {{-- Main Notification List --}}
        <main class="flex-1 overflow-y-auto">
            <div class="divide-y divide-surface-container-highest">
                @forelse($notifications as $notif)
                    @php
                        $isModel = $notif instanceof \App\Models\AcademicRecord;
                        $notifId = $isModel ? $notif->id : ($notif['id'] ?? null);
                        $data = $isModel ? ($notif->data ?? []) : $notif;
                        $isUnread = !empty($data['unread']);
                        $title = $isModel ? $notif->title : ($notif['title'] ?? '');
                        $content = $data['content'] ?? '';
                        $time = $data['created_at'] ?? ($isModel ? $notif->created_at->format('d/m/Y H:i') : ($notif['time'] ?? ''));
                        $icon = $data['icon'] ?? 'notifications';
                        $bgColor = $data['bg_color'] ?? 'bg-primary-container/10';
                        $textColor = $data['text_color'] ?? 'text-primary';
                    @endphp
                    <div class="flex items-start px-4 py-3.5 hover:bg-surface-container-low transition-colors relative group {{ !$isUnread ? 'opacity-80' : 'bg-primary-container/10' }}">
                        @if($isUnread)
                            {{-- Unread Indicator Dot --}}
                            <div class="absolute left-2 top-1/2 -translate-y-1/2 w-2 h-2 bg-primary-container rounded-full shadow-xs"></div>
                        @endif

                        <div class="ml-2 mr-3 shrink-0 w-10 h-10 rounded-full {{ $bgColor }} flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px] {{ $textColor }}" style="font-variation-settings: 'FILL' 1;">
                                {{ $icon }}
                            </span>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-0.5">
                                <h3 class="text-xs font-bold text-on-surface pr-2 truncate {{ $isUnread ? 'font-bold text-on-surface' : 'font-medium text-on-surface-variant' }}">
                                    {{ $title }}
                                </h3>
                                <span class="text-[10px] text-primary whitespace-nowrap font-medium">{{ $time }}</span>
                            </div>
                            <p class="text-xs text-on-surface-variant line-clamp-2 leading-relaxed">
                                {{ $content }}
                            </p>

                            {{-- CRUD Actions for single notification --}}
                            <div class="flex items-center gap-3 mt-2 text-[11px]">
                                @if($isUnread && $notifId)
                                    <form action="{{ route('portal.student.notifications.read-single', $notifId) }}" method="POST" class="inline">
                                        @csrf
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="drafts" class="text-primary">
                                            Đánh dấu đã đọc
                                        </x-ui.button>
                                    </form>
                                @endif
                                @if($notifId)
                                    <form action="{{ route('portal.student.notifications.destroy', $notifId) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa thông báo này?');">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete">
                                            Xóa
                                        </x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="notifications_off" title="Chưa có thông báo nào." />
                @endforelse
            </div>

            <div class="py-6 text-center text-xs text-on-surface-variant/70 flex items-center justify-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">done_all</span>
                <span>Đã tải hết thông báo gần đây</span>
            </div>
        </main>

        {{-- Bottom Navigation Bar Component --}}
        @include('portal.partials.bottom-nav', ['activeTab' => 'notifications', 'student' => $student])
    </div>
</x-app-layout>
