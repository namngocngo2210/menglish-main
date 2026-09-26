{{-- Học viên đã chốt nhưng chưa có lớp (khách ở Chờ xếp lớp) — mockup khach-hang-chot-thanh-cong, khu vực 1.
     Cần: $waitingLeads, $matchingClassesByLead --}}
<section id="waiting-class" class="overflow-hidden rounded-xl border border-error/20 bg-surface-container-lowest shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-md border-b border-error/10 bg-error-container/30 px-lg py-md">
        <div class="flex items-center gap-sm">
            <span class="material-symbols-outlined text-error" style="font-variation-settings: 'FILL' 1;">error</span>
            <div>
                <h2 class="font-h3 text-h3 text-on-surface">Chờ xếp lớp (Cần xử lý gấp)</h2>
                <p class="font-body-small text-body-small text-on-surface-variant">Hiện có <span class="font-bold text-error">{{ str_pad((string) $waitingLeads->count(), 2, '0', STR_PAD_LEFT) }}</span> học viên đang đợi phân bổ vào lớp mới. Gợi ý lớp đúng khóa, đúng chi nhánh, còn chỗ.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-xs rounded-full bg-error/10 px-md py-xs font-caption text-caption font-bold text-error">
            <span class="material-symbols-outlined text-[14px]">schedule</span>Ưu tiên xử lý
        </span>
    </div>
    <x-ui.data-table min-width="900px" class="!rounded-none !border-0">
        <table>
            <thead>
                <tr>
                    <th>Họ tên</th>
                    <th>Số điện thoại</th>
                    <th>Chi nhánh</th>
                    <th>Thời điểm chốt</th>
                    <th class="text-right">Hành động</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($waitingLeads as $lead)
                    @php
                        $matches = $matchingClassesByLead->get($lead->id, collect());
                        $waitDays = $lead->converted_at ? (int) $lead->converted_at->diffInDays(now()) : null;
                    @endphp
                    <tr class="align-top">
                        <td>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$lead->name" size="sm" />
                                <div>
                                    <a href="{{ route('crm.customers.show', $lead->id) }}" class="font-body-medium text-body-medium text-on-surface hover:text-primary">{{ $lead->name }}</a>
                                    <div class="font-code text-caption text-on-surface-variant">{{ $lead->convertedStudent?->code }} · {{ $lead->waitingCourse?->name ?? 'Chưa chọn khóa' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="font-code text-code text-on-surface-variant">{{ $lead->phone }}</td>
                        <td><span class="rounded bg-surface-container-high px-sm py-0.5 font-body-small text-body-small text-on-surface-variant">{{ $lead->waitingBranch?->name ?? $lead->branch?->name ?? '—' }}</span></td>
                        <td>
                            <div class="font-code text-code text-on-surface">{{ $lead->converted_at?->format('H:i d/m/Y') ?? '—' }}</div>
                            @if ($waitDays !== null)
                                <div class="font-caption text-caption {{ $waitDays >= 7 ? 'font-bold text-error' : 'text-on-surface-variant' }}">Chờ {{ $waitDays }} ngày</div>
                            @endif
                        </td>
                        <td class="text-right">
                            @can('student.assign_class')
                                @if ($matches->isNotEmpty())
                                    <form action="{{ route('crm.customers.assign-class', $lead->id) }}" method="POST" class="inline-flex items-center justify-end gap-sm">
                                        @csrf
                                        <x-ui.select name="class_id" value="" required aria-label="Lớp gán cho {{ $lead->name }}" class="max-w-[320px] font-body-small text-body-small">
                                            @foreach ($matches as $class)
                                                <option value="{{ $class->id }}">{{ $class->name }}{{ $class->status === 'upcoming' ? ' (sắp khai giảng)' : '' }} · còn {{ $class->max_capacity > 0 ? max(0, $class->max_capacity - $class->active_enrollments_count) : '∞' }} chỗ{{ $class->status === 'upcoming' && $class->active_enrollments_count < (int) $class->min_students ? ' · cần thêm '.((int) $class->min_students - $class->active_enrollments_count).' HV để khai giảng' : '' }}</option>
                                            @endforeach
                                        </x-ui.select>
                                        <x-ui.button type="submit" size="sm" icon="assignment_turned_in">Gán lớp</x-ui.button>
                                    </form>
                                @else
                                    <span class="font-body-small text-body-small font-semibold text-warning">Chưa có lớp phù hợp</span>
                                @endif
                            @else
                                <span class="font-body-small text-body-small text-on-surface-variant">Học vụ sẽ gán lớp</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="task_alt" title="Không có học viên nào đang chờ xếp lớp" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
</section>
