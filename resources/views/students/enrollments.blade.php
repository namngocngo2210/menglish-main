<x-app-layout>
    <x-ui.page-header title="Tiếp nhận Học viên & Bàn giao Lớp học" icon="how_to_reg" />

    <div class="space-y-6">
        {{-- Quick Enrollment Form --}}
        <form action="{{ route('students.enrollments.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
            @csrf
            <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider flex items-center gap-1.5 pb-2 border-b border-gray-100">
                <span class="material-symbols-outlined text-primary text-base">person_add</span>
                Xếp lớp nhanh cho học viên
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn Học viên <span class="text-rose-500">*</span></label>
                    <select name="student_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2">
                        @foreach ($students as $st)
                            <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Chọn Lớp học mục tiêu <span class="text-rose-500">*</span></label>
                    <select name="class_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-semibold text-primary">
                        @foreach ($classes as $cl)
                            <option value="{{ $cl->id }}">{{ $cl->name }} ({{ $cl->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full py-2 bg-primary-container hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-sm transition">
                        Bàn giao vào Lớp
                    </button>
                </div>
            </div>
        </form>

        {{-- Enrollment History Table --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-gray-100 font-bold text-xs text-gray-900 uppercase tracking-wider">
                Danh sách bàn giao học viên gần đây
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-4">Học viên</th>
                        <th class="py-3 px-4">Lớp học tiếp nhận</th>
                        <th class="py-3 px-4">Giáo viên chủ nhiệm</th>
                        <th class="py-3 px-4">Phát giáo trình</th>
                        <th class="py-3 px-4">Nhóm Zalo lớp</th>
                        <th class="py-3 px-4">Ngày vào lớp</th>
                        <th class="py-3 px-4 text-right">Trạng thái</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                    @forelse ($enrollments as $en)
                        <tr class="hover:bg-orange-50/20 transition">
                            <td class="py-3.5 px-4 font-bold text-gray-900">{{ $en->student?->name }}</td>
                            <td class="py-3.5 px-4 font-semibold text-primary">{{ $en->classModel?->name }}</td>
                            <td class="py-3.5 px-4">{{ $en->classModel?->teacher?->name ?? 'Chưa phân công' }}</td>
                            <td class="py-3.5 px-4">
                                <span class="{{ $en->curriculum_delivered ? 'text-emerald-600' : 'text-amber-600' }} font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">{{ $en->curriculum_delivered ? 'check_circle' : 'pending' }}</span>
                                    {{ $en->curriculum_delivered ? 'Đã phát' : 'Chờ phát' }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="{{ $en->zalo_group_added ? 'text-emerald-600' : 'text-amber-600' }} font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">{{ $en->zalo_group_added ? 'check_circle' : 'pending' }}</span>
                                    {{ $en->zalo_group_added ? 'Đã thêm' : 'Chờ thêm' }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-mono text-gray-500">{{ $en->enrolled_at ? $en->enrolled_at->format('d/m/Y') : '—' }}</td>
                            <td class="py-3.5 px-4 text-right">
                                @if ($en->status === \App\Models\Student::ENROLLMENT_DROPPED)
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border-rose-200 border">Thôi học</span>
                                @elsecan('student.assign_class')
                                    <form method="POST" action="{{ route('students.enrollments.update', $en->id) }}" class="inline-flex items-center justify-end gap-2">
                                        @csrf
                                        @method('PUT')
                                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="curriculum_delivered" value="1" @checked($en->curriculum_delivered)> Giáo trình</label>
                                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="zalo_group_added" value="1" @checked($en->zalo_group_added)> Zalo</label>
                                        <button class="px-2.5 py-1 rounded-lg bg-slate-900 text-white font-bold">Lưu</button>
                                    </form>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold {{ $en->status === 'completed' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200' }} border">
                                        {{ $en->status === 'completed' ? 'Hoàn tất' : 'Chờ bàn giao' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-6 text-gray-400 text-xs">Chưa có lịch sử bàn giao nào.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <x-pagination :paginator="$enrollments" />
        </div>
    </div>
</x-app-layout>
