{{-- Mockup: ui-full-tinh-nang-menglish/epic-7/lich-su-dong-bo-cham-cong --}}
<x-app-layout>
    @php
        $statusStyles = [
            'success' => ['success', 'check_circle', 'text-tertiary'],
            'partial' => ['warning', 'warning', 'text-warning'],
            'failed' => ['error', 'error', 'text-error'],
        ];
    @endphp

    <x-ui.page-header title="Lịch sử đồng bộ chấm công"
                      description="Theo dõi các đợt đồng bộ dữ liệu chấm công tự động (AppSheet / máy chấm công) vào hệ thống.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="refresh" :href="request()->fullUrl()">Làm mới</x-ui.button>
            <span title="Chưa kết nối AppSheet / máy chấm công — chưa thể đồng bộ">
                <x-ui.button icon="sync" disabled aria-disabled="true">Đồng bộ ngay</x-ui.button>
            </span>
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($hasAnyLog)
        <x-ui.alert type="info" title="Chưa kết nối nguồn đồng bộ" class="mb-lg">
            Hệ thống hiện <strong>chưa tích hợp</strong> AppSheet hay thiết bị vân tay / FaceID nào, nên chưa có đợt đồng bộ nào và nút
            "Đồng bộ ngay" đang khóa. Chấm công giáo viên đang được ghi nhận qua <strong>check-in theo buổi học</strong> trên cổng giáo viên
            và <strong>chấm công thủ công</strong> của Học vụ (xem tại
            <a href="{{ route('payroll.timesheets.teachers') }}" class="font-semibold underline">Chi tiết chấm công giáo viên</a>).
        </x-ui.alert>
    @endunless

    <x-ui.filter-bar :search="false" submit-label="Lọc dữ liệu">
        <x-ui.date-range label="Khoảng ngày" />
        <x-ui.select name="status" label="Trạng thái" :options="\App\Models\TimesheetSyncLog::STATUS_LABELS" placeholder="Tất cả trạng thái" />
    </x-ui.filter-bar>

    <x-ui.data-table min-width="900px">
        <table>
            <thead>
                <tr>
                    <th>Thời điểm chạy</th>
                    <th>Nguồn / Cơ sở</th>
                    <th class="text-center">Tổng số dòng</th>
                    <th class="text-center">Thành công</th>
                    <th class="text-center">Lỗi</th>
                    <th class="text-center">
                        <span class="inline-flex items-center gap-xs">Bỏ qua
                            <span class="material-symbols-outlined cursor-help text-[16px]" title="Hệ thống bỏ qua không ghi đè dữ liệu của các nhân sự đã chốt kỳ lương.">info</span>
                        </span>
                    </th>
                    <th>Trạng thái</th>
                    <th class="text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($syncLogs as $log)
                    @php [$color, $icon, $tone] = $statusStyles[$log->normalized_status] ?? ['neutral', 'help', 'text-on-surface-variant']; @endphp
                    <tr>
                        <td class="font-mono">{{ $log->created_at->format('H:i d/m/Y') }}</td>
                        <td>
                            <span class="block font-semibold">{{ $log->device_name }}</span>
                            <span class="block font-body-small text-body-small text-on-surface-variant">{{ $log->branch?->name ?? 'Toàn hệ thống' }}</span>
                        </td>
                        <td class="text-center font-mono">{{ number_format($log->records_count, 0, ',', '.') }}</td>
                        <td class="text-center font-mono text-tertiary">{{ number_format($log->matched_count, 0, ',', '.') }}</td>
                        <td class="text-center font-mono {{ $log->failed_count ? 'text-error font-semibold' : '' }}">{{ number_format($log->failed_count, 0, ',', '.') }}</td>
                        <td class="text-center font-mono">{{ number_format($log->skipped_count, 0, ',', '.') }}</td>
                        <td>
                            <span class="inline-flex items-center gap-xs font-body-medium text-body-medium {{ $tone }}">
                                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $icon }}</span>{{ $log->status_label }}
                            </span>
                            @if ($log->normalized_status === 'partial' && $log->failed_count)
                                <span class="block font-body-small text-body-small text-on-surface-variant">{{ $log->failed_count }} dòng lỗi</span>
                            @elseif ($log->normalized_status === 'failed' && $log->error_code)
                                <span class="block font-body-small text-body-small text-on-surface-variant">{{ $log->error_code }}</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($log->hasErrorDetails())
                                <x-ui.button variant="ghost" size="sm" icon="visibility" x-on:click="$dispatch('open-modal', 'sync-errors-{{ $log->id }}')">Xem chi tiết lỗi</x-ui.button>
                            @else
                                <span class="font-body-small text-body-small text-on-surface-variant">Không có lỗi</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-ui.empty-state icon="history" :title="$hasAnyLog ? 'Không có đợt đồng bộ khớp bộ lọc' : 'Chưa có lịch sử đồng bộ'"
                                              :description="$hasAnyLog ? 'Thử đổi khoảng ngày hoặc trạng thái.' : 'Màn này sẽ hiển thị các đợt đồng bộ thật khi có tích hợp AppSheet / máy chấm công.'" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer><x-ui.pagination :paginator="$syncLogs" unit="đợt đồng bộ" /></x-slot:footer>
    </x-ui.data-table>

    {{-- Chi tiết lỗi từng đợt đồng bộ: mở trong modal thay vì bung dòng ngay dưới bảng. --}}
    @foreach ($syncLogs as $log)
        @continue(! $log->hasErrorDetails())
        <x-ui.modal :name="'sync-errors-'.$log->id" :title="'Chi tiết lỗi đồng bộ lúc '.$log->created_at->format('H:i d/m/Y')" max-width="3xl">
            @if (! empty($log->error_rows))
                <p class="mb-sm flex items-center gap-xs font-body-semibold text-body-semibold text-warning">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">warning</span>{{ count($log->error_rows) }} dòng lỗi · {{ $log->device_name }}
                </p>
                <x-ui.data-table min-width="560px">
                    <table>
                        <thead><tr><th>Mã NV</th><th>Tên nhân viên</th><th>Mã lỗi</th><th>Nội dung chi tiết</th></tr></thead>
                        <tbody>
                            @foreach ($log->error_rows as $row)
                                <tr>
                                    <td class="font-mono">{{ $row['employee_code'] ?? '—' }}</td>
                                    <td>{{ $row['employee_name'] ?? '—' }}</td>
                                    <td><x-ui.badge color="error">{{ $row['code'] ?? 'ERROR' }}</x-ui.badge></td>
                                    <td>{{ $row['message'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.data-table>
                <x-slot:footer>
                    <x-ui.button variant="secondary" icon="download" :href="route('payroll.timesheets.sync-history.errors', $log->id)">Xuất file Excel lỗi</x-ui.button>
                </x-slot:footer>
            @else
                <x-ui.alert type="error">
                    <p class="font-semibold">{{ $log->error_code ?: 'Lỗi hệ thống' }}</p>
                    <p class="mt-1">{{ $log->error_message }}</p>
                </x-ui.alert>
            @endif
        </x-ui.modal>
    @endforeach
</x-app-layout>
