{{-- Bảng KPI tự động — chỉ số tính từ dữ liệu thật trong tháng được chọn (KpiBoardService). --}}
@php
    $fmt = fn (?float $value) => $value === null ? null : number_format($value, 1, ',', '.').'%';
@endphp
<x-app-layout title="Bảng KPI tự động">
    <x-ui.page-header title="Bảng KPI tự động" description="Theo dõi các chỉ số hiệu suất chính của nhân sự giảng dạy, tính tự động từ điểm danh, bài tập và công việc.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download" :href="request()->fullUrlWithQuery(['export' => 1, 'page' => null])">Xuất Excel KPI</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :search="false" :action="route('tasks.kpi-dashboard')">
        @if ($canSeeStaff)
            <x-ui.select name="user_id" label="Chọn nhân sự" :options="$staffOptions->mapWithKeys(fn ($u) => [$u->id => $u->name.($u->employee_code ? ' ('.$u->employee_code.')' : '')])" placeholder="Tất cả nhân sự" />
        @endif
        <x-ui.date-range label="Kỳ báo cáo" type="month" from="month" to="month_to" :from-value="$month" :to-value="$monthTo" />
        <span class="self-center font-body-small text-body-small text-on-surface-variant sm:col-span-2">Tháng {{ $from->format('m/Y') }}{{ $monthTo !== $month ? ' - Tháng '.$to->format('m/Y') : '' }} ({{ $from->format('d/m/Y') }} - {{ $to->format('d/m/Y') }})</span>
    </x-ui.filter-bar>
    @unless ($canSeeStaff)
        <x-ui.alert type="info" class="mb-md">Bạn đang xem KPI của chính mình. KPI toàn bộ nhân sự chỉ dành cho người có quyền duyệt công việc.</x-ui.alert>
    @endunless

    <x-ui.data-table min-width="860px">
        <table>
            <thead>
                <tr>
                    <th>Nhân sự</th>
                    <th class="text-center">Lớp phụ trách</th>
                    <th class="text-right">Tỷ lệ giữ chân học viên</th>
                    <th class="text-right">Tỷ lệ chuyên cần</th>
                    <th class="text-right">Tỷ lệ hoàn thành bài tập</th>
                    <th class="text-right">Hoàn thành công việc</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($kpiData as $row)
                    <tr data-user-id="{{ $row['user']->id }}">
                        <td>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$row['user']->name" />
                                <div>
                                    <div class="font-semibold text-on-surface">{{ $row['user']->name }}</div>
                                    <div class="font-code text-caption text-on-surface-variant">{{ $row['code'] }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="text-center font-code">{{ $row['classes'] }}</td>
                        @foreach ([['retention', 80], ['attendance', 85], ['homework', 70], ['tasks', 80]] as [$key, $threshold])
                            <td class="text-right">
                                @if ($row[$key] === null)
                                    <span class="font-body-small text-body-small italic text-on-surface-variant" title="Không có dữ liệu trong kỳ">Chưa có dữ liệu</span>
                                @else
                                    <span class="font-code font-semibold {{ $row[$key] < $threshold ? 'text-error' : 'text-on-surface' }}">{{ $fmt($row[$key]) }}</span>
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ $row[$key.'_detail'] }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty-state icon="insights" title="Chưa có nhân sự để tính KPI"
                                description="Bảng KPI theo dõi giáo viên, trợ giảng và học vụ đang hoạt động." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            <x-ui.pagination :paginator="$staff" unit="nhân sự" />
            <p class="px-md pb-md font-caption text-caption text-on-surface-variant">
                Chuyên cần = lượt có mặt/đi muộn ÷ lượt điểm danh trong kỳ (lớp phụ trách). Bài tập = bài học viên nộp ÷ (bài giao có hạn trong kỳ × sĩ số).
                Công việc = việc có hạn trong kỳ đã hoàn thành. Giữ chân = học viên chưa thôi học ÷ học viên đã vào lớp.
            </p>
        </x-slot:footer>
    </x-ui.data-table>
</x-app-layout>
