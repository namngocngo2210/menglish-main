{{-- Mockup: roundcuoi 02_Quan_Ly_Hoc_Thuat_Va_Hoc_Vu/03_tong_hop_kpi_danh_gia_thang (danh sách nhân sự của kỳ) --}}
<x-app-layout>
    @php
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $periodValue = sprintf('%04d-%02d', $year, $month);
        $periodOptions = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => 'Tháng '.now()->startOfMonth()->subMonths($i)->format('m/Y')])
            ->put($periodValue, 'Tháng '.sprintf('%02d/%04d', $month, $year))->sortKeysDesc();
        $roleOptions = collect(['academic_staff', 'academic_lead', 'teacher', 'teacher_fulltime', 'teacher_parttime', 'assistant'])
            ->mapWithKeys(fn ($r) => [$r => \App\Helpers\AclHelper::roleLabel($r)]);
    @endphp

    <x-ui.page-header :title="'Tổng hợp KPI & Đánh giá tháng '.sprintf('%02d/%04d', $month, $year)"
                      description="Đánh giá hiệu suất nhân sự theo bộ chỉ số KPI (Học vụ: 6 nhóm / 15 mục, quỹ KPI tính lương tự động).">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="tune" :href="route('kpi.criteria')">Cấu hình chỉ số</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar placeholder="Tìm nhân sự (tên, mã NV)...">
        <x-ui.select name="period" :options="$periodOptions" :value="$periodValue" aria-label="Kỳ đánh giá" />
        <x-ui.select name="role" :options="$roleOptions" placeholder="Mọi vai trò" aria-label="Vai trò" />
        <x-ui.button type="submit" variant="secondary" icon="filter_list">Xem</x-ui.button>
    </x-ui.filter-bar>

    <x-ui.data-table min-width="860px">
        <table>
            <thead>
                <tr>
                    <th>Nhân sự</th>
                    <th>Vai trò</th>
                    <th class="text-right">Tổng KPI đạt</th>
                    <th class="text-center">Xếp loại</th>
                    <th class="text-right">Tiền KPI dự tính</th>
                    <th>Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($staff as $s)
                    @php
                        $eval = $evaluations->get($s->id);
                        $isHv = $s->hasRole('academic_staff');
                        [$grade, $gradeLabel] = $eval ? \App\Models\KpiEvaluation::gradeFor((float) $eval->total_score) : [null, null];
                    @endphp
                    <tr>
                        <td>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$s->name" size="sm" />
                                <div>
                                    <p class="font-semibold text-on-surface">{{ $s->name }}</p>
                                    <p class="font-caption text-caption text-on-surface-variant">{{ $s->employee_code ?: $s->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td>{{ \App\Helpers\AclHelper::roleLabel((string) $s->getRoleNames()->first()) }}</td>
                        <td class="text-right font-mono font-semibold {{ $eval ? ($eval->total_score >= 85 ? 'text-tertiary' : ($eval->total_score >= 70 ? 'text-amber-600' : 'text-error')) : 'text-on-surface-variant' }}">
                            {{ $eval ? $fmt($eval->total_score).'%' : '—' }}
                        </td>
                        <td class="text-center">
                            @if ($grade)
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary-fixed font-semibold text-primary" title="{{ $gradeLabel }}">{{ $grade }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-right font-mono">{{ $isHv && $eval ? number_format(round($fund * (float) $eval->total_score / 100), 0, ',', '.').'đ' : '—' }}</td>
                        <td>
                            @if (! $eval)
                                <x-ui.badge color="neutral">Chưa đánh giá</x-ui.badge>
                            @elseif ($eval->status === 'confirmed')
                                <x-ui.badge color="success">Đã chốt</x-ui.badge>
                            @else
                                <x-ui.badge color="warning">Bản nháp</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right">
                            <x-ui.button :variant="$eval ? 'ghost' : 'secondary'" size="sm" :icon="$eval ? 'visibility' : 'rate_review'"
                                         :href="route('kpi.evaluate', ['userId' => $s->id, 'month' => $month, 'year' => $year])">{{ $eval ? 'Xem / Sửa' : 'Đánh giá' }}</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="group_off" title="Chưa có nhân sự nào để đánh giá" /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$staff" unit="nhân sự" /></x-slot:footer>
    </x-ui.data-table>
</x-app-layout>
