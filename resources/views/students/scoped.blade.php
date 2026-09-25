<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('students.show', $student->id) }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-600">admin_panel_settings</span>
                    Hồ sơ Học viên: {{ $student->name }} ({{ $student->code }}) — Phân quyền
                </h1>
                <p class="text-xs text-gray-500">Mô phỏng góc nhìn giao diện theo từng quyền: Giáo viên, Học vụ, Kế toán, Quản trị</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6" x-data="{ currentRole: 'teacher' }">
        <!-- Role switcher tabs -->
        <div class="bg-white rounded-2xl p-4 border border-gray-200 shadow-sm flex items-center justify-between">
            <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Chọn vai trò hiển thị:</span>
            <div class="flex items-center gap-2">
                <button @click="currentRole = 'teacher'" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition" :class="currentRole === 'teacher' ? 'bg-primary-container text-white' : 'bg-gray-100 text-gray-700'">
                    👨‍🏫 Giáo viên (Chỉ xem học tập)
                </button>
                <button @click="currentRole = 'academic'" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition" :class="currentRole === 'academic' ? 'bg-primary-container text-white' : 'bg-gray-100 text-gray-700'">
                    📚 Học vụ (Điểm & Chuyên cần)
                </button>
                <button @click="currentRole = 'accountant'" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition" :class="currentRole === 'accountant' ? 'bg-primary-container text-white' : 'bg-gray-100 text-gray-700'">
                    💰 Kế toán (Học phí & Hóa đơn)
                </button>
            </div>
        </div>

        <!-- Scoped Content -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-4">
            <template x-if="currentRole === 'teacher'">
                <div class="space-y-4 text-xs">
                    <div class="p-3 bg-blue-50 text-blue-800 rounded-xl border border-blue-200">
                        🔒 <strong>Chế độ Giáo viên:</strong> Đã ẩn toàn bộ thông tin học phí, công nợ, số điện thoại gia đình và doanh thu.
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 border rounded-xl">
                            <span class="text-gray-500 font-semibold block mb-1">Họ tên học sinh:</span>
                            <div class="font-bold text-sm text-gray-900">{{ $student->name }}</div>
                        </div>
                        <div class="p-4 border rounded-xl">
                            <span class="text-gray-500 font-semibold block mb-1">Lớp giảng dạy:</span>
                            <div class="font-bold text-sm text-primary">{{ $student->currentClass?->name ?? 'Chưa gán lớp' }}</div>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="currentRole === 'accountant'">
                <div class="space-y-4 text-xs">
                    <div class="p-3 bg-emerald-50 text-emerald-800 rounded-xl border border-emerald-200">
                        💰 <strong>Chế độ Kế toán:</strong> Xem đầy đủ lịch sử đóng tiền, biên lai và trạng thái công nợ.
                    </div>
                    <div class="p-4 border rounded-xl space-y-2">
                        <div class="flex justify-between"><span class="text-gray-500">Tổng học phí:</span><span class="font-bold font-mono">{{ number_format($student->tuition?->final_amount ?? 12500000) }}đ</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Đã thanh toán:</span><span class="font-bold text-emerald-600 font-mono">{{ number_format($student->tuition?->paid_amount ?? 0) }}đ</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Công nợ:</span><span class="font-bold text-rose-600 font-mono">{{ number_format($student->tuition?->debt_amount ?? 0) }}đ</span></div>
                    </div>
                </div>
            </template>

            <template x-if="currentRole === 'academic'">
                <div class="space-y-4 text-xs">
                    <div class="p-3 bg-purple-50 text-purple-800 rounded-xl border border-purple-200">
                        📚 <strong>Chế độ Học vụ:</strong> Quản lý danh sách điểm danh, chuyên cần và bảo lưu khóa học.
                    </div>
                    <div class="p-4 border rounded-xl space-y-2">
                        <div class="flex justify-between"><span class="text-gray-500 font-semibold">Tỷ lệ Chuyên cần:</span><span class="font-bold text-emerald-600">{{ $student->attendance_rate }}%</span></div>
                        <div class="flex justify-between"><span class="text-gray-500 font-semibold">Bài tập về nhà (HW):</span><span class="font-bold text-blue-600">{{ $student->homework_rate }}%</span></div>
                        <div class="flex justify-between"><span class="text-gray-500 font-semibold">Mục tiêu:</span><span class="font-bold text-primary">{{ $student->target }}</span></div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-app-layout>
