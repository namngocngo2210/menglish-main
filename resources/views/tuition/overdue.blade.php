{{-- Danh sách học viên đến hạn / quá hạn thu phí (mockup: epic-8-thu-phi-qua-han). --}}
<x-app-layout title="Thu phí quá hạn">
    <x-ui.page-header title="Danh sách học viên đến hạn thu phí" description="Theo dõi và đôn đốc công nợ học phí: quá hạn nghiêm trọng, mới quá hạn và sắp đến hạn.">
        <x-slot:actions>
            @can('fee_reminder_config.manage')
                <x-ui.button variant="secondary" icon="settings" :href="route('system-config.debt-reminders')">Cấu hình nhắc nợ</x-ui.button>
            @endcan
            @can('tuition.create')
                <x-ui.button icon="add_card" :href="route('tuition.receipts.create')">Lập phiếu thu mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('tuition.overdue')" placeholder="Họ tên, SĐT hoặc mã học viên...">
        <x-ui.select name="branch_id" :options="$branches->pluck('name', 'id')" placeholder="Tất cả chi nhánh" inline-label="Chi nhánh:" />
        <x-ui.select name="class_id" :options="$classes->mapWithKeys(fn ($c) => [$c->id => $c->name.' ('.$c->code.')'])" placeholder="Tất cả lớp học" inline-label="Lớp:" />
        <x-ui.select name="type" :options="['all' => 'Quá hạn & sắp đến hạn', 'overdue' => 'Chỉ quá hạn', 'upcoming' => 'Chỉ sắp đến hạn']" inline-label="Nhóm:" />
    </x-ui.filter-bar>

    @php
        $sessionLines = function ($t) {
            $s = $t->session_stats;
            return $s ? [
                'Số buổi còn tồn' => $t->frozen_remaining_sessions ?? $s['remaining'],
                'Số buổi đã học' => $s['attended'],
                'Tổng số buổi' => $s['total'],
            ] : [];
        };
    @endphp

    <div class="space-y-xl">
        {{-- NHÓM QUÁ HẠN --}}
        @if ($type !== 'upcoming')
            <section class="space-y-md">
                <div class="flex items-center gap-sm border-l-4 border-error pl-sm">
                    <h2 class="font-h2 text-h2 uppercase text-on-surface">Nhóm "Quá hạn"</h2>
                    <x-ui.badge color="error" pill :dot="false">{{ $overdueTuitions->count() }} trường hợp</x-ui.badge>
                </div>

                @foreach ([
                    ['rows' => $seriousOverdue, 'title' => 'Quá hạn nghiêm trọng (≥ '.$seriousDays.' ngày)', 'icon' => 'report', 'tone' => 'text-error', 'serious' => true],
                    ['rows' => $newOverdue, 'title' => 'Mới quá hạn (1–'.max(1, $seriousDays - 1).' ngày)', 'icon' => 'info', 'tone' => 'text-amber-700', 'serious' => false],
                ] as $group)
                    <div class="space-y-sm">
                        <h3 class="flex items-center gap-xs font-label text-label uppercase {{ $group['tone'] }}">
                            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $group['icon'] }}</span>{{ $group['title'] }}
                            <span class="text-on-surface-variant">· {{ $group['rows']->count() }}</span>
                        </h3>

                        @forelse ($group['rows'] as $ot)
                            <article @class([
                                'grid grid-cols-1 overflow-hidden rounded-xl border bg-surface-container-lowest lg:grid-cols-[260px_1fr_260px]',
                                'border-error/40' => $group['serious'],
                                'border-outline-variant' => ! $group['serious'],
                            ])>
                                <div class="space-y-sm bg-surface-container-low p-md">
                                    <div class="flex items-center gap-sm">
                                        <x-ui.avatar :name="$ot->student?->name" />
                                        <div class="min-w-0">
                                            <p class="truncate font-body-medium text-body-medium text-on-surface">{{ $ot->student?->name }}</p>
                                            <p class="font-caption text-caption text-on-surface-variant">MS: {{ $ot->student?->code }} · {{ $ot->student?->phone }}</p>
                                        </div>
                                    </div>
                                    @forelse ($sessionLines($ot) as $label => $value)
                                        <div class="flex justify-between font-body-small text-body-small"><span class="text-on-surface-variant">{{ $label }}:</span><span class="font-code">{{ $value }}</span></div>
                                    @empty
                                        <p class="font-caption text-caption text-on-surface-variant">Chưa có dữ liệu số buổi.</p>
                                    @endforelse
                                </div>

                                <div class="grid grid-cols-2 gap-md p-md md:grid-cols-4">
                                    <div>
                                        <p class="font-label text-label uppercase text-on-surface-variant">Lớp học</p>
                                        <p class="font-body-medium text-body-medium">{{ $ot->classModel?->code ?? $ot->classModel?->name ?? '—' }}</p>
                                        <p class="font-caption text-caption text-on-surface-variant">{{ $ot->branch?->name }}</p>
                                    </div>
                                    <div>
                                        <p class="font-label text-label uppercase text-on-surface-variant">Còn nợ</p>
                                        <x-ui.money :value="$ot->debt_amount" align="left" class="text-error" />
                                    </div>
                                    <div>
                                        <p class="font-label text-label uppercase text-on-surface-variant">Hạn thanh toán</p>
                                        <p class="font-code text-code text-error">{{ $ot->due_date?->format('d/m/Y') }}</p>
                                    </div>
                                    <div class="space-y-xs">
                                        <p class="font-label text-label uppercase text-on-surface-variant">Trạng thái</p>
                                        @if ($ot->last_contact)
                                            <x-ui.badge color="info">Đã liên hệ — chờ thu</x-ui.badge>
                                            <p class="font-caption text-caption text-on-surface-variant" title="{{ $ot->last_contact->note }}">
                                                {{ $ot->last_contact->contacted_at->format('d/m H:i') }} · {{ $ot->last_contact->user?->name }}
                                                @if ($ot->last_contact->note) — {{ \Illuminate\Support\Str::limit($ot->last_contact->note, 40) }} @endif
                                            </p>
                                        @endif
                                        <x-ui.badge :color="$group['serious'] ? 'error' : 'warning'">Quá hạn {{ $ot->days_overdue }} ngày</x-ui.badge>
                                    </div>
                                </div>

                                <div class="flex flex-col justify-center gap-sm bg-surface-container-low p-md" x-data="{ contact: false, report: false }">
                                    @can('tuition.create')
                                        <x-ui.button size="sm" icon="payments" :href="route('tuition.receipts.create', ['tuition_id' => $ot->id])">Lập phiếu thu</x-ui.button>
                                    @endcan
                                    @can('tuition.mark_contacted')
                                        <x-ui.button size="sm" variant="secondary" icon="call" @click="contact = ! contact">Xác nhận đã liên hệ</x-ui.button>
                                        <form x-show="contact" x-cloak method="POST" action="{{ route('tuition.overdue.contacted', $ot->id) }}" class="space-y-xs">
                                            @csrf
                                            <input type="datetime-local" name="contacted_at" value="{{ now()->format('Y-m-d\TH:i') }}" max="{{ now()->format('Y-m-d\TH:i') }}" class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small">
                                            <textarea name="note" rows="2" placeholder="Nội dung trao đổi, hẹn ngày đóng..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                                            <x-ui.button type="submit" size="sm" icon="check" class="w-full">Lưu liên hệ</x-ui.button>
                                        </form>
                                    @endcan
                                    @if ($ot->last_report)
                                        <p class="flex items-center gap-xs rounded-lg bg-error-container/60 px-sm py-xs font-body-small text-body-small italic text-on-error-container">
                                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">check_circle</span>
                                            Đã báo cáo Admin — {{ $ot->last_report->contacted_at->format('d/m/Y') }}
                                        </p>
                                    @else
                                        @can('tuition.report_overdue')
                                            <x-ui.button size="sm" variant="danger-text" icon="report" @click="report = ! report">Báo cáo Admin</x-ui.button>
                                            <form x-show="report" x-cloak method="POST" action="{{ route('tuition.overdue.report-admin', $ot->id) }}" class="space-y-xs">
                                                @csrf
                                                <textarea name="note" rows="2" placeholder="Tình hình liên hệ, đề xuất xử lý..." class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small"></textarea>
                                                <x-ui.button type="submit" size="sm" variant="danger" icon="send" class="w-full">Gửi báo cáo</x-ui.button>
                                            </form>
                                        @endcan
                                    @endif
                                    @can('tuition.mark_contacted')
                                        <form method="POST" action="{{ route('tuition.overdue.remind', $ot->id) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="ghost" icon="notifications_active" class="w-full">Gửi nhắc nợ</x-ui.button>
                                        </form>
                                    @endcan
                                </div>
                            </article>
                        @empty
                            <p class="rounded-xl border border-dashed border-outline-variant p-md text-center font-body-small text-body-small text-on-surface-variant">Không có học viên trong nhóm này.</p>
                        @endforelse
                    </div>
                @endforeach
            </section>
        @endif

        {{-- NHÓM SẮP ĐẾN HẠN --}}
        @if ($type !== 'overdue')
            <section class="space-y-md">
                <div class="flex items-center gap-sm border-l-4 border-secondary pl-sm">
                    <h2 class="font-h2 text-h2 uppercase text-on-surface">Nhóm "Sắp đến hạn"</h2>
                    <span class="font-body-medium text-on-surface-variant">(Trong {{ $upcomingDays }} ngày tới)</span>
                    <span class="ml-auto font-caption text-caption text-on-surface-variant">Hiển thị {{ $upcoming->count() }} kết quả</span>
                </div>
                <x-ui.data-table min-width="860px">
                    <table>
                        <thead>
                            <tr>
                                <th>Học sinh</th>
                                <th>Lớp</th>
                                <th class="text-right">Còn nợ</th>
                                <th>Hạn thanh toán</th>
                                <th>Chi tiết buổi học</th>
                                <th>Số ngày còn lại</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($upcoming as $ot)
                                <tr>
                                    <td>
                                        <div class="font-body-medium">{{ $ot->student?->name }}</div>
                                        <div class="font-caption text-caption text-on-surface-variant">MS: {{ $ot->student?->code }}</div>
                                    </td>
                                    <td>{{ $ot->classModel?->code ?? $ot->classModel?->name ?? '—' }}</td>
                                    <td><x-ui.money :value="$ot->debt_amount" /></td>
                                    <td class="font-code text-code">{{ $ot->due_date?->format('d/m/Y') }}</td>
                                    <td class="font-caption text-caption">
                                        @forelse ($sessionLines($ot) as $label => $value)
                                            <div>{{ $label }}: {{ $value }}</div>
                                        @empty
                                            <span class="text-on-surface-variant">—</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @if ($ot->last_contact)
                                            <x-ui.badge color="info">Đã liên hệ — chờ thu</x-ui.badge>
                                        @elseif ($ot->days_overdue === 0)
                                            <x-ui.badge color="warning">Đến hạn hôm nay</x-ui.badge>
                                        @else
                                            <x-ui.badge color="success">{{ abs($ot->days_overdue) }} ngày</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        @can('tuition.create')
                                            <x-ui.button size="sm" variant="ghost" icon="receipt_long" :href="route('tuition.receipts.create', ['tuition_id' => $ot->id])">Lập phiếu thu</x-ui.button>
                                        @endcan
                                        @can('tuition.mark_contacted')
                                            <form method="POST" action="{{ route('tuition.overdue.upcoming-remind', $ot->id) }}" class="inline">
                                                @csrf
                                                <x-ui.button type="submit" size="sm" variant="ghost" icon="notifications_active" title="Gửi nhắc hạn học phí" aria-label="Gửi nhắc hạn học phí" />
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><x-ui.empty-state icon="event_available" title="Không có khoản sắp đến hạn" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.data-table>
            </section>
        @endif

        {{-- ĐANG KHẤT NỢ / BẢO LƯU --}}
        @if ($paused->isNotEmpty())
            <section class="space-y-md">
                <div class="flex items-center gap-sm border-l-4 border-outline pl-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Đang khất nợ / bảo lưu (tạm dừng nhắc nợ)</h2>
                </div>
                <x-ui.data-table min-width="720px">
                    <table>
                        <thead><tr><th>Học sinh</th><th>Lớp</th><th class="text-right">Còn nợ</th><th>Hạn đóng</th><th>Tạm dừng nhắc tới</th><th>Ghi chú</th></tr></thead>
                        <tbody>
                            @foreach ($paused as $ot)
                                <tr>
                                    <td>{{ $ot->student?->name }} <span class="font-caption text-caption text-on-surface-variant">({{ $ot->student?->code }})</span></td>
                                    <td>{{ $ot->classModel?->name ?? '—' }}</td>
                                    <td><x-ui.money :value="$ot->debt_amount" /></td>
                                    <td class="font-code text-code">{{ $ot->due_date?->format('d/m/Y') }}</td>
                                    <td class="font-code text-code">{{ $ot->reminder_paused_until?->format('d/m/Y') }}</td>
                                    <td>
                                        @if ($ot->deferred_until)
                                            <x-ui.badge color="info">Bảo lưu tới {{ $ot->deferred_until->format('d/m/Y') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge color="warning">Khất nợ</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
            </section>
        @endif

        {{-- Thống kê --}}
        <section class="grid grid-cols-1 gap-lg lg:grid-cols-2">
            <x-ui.data-table>
                <x-slot:header><h3 class="font-h3 text-h3">Công nợ theo chi nhánh</h3></x-slot:header>
                <table>
                    <thead><tr><th>Chi nhánh</th><th class="text-center">Đang nợ</th><th class="text-center">Quá hạn</th><th class="text-right">Tổng nợ</th></tr></thead>
                    <tbody>
                        @forelse ($statsByBranch as $sb)
                            <tr><td>{{ $sb['branch_name'] }}</td><td class="text-center font-code">{{ $sb['count'] }}</td><td class="text-center font-code text-error">{{ $sb['overdue_count'] }}</td><td><x-ui.money :value="$sb['total_debt']" /></td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-on-surface-variant">Chưa có công nợ.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.data-table>
            <x-ui.data-table>
                <x-slot:header><h3 class="font-h3 text-h3">Công nợ theo lớp</h3></x-slot:header>
                <table>
                    <thead><tr><th>Lớp</th><th class="text-center">Đang nợ</th><th class="text-center">Quá hạn</th><th class="text-right">Tổng nợ</th></tr></thead>
                    <tbody>
                        @forelse ($statsByClass as $sc)
                            <tr><td>{{ $sc['class_name'] }} <span class="font-caption text-caption text-on-surface-variant">{{ $sc['class_code'] }}</span></td><td class="text-center font-code">{{ $sc['count'] }}</td><td class="text-center font-code text-error">{{ $sc['overdue_count'] }}</td><td><x-ui.money :value="$sc['total_debt']" /></td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-on-surface-variant">Chưa có công nợ.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.data-table>
        </section>
    </div>
</x-app-layout>
