<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">more_time</span>
                    Nhật ký Chấm công Giáo viên (Database)
                </h1>
                <p class="text-xs text-gray-500">Tra cứu ca dạy thực tế, đối soát giờ dạy FaceID và tính thù lao</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('payroll.timesheets.manual') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-semibold shadow-sm transition">
                    <span class="material-symbols-outlined text-[18px]">add_circle</span>
                    <span>Thêm ca dạy thủ công</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4">
        <!-- Timesheet table -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Giáo viên</th>
                        <th class="py-3 px-4">Lớp học</th>
                        <th class="py-3 px-4">Ngày dạy</th>
                        <th class="py-3 px-4">Loại ca dạy</th>
                        <th class="py-3 px-4 text-center">Số giờ</th>
                        <th class="py-3 px-4 text-right">Đơn giá / giờ</th>
                        <th class="py-3 px-4 text-right">Thành tiền</th>
                        <th class="py-3 px-4">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($timesheets as $ts)
                        <tr class="hover:bg-orange-50/20 transition">
                            <td class="py-3.5 px-4 font-bold text-gray-900">{{ $ts->teacher?->name }}</td>
                            <td class="py-3.5 px-4 font-semibold text-primary">{{ $ts->classModel?->name ?? '—' }}</td>
                            <td class="py-3.5 px-4 font-mono text-gray-500">{{ $ts->teaching_date->format('d/m/Y') }}</td>
                            <td class="py-3.5 px-4">{{ $ts->type_label }}</td>
                            <td class="py-3.5 px-4 text-center font-mono font-bold">{{ $ts->hours }}h</td>
                            <td class="py-3.5 px-4 text-right font-mono">{{ number_format($ts->hourly_rate) }}đ</td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-600 text-sm">
                                {{ number_format($ts->hours * $ts->hourly_rate) }}đ
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold {{ $ts->status === 'valid' ? 'bg-emerald-50 text-emerald-700' : ($ts->status === 'invalid' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">{{ $ts->status }}</span>
                                @can('attendance_staff.view')
                                    @if($ts->status === 'pending_review')
                                        <div class="flex gap-1 mt-2">
                                            <form method="POST" action="{{ route('payroll.timesheets.review', $ts->id) }}">@csrf<button name="decision" value="valid" class="text-[10px] font-bold text-emerald-700">Duyệt</button></form>
                                            <form method="POST" action="{{ route('payroll.timesheets.review', $ts->id) }}">@csrf<input type="hidden" name="rejection_reason" value="Dữ liệu check-in cần xác minh"><button name="decision" value="invalid" class="text-[10px] font-bold text-rose-700">Từ chối</button></form>
                                        </div>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-8 text-gray-400 text-xs">Chưa có dữ liệu chấm công.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <x-pagination :paginator="$timesheets" />
        </div>
    </div>
</x-app-layout>
