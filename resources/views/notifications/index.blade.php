<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-rose-600">notifications_active</span>
                    Trung Tâm Cảnh Báo &amp; Thông Báo Quản Trị
                </h1>
                <p class="text-xs text-gray-500">Giám sát tự động các Lead bị sót quá 24h chưa chuyển trạng thái, phiếu thu chờ duyệt và sự cố</p>
            </div>
            <div class="flex items-center gap-2">
                <form action="{{ route('notifications.scan') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold transition">
                        <span class="material-symbols-outlined text-[18px]">sync</span>
                        <span>Quét lại hệ thống</span>
                    </button>
                </form>
                <form action="{{ route('notifications.read-all') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                        <span class="material-symbols-outlined text-[18px]">done_all</span>
                        <span>Đánh dấu tất cả đã đọc</span>
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <!-- Stats Widgets -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <div class="text-xs text-gray-500 font-medium">Tổng thông báo</div>
                    <div class="text-2xl font-extrabold text-gray-900 font-mono mt-1">{{ $stats['total'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-gray-50 text-gray-700 flex items-center justify-center">
                    <span class="material-symbols-outlined">notifications</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-rose-200 shadow-sm flex items-center justify-between bg-rose-50/20">
                <div>
                    <div class="text-xs text-rose-700 font-medium">Lead bị sót >24h (Chưa xử lý)</div>
                    <div class="text-2xl font-extrabold text-rose-900 font-mono mt-1">{{ $stats['stale_leads'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-800 flex items-center justify-center">
                    <span class="material-symbols-outlined">person_alert</span>
                </div>
            </div>

            <div class="bg-white p-4 rounded-2xl border border-amber-200 shadow-sm flex items-center justify-between bg-amber-50/20">
                <div>
                    <div class="text-xs text-amber-700 font-medium">Thông báo chưa đọc</div>
                    <div class="text-2xl font-extrabold text-amber-900 font-mono mt-1">{{ $stats['unread'] }}</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center">
                    <span class="material-symbols-outlined">mark_email_unread</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('notifications.index') }}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ !request('type') && !request('unread') ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Tất cả
                </a>
                <a href="{{ route('notifications.index', ['type' => 'stale_lead_24h']) }}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ request('type') === 'stale_lead_24h' ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200' }}">
                    ⚠️ Lead tồn đọng >24h ({{ $stats['stale_leads'] }})
                </a>
                <a href="{{ route('notifications.index', ['unread' => 1]) }}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ request('unread') ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-200' }}">
                    Chưa đọc ({{ $stats['unread'] }})
                </a>
            </div>
        </div>

        <!-- Notifications Stream -->
        <div class="space-y-3">
            @forelse ($notifications as $notif)
                <div class="bg-white rounded-2xl border {{ !$notif->is_read ? 'border-rose-200 bg-rose-50/10 shadow-sm' : 'border-gray-200 opacity-80' }} p-5 transition hover:shadow-md">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                        <div class="flex items-start gap-3.5 flex-1">
                            <div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 {{ $notif->badge_color }}">
                                <span class="material-symbols-outlined text-xl">{{ $notif->icon }}</span>
                            </div>
                            <div class="space-y-1.5 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="px-2.5 py-0.5 rounded-full border text-[10px] font-bold {{ $notif->badge_color }}">
                                        {{ $notif->type_label }}
                                    </span>
                                    <h3 class="font-bold text-gray-900 text-sm">{{ $notif->title }}</h3>
                                    @if (!$notif->is_read)
                                        <span class="w-2 h-2 rounded-full bg-rose-500 inline-block animate-pulse"></span>
                                    @endif
                                </div>

                                <p class="text-xs text-gray-700 leading-relaxed">{{ $notif->message }}</p>

                                @if ($notif->data && isset($notif->data['customer_id']))
                                    <div class="flex items-center gap-4 text-xs text-gray-500 pt-1 font-medium flex-wrap">
                                        <span>Khách hàng: <strong class="text-gray-900">{{ $notif->data['customer_name'] }}</strong></span>
                                        <span>SĐT: <strong class="font-mono text-gray-900">{{ $notif->data['customer_phone'] }}</strong></span>
                                        <span>Sales: <strong class="text-indigo-700">{{ $notif->data['assigned_user'] }}</strong></span>
                                        <span>Thời gian trễ: <strong class="text-rose-600 font-mono font-bold">{{ $notif->data['hours_elapsed'] }}h</strong></span>
                                    </div>
                                @endif

                                <div class="text-[10px] text-gray-400 font-mono pt-1">
                                    Ghi nhận lúc: {{ $notif->created_at->format('d/m/Y H:i') }} ({{ $notif->created_at->diffForHumans() }})
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-2 shrink-0 self-end sm:self-center">
                            @if ($notif->data && isset($notif->data['customer_id']))
                                <a href="{{ route('crm.customers.show', $notif->data['customer_id']) }}" class="px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-xs transition inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">call</span>
                                    <span>Xử lý Lead ngay</span>
                                </a>
                            @endif

                            @if (!$notif->is_read)
                                <form action="{{ route('notifications.read', $notif->id) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 rounded-xl border border-gray-200 hover:bg-gray-50 text-gray-600 text-xs font-semibold transition" title="Đánh dấu đã đọc">
                                        <span class="material-symbols-outlined text-[16px] align-middle">check</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center text-gray-400 text-xs space-y-2">
                    <span class="material-symbols-outlined text-4xl text-emerald-500 block mx-auto">task_alt</span>
                    <div class="font-bold text-gray-700 text-sm">Hệ thống đang hoạt động tối ưu!</div>
                    <div>Không có Lead nào bị sót quá 24h và không có thông báo cảnh báo chưa xử lý.</div>
                </div>
            @endforelse
        </div>

        <div class="pt-2 rounded-2xl overflow-hidden shadow-sm">
            <x-pagination :paginator="$notifications" />
        </div>
    </div>
</x-app-layout>
