<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('payroll.timesheets.teachers') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-gray-900 tracking-tight">Chấm công Ca dạy Thủ công (Giáo viên / Trợ giảng)</h1>
                <p class="text-xs text-gray-500">Ghi nhận giờ dạy thực tế, ca dạy thay, dạy kèm 1-1 hoặc workshop</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <form action="{{ route('payroll.timesheets.manual.store') }}" method="POST" class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Giáo viên / Trợ giảng được chấm công <span class="text-rose-500">*</span></label>
                    <select name="user_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-bold">
                        @foreach ($teachers as $tc)
                            <option value="{{ $tc->id }}">
                                {{ $tc->name }} - [{{ $tc->getRoleNames()->implode(', ') ?: 'GV/TA' }}] ({{ $tc->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Lớp học giảng dạy <span class="text-rose-500">*</span></label>
                    <select name="class_id" required class="w-full text-xs rounded-xl border border-gray-200 p-2 font-semibold text-primary">
                        @foreach ($classes as $cl)
                            <option value="{{ $cl->id }}">{{ $cl->name }} ({{ $cl->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Ngày giảng dạy <span class="text-rose-500">*</span></label>
                    <input type="date" name="teaching_date" value="{{ date('Y-m-d') }}" required class="w-full text-xs rounded-xl border border-gray-200 p-2" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Số giờ tính công (Hours) <span class="text-rose-500">*</span></label>
                    <input type="number" step="0.5" name="hours" value="2.0" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Đơn giá giờ dạy (VNĐ/h) <span class="text-rose-500">*</span></label>
                    <input type="number" name="hourly_rate" value="300000" required class="w-full text-xs font-mono font-bold rounded-xl border border-gray-200 p-2 text-emerald-600" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Loại ca dạy</label>
                    <select name="type" class="w-full text-xs rounded-xl border border-gray-200 p-2">
                        <option value="regular">Ca dạy chính khóa</option>
                        <option value="sub">Dạy thay (Sub)</option>
                        <option value="1on1">Kèm phụ đạo 1-1</option>
                        <option value="grading">Chấm bài thi Test</option>
                        <option value="workshop">Workshop / Sự kiện</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">Ghi chú ca dạy</label>
                    <textarea name="notes" rows="2" placeholder="Ghi chú nội dung buổi học, học sinh vắng..." class="w-full text-xs rounded-xl border border-gray-200 p-2"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="submit" class="px-6 py-2.5 bg-primary hover:bg-primary-hover text-white text-xs font-bold rounded-xl shadow-md transition flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span>Lưu Chấm công vào CSDL</span>
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
