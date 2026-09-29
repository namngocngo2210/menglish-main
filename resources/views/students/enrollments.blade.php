<x-app-layout>
    <x-ui.page-header title="Xác nhận nhập học" icon="how_to_reg" description="Tiếp nhận học viên và bàn giao lớp học.">
        @if (auth()->user()->can('student.assign_class'))
            <x-slot:actions>
                <x-ui.button icon="person_add" x-on:click="$dispatch('open-modal', 'enroll-student')">Xếp lớp cho học viên</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @php
        // Khách chốt từ CRM đang chờ lớp: xếp ở một nơi duy nhất (màn Chờ xếp lớp của CRM) — ở đây chỉ nhắc số + link.
        $crmWaitingCount = auth()->user()->can('lead.view')
            ? \App\Models\CrmCustomer::query()->visibleTo(auth()->user())->where('stage', 'waiting_class')->count()
            : 0;
        $canHandoff = auth()->user()->can('student.assign_class');
        $canOpenConfirmations = $canHandoff && auth()->user()->can('lead.view');
        // Mở từ cột Hành động của bảng Chờ xếp lớp (?student_id=): chọn sẵn học viên, nhắc khóa / chi nhánh đã chốt.
        $preselectedId = (string) old('student_id', request('student_id'));
        $preselected = $preselectedId !== '' ? $students->firstWhere('id', (int) $preselectedId) : null;
        $preselectedLead = $preselected
            ? \App\Models\CrmCustomer::query()->with(['waitingCourse', 'waitingBranch', 'branch'])
                ->where('stage', 'waiting_class')->where('converted_student_id', $preselected->id)->first()
            : null;
    @endphp

    <div class="space-y-6">
        @if ($crmWaitingCount > 0)
            <div class="flex flex-wrap items-center justify-between gap-sm rounded-xl border border-error/20 bg-error-container/20 px-md py-sm">
                <div class="flex items-center gap-sm font-body-medium text-body-medium text-on-surface">
                    <span class="material-symbols-outlined text-error" style="font-variation-settings: 'FILL' 1;" aria-hidden="true">error</span>
                    <span><strong class="text-error">{{ $crmWaitingCount }}</strong> khách chốt từ CRM đang chờ xếp lớp</span>
                </div>
                <x-ui.button variant="secondary" size="sm" icon="arrow_forward" :href="route('crm.waiting-list')">{{ $canHandoff ? 'Xếp lớp' : 'Xem danh sách' }}</x-ui.button>
            </div>
        @endif

        {{-- Lịch sử xếp lớp. Checklist bàn giao chỉ sửa ở MỘT nơi:
             - lượt xếp lớp từ CRM (có khách CRM): sửa ở màn Xác nhận chính thức — ở đây chỉ xem + link;
             - lượt xếp lớp trực tiếp (không qua CRM): sửa ngay tại cột Giáo trình / Zalo. --}}
        <x-ui.data-table>
            <x-slot:header>
                <h2 class="font-bold text-xs text-on-surface uppercase tracking-wider">Danh sách bàn giao học viên gần đây</h2>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Lớp học tiếp nhận</th>
                        <th>Giáo viên chủ nhiệm</th>
                        <th>Phát giáo trình</th>
                        <th>Nhóm Zalo lớp</th>
                        <th>Ngày vào lớp</th>
                        <th class="text-right">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($enrollments as $en)
                        @php
                            $dropped = $en->status === \App\Models\Student::ENROLLMENT_DROPPED;
                            $fromCrm = (bool) $en->customer_id;
                            $editable = $canHandoff && ! $dropped && ! $fromCrm;
                        @endphp
                        <tr>
                            <td class="font-bold">{{ $en->student?->name }}</td>
                            <td class="font-semibold text-primary">{{ $en->classModel?->name }}</td>
                            <td>{{ $en->classModel?->teacher?->name ?? 'Chưa phân công' }}</td>
                            @foreach (['curriculum_delivered' => ['Đã phát', 'Chờ phát', 'Giáo trình'], 'zalo_group_added' => ['Đã thêm', 'Chờ thêm', 'Zalo']] as $field => [$doneLabel, $pendingLabel, $shortLabel])
                                <td>
                                    @if ($editable)
                                        <label class="inline-flex cursor-pointer items-center gap-1 font-semibold">
                                            <input type="checkbox" name="{{ $field }}" value="1" form="handoff-{{ $en->id }}" @checked($en->{$field}) class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40" aria-label="{{ $shortLabel }} — {{ $en->student?->name }}">
                                            <span class="{{ $en->{$field} ? 'text-tertiary' : 'text-warning' }}">{{ $en->{$field} ? $doneLabel : $pendingLabel }}</span>
                                        </label>
                                    @else
                                        <span class="{{ $en->{$field} ? 'text-tertiary' : 'text-warning' }} font-semibold flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm">{{ $en->{$field} ? 'check_circle' : 'pending' }}</span>
                                            {{ $en->{$field} ? $doneLabel : $pendingLabel }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="font-code text-on-surface-variant">{{ $en->enrolled_at ? $en->enrolled_at->format('d/m/Y') : '—' }}</td>
                            <td class="text-right">
                                @if ($dropped)
                                    <x-ui.badge color="error" pill>Thôi học</x-ui.badge>
                                @elseif ($editable)
                                    <form id="handoff-{{ $en->id }}" method="POST" action="{{ route('students.enrollments.update', $en->id) }}" class="inline-flex items-center justify-end gap-2">
                                        @csrf
                                        @method('PUT')
                                        <x-ui.badge :color="$en->status === 'completed' ? 'success' : 'warning'" pill>{{ $en->status === 'completed' ? 'Hoàn tất' : 'Chờ bàn giao' }}</x-ui.badge>
                                        <x-ui.button type="submit" variant="secondary" size="sm">Lưu</x-ui.button>
                                    </form>
                                @else
                                    <div class="flex flex-col items-end gap-1">
                                        <x-ui.badge :color="$en->status === 'completed' ? 'success' : 'warning'" pill>{{ $en->status === 'completed' ? 'Hoàn tất' : 'Chờ bàn giao' }}</x-ui.badge>
                                        @if ($fromCrm && $canOpenConfirmations)
                                            <a href="{{ route('crm.confirmations', array_filter(['search' => $en->student?->code, 'status' => $en->confirmed_at ? 'confirmed' : null])) }}"
                                               class="inline-flex items-center gap-1 whitespace-nowrap text-xs font-semibold text-primary hover:underline">
                                                Sửa ở Xác nhận chính thức<span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7"><x-ui.empty-state title="Chưa có lịch sử bàn giao nào." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$enrollments" /></x-slot:footer>
        </x-ui.data-table>
    </div>

    {{-- Xếp lớp cho học viên (cùng động từ "Xếp lớp" với CRM). Mở sẵn khi đến từ bảng Chờ xếp lớp (?student_id=) hoặc khi lỗi validate. --}}
    @if ($canHandoff)
        <x-ui.modal name="enroll-student" title="Xếp lớp cho học viên" max-width="xl" :show="old('_modal') === 'enroll-student' || request()->filled('student_id')">
            <form id="enroll-student-form" action="{{ route('students.enrollments.store') }}" method="POST" class="space-y-md">
                @csrf
                <input type="hidden" name="_modal" value="enroll-student">
                @if ($preselectedLead)
                    <div class="flex flex-wrap items-center justify-between gap-sm rounded-lg border border-warning/30 bg-warning/10 px-md py-sm font-body-small text-body-small text-on-surface">
                        <span>
                            <strong>{{ $preselected->name }}</strong> đã chốt khóa <strong>{{ $preselectedLead->waitingCourse?->name ?? '—' }}</strong>
                            tại <strong>{{ $preselectedLead->waitingBranch?->name ?? $preselectedLead->branch?->name ?? '—' }}</strong>.
                            Chọn lớp đúng khóa đã chốt, hoặc tạo lớp mới nếu chưa có.
                        </span>
                        @can('class.create')
                            <x-ui.button variant="secondary" size="sm" icon="add" :href="route('classes.create')">Tạo lớp mới</x-ui.button>
                        @endcan
                    </div>
                @endif
                <x-ui.select name="student_id" label="Chọn Học viên" required>
                    @foreach ($students as $st)
                        <option value="{{ $st->id }}" @selected((string) $st->id === $preselectedId)>{{ $st->name }} ({{ $st->code }})</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="class_id" label="Chọn Lớp học mục tiêu" required class="font-semibold text-primary">
                    @foreach ($classes as $cl)
                        <option value="{{ $cl->id }}">{{ $cl->name }} ({{ $cl->code }})</option>
                    @endforeach
                </x-ui.select>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'enroll-student')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="enroll-student-form" icon="assignment_turned_in">Xếp lớp</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-app-layout>
