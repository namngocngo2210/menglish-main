<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-amber-500">notifications</span>
                        Flow 4 — Bước 5: Danh sách thông báo
                    </h1>
                    <p class="text-xs text-gray-500">Hộp thư thông báo cập nhật học phí, sinh nhật, khảo sát chất lượng và lịch nghỉ của trung tâm.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.student.home', ['studentId' => $student?->id]) }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                    <span class="material-symbols-outlined text-[18px]">cottage</span>
                    <span>Về Trang chủ</span>
                </a>
            </div>
        </div>
    </x-slot>

    

    <!-- Mobile Frame for Notifications (Matches 04_Cong_Phu_Huynh_Hoc_Sinh/05_danh_sach_thong_bao) -->
    <div class="max-w-[430px] mx-auto bg-white min-h-[844px] shadow-2xl rounded-3xl border border-gray-200 overflow-hidden flex flex-col relative pb-24 my-4"
         x-data="{ unreadOnly: false }">

        <!-- Header Partial -->
        @include('portal.partials.top-header', [
            'student' => $student,
            'students' => $students,
            'title' => 'Thông báo',
            'showBack' => true,
            'backUrl' => route('portal.student.home', ['studentId' => $student?->id])
        ])

        <!-- Contextual Subheader -->
        <div class="px-4 py-3 bg-gray-50/80 border-b border-gray-200 flex items-center justify-between">
            <span class="text-xs font-bold text-gray-800">Tất cả thông báo</span>
            <form action="{{ route('portal.student.notifications.read') }}" method="POST">
                @csrf
                <button type="submit" class="text-primary text-xs font-semibold hover:underline">
                    Đánh dấu tất cả đã đọc
                </button>
            </form>
        </div>

        <!-- Main Notification List -->
        <main class="flex-1 overflow-y-auto">
            <div class="divide-y divide-gray-100">
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
                        $bgColor = $data['bg_color'] ?? 'bg-orange-100';
                        $textColor = $data['text_color'] ?? 'text-primary';
                    @endphp
                    <div class="flex items-start px-4 py-3.5 hover:bg-gray-50 transition-colors relative group {{ !$isUnread ? 'opacity-80' : 'bg-orange-50/20' }}">
                        @if($isUnread)
                            <!-- Unread Indicator Dot -->
                            <div class="absolute left-2 top-1/2 -translate-y-1/2 w-2 h-2 bg-primary-container rounded-full shadow-xs"></div>
                        @endif

                        <div class="ml-2 mr-3 shrink-0 w-10 h-10 rounded-full {{ $bgColor }} flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px] {{ $textColor }}" style="font-variation-settings: 'FILL' 1;">
                                {{ $icon }}
                            </span>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-0.5">
                                <h3 class="text-xs font-bold text-gray-900 pr-2 truncate {{ $isUnread ? 'font-bold text-gray-900' : 'font-medium text-gray-700' }}">
                                    {{ $title }}
                                </h3>
                                <span class="text-[10px] text-primary whitespace-nowrap font-medium">{{ $time }}</span>
                            </div>
                            <p class="text-xs text-gray-600 line-clamp-2 leading-relaxed">
                                {{ $content }}
                            </p>

                            <!-- CRUD Actions for single notification -->
                            <div class="flex items-center gap-3 mt-2 text-[11px]">
                                @if($isUnread && $notifId)
                                    <form action="{{ route('portal.student.notifications.read-single', $notifId) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-primary font-semibold hover:underline flex items-center gap-0.5">
                                            <span class="material-symbols-outlined text-[13px]">drafts</span>
                                            Đánh dấu đã đọc
                                        </button>
                                    </form>
                                @endif
                                @if($notifId)
                                    <form action="{{ route('portal.student.notifications.destroy', $notifId) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa thông báo này?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-gray-400 hover:text-rose-600 transition flex items-center gap-0.5">
                                            <span class="material-symbols-outlined text-[13px]">delete</span>
                                            Xóa
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-xs text-gray-500">
                        <span class="material-symbols-outlined text-gray-300 text-[36px] block mb-1">notifications_off</span>
                        Chưa có thông báo nào.
                    </div>
                @endforelse
            </div>

            <div class="py-6 text-center text-xs text-gray-400 flex items-center justify-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">done_all</span>
                <span>Đã tải hết thông báo gần đây</span>
            </div>
        </main>

        <!-- Bottom Navigation Bar Component -->
        @include('portal.partials.bottom-nav', ['activeTab' => 'notifications', 'student' => $student])
    </div>
</x-app-layout>
