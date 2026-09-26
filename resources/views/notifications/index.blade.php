<x-app-layout>
    @include('crm.partials.header-tabs')

    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <h2 class="text-lg font-bold text-on-surface tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-error">notifications_active</span>
                Trung Tâm Cảnh Báo &amp; Thông Báo Quản Trị
            </h2>
            <div class="flex items-center gap-2">
                <form action="{{ route('notifications.scan') }}" method="POST" class="inline">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="sync">Quét lại hệ thống</x-ui.button>
                </form>
                <form action="{{ route('notifications.read-all') }}" method="POST" class="inline">
                    @csrf
                    <x-ui.button type="submit" size="sm" icon="done_all">Đánh dấu tất cả đã đọc</x-ui.button>
                </form>
            </div>
        </div>

        {{-- Stats Widgets --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <x-ui.stat-card label="Tổng thông báo" :value="$stats['total']" icon="notifications" />
            <x-ui.stat-card label="Lead bị sót >24h (Chưa xử lý)" :value="$stats['stale_leads']" tone="error" icon="person_alert" />
            <x-ui.stat-card label="Thông báo chưa đọc" :value="$stats['unread']" tone="warning" icon="mark_email_unread" />
        </div>

        {{-- Filter Bar --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('notifications.index') }}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ !request('type') && !request('unread') ? 'bg-primary-container text-white' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' }}">
                    Tất cả
                </a>
                <a href="{{ route('notifications.index', ['type' => 'stale_lead_24h']) }}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ request('type') === 'stale_lead_24h' ? 'bg-error text-white' : 'bg-error/10 text-error hover:bg-error/20 border border-error/30' }}">
                    ⚠️ Lead tồn đọng >24h ({{ $stats['stale_leads'] }})
                </a>
                <a href="{{ route('notifications.index', ['unread' => 1]) }}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ request('unread') ? 'bg-warning text-white' : 'bg-warning-container text-on-warning-container hover:bg-warning/20 border border-warning/30' }}">
                    Chưa đọc ({{ $stats['unread'] }})
                </a>
            </div>
        </div>

        {{-- Notifications Stream --}}
        <div class="space-y-3">
            @forelse ($notifications as $notif)
                <div class="bg-surface-container-lowest rounded-2xl border {{ !$notif->is_read ? 'border-error/30 bg-error/5 shadow-sm' : 'border-surface-container-highest opacity-80' }} p-5 transition hover:shadow-md">
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
                                    <h3 class="font-bold text-on-surface text-sm">{{ $notif->title }}</h3>
                                    @if (!$notif->is_read)
                                        <span class="w-2 h-2 rounded-full bg-error inline-block animate-pulse"></span>
                                    @endif
                                </div>

                                <p class="text-xs text-on-surface-variant leading-relaxed">{{ $notif->message }}</p>

                                @if ($notif->data && isset($notif->data['customer_id']))
                                    <div class="flex items-center gap-4 text-xs text-on-surface-variant pt-1 font-medium flex-wrap">
                                        <span>Khách hàng: <strong class="text-on-surface">{{ $notif->data['customer_name'] }}</strong></span>
                                        <span>SĐT: <strong class="font-mono text-on-surface">{{ $notif->data['customer_phone'] }}</strong></span>
                                        <span>Sales: <strong class="text-secondary">{{ $notif->data['assigned_user'] }}</strong></span>
                                        <span>Thời gian trễ: <strong class="text-error font-mono font-bold">{{ $notif->data['hours_elapsed'] }}h</strong></span>
                                    </div>
                                @endif

                                <div class="text-[10px] text-on-surface-variant/70 font-mono pt-1">
                                    Ghi nhận lúc: {{ $notif->created_at->format('d/m/Y H:i') }} ({{ $notif->created_at->diffForHumans() }})
                                </div>
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="flex items-center gap-2 shrink-0 self-end sm:self-center">
                            @if ($notif->data && isset($notif->data['customer_id']))
                                <x-ui.button variant="info" size="sm" icon="call" :href="route('crm.customers.show', $notif->data['customer_id'])">Xử lý Lead ngay</x-ui.button>
                            @endif

                            @if (!$notif->is_read)
                                <form action="{{ route('notifications.read', $notif->id) }}" method="POST" class="inline">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary" size="sm" icon="check" title="Đánh dấu đã đọc" aria-label="Đánh dấu đã đọc" />
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest">
                    <x-ui.empty-state icon="task_alt" title="Hệ thống đang hoạt động tối ưu!" description="Không có Lead nào bị sót quá 24h và không có thông báo cảnh báo chưa xử lý." />
                </div>
            @endforelse
        </div>

        <div class="rounded-2xl overflow-hidden border border-outline-variant bg-surface-container-low shadow-sm">
            <x-ui.pagination :paginator="$notifications" />
        </div>
    </div>
</x-app-layout>
