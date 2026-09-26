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

    <div class="space-y-xl">
        @include('tuition.partials.due-groups')

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
