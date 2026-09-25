{{--
    Hồ sơ học viên theo phân quyền: server chỉ render phần người xem có quyền.
    - Học tập (mọi người được xem hồ sơ): họ tên, lớp, mục tiêu, trạng thái.
    - Liên hệ: cần student.update hoặc tuition.view.
    - Chuyên cần & điểm: cần attendance_student.view hoặc student.update (số liệu từ điểm danh thật).
    - Học phí: chỉ khi có tuition.view (dữ liệu không được gửi xuống cho người khác).
--}}
@php
    $roleName = auth()->user()?->getRoleNames()->first();
    $roleLabel = $roleName ? \App\Helpers\AclHelper::roleLabel($roleName) : 'Người dùng';
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('students.show', $student->id) }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition" aria-label="Quay lại hồ sơ">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-600">admin_panel_settings</span>
                    Hồ sơ học viên: {{ $student->name }} ({{ $student->code }}) — Phân quyền
                </h1>
                <p class="text-xs text-gray-500">Chỉ hiển thị các mục bạn được phân quyền xem.</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6">
        <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm flex flex-wrap items-center justify-between gap-2 text-xs">
            <span class="font-bold text-gray-700 uppercase tracking-wider">Đang xem với vai trò</span>
            <x-ui.badge color="info" pill>{{ $roleLabel }}</x-ui.badge>
        </div>

        {{-- Học tập (luôn hiển thị) --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-4 text-xs">
            <h2 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[20px]">school</span> Thông tin học tập
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 border rounded-xl">
                    <span class="text-gray-500 font-semibold block mb-1">Họ tên học viên</span>
                    <div class="font-bold text-sm text-gray-900">{{ $student->name }}</div>
                </div>
                <div class="p-4 border rounded-xl">
                    <span class="text-gray-500 font-semibold block mb-1">Lớp đang học</span>
                    <div class="font-bold text-sm text-primary">{{ $student->currentClass?->name ?? 'Chưa xếp lớp' }}</div>
                </div>
                <div class="p-4 border rounded-xl">
                    <span class="text-gray-500 font-semibold block mb-1">Mục tiêu</span>
                    <div class="font-bold text-sm text-gray-900">{{ $student->target ?: 'Chưa cập nhật' }}</div>
                </div>
                <div class="p-4 border rounded-xl">
                    <span class="text-gray-500 font-semibold block mb-1">Trạng thái</span>
                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold border {{ $student->status_badge }} inline-block">{{ $student->status_label }}</span>
                </div>
            </div>
        </section>

        @if ($canViewContact)
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-3 text-xs" data-section="contact">
                <h2 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">contact_phone</span> Liên hệ
                </h2>
                <div class="flex justify-between"><span class="text-gray-500">Số điện thoại</span><span class="font-bold font-mono">{{ $student->phone }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Email</span><span class="font-mono">{{ $student->email ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Cơ sở</span><span class="font-semibold">{{ $student->branch?->name ?? '—' }}</span></div>
            </section>
        @endif

        @if ($canViewAcademic)
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-3 text-xs" data-section="academic">
                <h2 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">how_to_reg</span> Chuyên cần &amp; điểm số
                </h2>
                <div class="flex justify-between">
                    <span class="text-gray-500 font-semibold">Chuyên cần</span>
                    <span class="font-bold text-emerald-600">
                        @if ($attendanceStats['rate'] === null)
                            Chưa có dữ liệu điểm danh
                        @else
                            {{ $attendanceStats['present'] }}/{{ $attendanceStats['recorded'] }} buổi ({{ $attendanceStats['rate'] }}%)
                        @endif
                    </span>
                </div>
                <div class="flex justify-between"><span class="text-gray-500 font-semibold">Điểm đầu vào</span><span class="font-bold">{{ $student->entrance_score ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500 font-semibold">Giữa kỳ</span><span class="font-bold">{{ $student->midterm_score ?: '—' }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500 font-semibold">Cuối kỳ</span><span class="font-bold">{{ $student->final_score ?: '—' }}</span></div>
            </section>
        @endif

        @if ($canViewTuition)
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-3 text-xs" data-section="tuition">
                <h2 class="font-bold text-sm text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">payments</span> Học phí &amp; công nợ
                </h2>
                @if ($student->tuition)
                    <div class="flex justify-between"><span class="text-gray-500">Tổng học phí</span><span class="font-bold font-mono">{{ number_format($student->tuition->final_amount) }}đ</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Đã thanh toán</span><span class="font-bold text-emerald-600 font-mono">{{ number_format($student->tuition->paid_amount) }}đ</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Công nợ</span><span class="font-bold text-rose-600 font-mono">{{ number_format($student->tuition->debt_amount) }}đ</span></div>
                @else
                    <x-ui.empty-state icon="receipt_long" title="Chưa có sổ học phí" description="Học viên chưa được lập sổ học phí." />
                @endif
            </section>
        @endif
    </div>
</x-app-layout>
