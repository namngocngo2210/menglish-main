<x-app-layout>
    <x-ui.page-header title="Tiếp nhận Học viên & Bàn giao Lớp học" icon="how_to_reg" />

    <div class="space-y-6">
        {{-- Quick Enrollment Form --}}
        <form action="{{ route('students.enrollments.store') }}" method="POST" class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm p-5 space-y-4">
            @csrf
            <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider flex items-center gap-1.5 pb-2 border-b border-surface-container-highest">
                <span class="material-symbols-outlined text-primary text-base">person_add</span>
                Xếp lớp nhanh cho học viên
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <x-ui.select name="student_id" label="Chọn Học viên" required>
                    @foreach ($students as $st)
                        <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->code }})</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="class_id" label="Chọn Lớp học mục tiêu" required class="font-semibold text-primary">
                    @foreach ($classes as $cl)
                        <option value="{{ $cl->id }}">{{ $cl->name }} ({{ $cl->code }})</option>
                    @endforeach
                </x-ui.select>
                <div class="flex items-end">
                    <x-ui.button type="submit" class="w-full">Bàn giao vào Lớp</x-ui.button>
                </div>
            </div>
        </form>

        {{-- Enrollment History Table --}}
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
                        <tr>
                            <td class="font-bold">{{ $en->student?->name }}</td>
                            <td class="font-semibold text-primary">{{ $en->classModel?->name }}</td>
                            <td>{{ $en->classModel?->teacher?->name ?? 'Chưa phân công' }}</td>
                            <td>
                                <span class="{{ $en->curriculum_delivered ? 'text-tertiary' : 'text-warning' }} font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">{{ $en->curriculum_delivered ? 'check_circle' : 'pending' }}</span>
                                    {{ $en->curriculum_delivered ? 'Đã phát' : 'Chờ phát' }}
                                </span>
                            </td>
                            <td>
                                <span class="{{ $en->zalo_group_added ? 'text-tertiary' : 'text-warning' }} font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">{{ $en->zalo_group_added ? 'check_circle' : 'pending' }}</span>
                                    {{ $en->zalo_group_added ? 'Đã thêm' : 'Chờ thêm' }}
                                </span>
                            </td>
                            <td class="font-code text-on-surface-variant">{{ $en->enrolled_at ? $en->enrolled_at->format('d/m/Y') : '—' }}</td>
                            <td class="text-right">
                                @if ($en->status === \App\Models\Student::ENROLLMENT_DROPPED)
                                    <x-ui.badge color="error" pill>Thôi học</x-ui.badge>
                                @elsecan('student.assign_class')
                                    <form method="POST" action="{{ route('students.enrollments.update', $en->id) }}" class="inline-flex items-center justify-end gap-2">
                                        @csrf
                                        @method('PUT')
                                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="curriculum_delivered" value="1" @checked($en->curriculum_delivered) class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40"> Giáo trình</label>
                                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="zalo_group_added" value="1" @checked($en->zalo_group_added) class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40"> Zalo</label>
                                        <x-ui.button type="submit" size="sm">Lưu</x-ui.button>
                                    </form>
                                @else
                                    <x-ui.badge :color="$en->status === 'completed' ? 'success' : 'warning'" pill>{{ $en->status === 'completed' ? 'Hoàn tất' : 'Chờ bàn giao' }}</x-ui.badge>
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
</x-app-layout>
