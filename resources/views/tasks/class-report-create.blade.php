<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('portal.ta-tasks') }}" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Nộp báo cáo trực lớp</h1>
                    <p class="text-xs text-gray-500 mt-0.5">Báo cáo nội dung bài giảng, nhật ký lớp học và học sinh cần bổ trợ</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-6" x-data="{
        supports: [
            { id: 1, student_id: '{{ $students->first()?->id ?? '' }}', absence_session: 'Buổi 3 - Speaking', reason: 'Học sinh yếu kỹ năng nghe, không theo kịp tiến độ trên lớp.', action_plan: 'Làm lại bài tập nghe Part 1 trang 12 và ghi âm gửi TA.' }
        ],
        addSupport() {
            this.supports.push({
                id: Date.now(),
                student_id: '',
                absence_session: '',
                reason: '',
                action_plan: ''
            });
        },
        removeSupport(index) {
            this.supports.splice(index, 1);
        }
    }">

        <form action="{{ route('tasks.class-reports.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @if(!empty($taskId))
                <input type="hidden" name="task_id" value="{{ $taskId }}">
            @endif

            <!-- Thông tin Lớp học & Buổi học Card -->
            <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-xs space-y-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-orange-100 text-primary-container flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined">class</span>
                    </div>
                    <div class="flex-1">
                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Lớp học</label>
                        <select name="class_id" required class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary-container focus:border-primary-container">
                            @foreach($classes as $c)
                                <option value="{{ $c->id }}" {{ $selectedClass && $selectedClass->id === $c->id ? 'selected' : '' }}>
                                    {{ $c->name }} ({{ $c->code }}) · {{ $c->schedule_text }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="border-t border-gray-100 pt-3 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined">event_note</span>
                    </div>
                    <div class="flex-1">
                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Buổi học</label>
                        <input type="text" name="session_name" required value="Buổi 5 - Listening Practice"
                               class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary-container focus:border-primary-container">
                    </div>
                </div>
            </div>

            <!-- Form nội dung -->
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-xs space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5" for="hom_nay_hoc_gi">
                        Hôm nay học gì <span class="text-rose-500">*</span>
                    </label>
                    <textarea id="hom_nay_hoc_gi" name="hom_nay_hoc_gi" required rows="3" placeholder="Tóm tắt nội dung chính đã giảng dạy..."
                              class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary-container focus:border-primary-container">Hôm nay học Section 1 & Section 2 dạng bài Form/Note Completion, chiến thuật bắt từ khóa (Keywords) và tránh bẫy ngữ pháp.</textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5" for="nhat_ky_day">
                        Nhật ký dạy <span class="text-gray-400 font-normal text-xs">(Tùy chọn)</span>
                    </label>
                    <textarea id="nhat_ky_day" name="nhat_ky_day" rows="3" placeholder="Ghi chú về thái độ học tập, vấn đề phát sinh..."
                              class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary-container focus:border-primary-container">Lớp học nghiêm túc, phần nghe số điện thoại và tên riêng còn một số bạn nhầm lẫn giữa 15 và 50.</textarea>
                </div>

                <!-- Đính kèm hình ảnh bảng / lớp -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-1.5">Đính kèm hình ảnh bảng / lớp học</label>
                    <div class="border-2 border-dashed border-gray-300 rounded-2xl p-6 flex flex-col items-center justify-center bg-gray-50/50 hover:bg-gray-50 transition cursor-pointer relative">
                        <span class="material-symbols-outlined text-4xl text-gray-400 mb-1">add_a_photo</span>
                        <p class="text-xs font-semibold text-gray-800">Nhấn để tải ảnh bảng/lớp lên</p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Hỗ trợ JPG, PNG (Tối đa 10MB)</p>
                        <input type="file" name="board_image" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer">
                    </div>
                </div>
            </div>

            <!-- Học sinh cần bổ trợ Section -->
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                    <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-rose-500">warning</span>
                        Học sinh cần bổ trợ
                    </h2>
                    <span class="text-xs text-gray-500" x-text="supports.length + ' học sinh'"></span>
                </div>

                <div class="space-y-4">
                    <template x-for="(sup, idx) in supports" :key="sup.id">
                        <div class="bg-gray-50/80 border border-gray-200 rounded-xl p-4 relative space-y-3">
                            <button type="button" @click="removeSupport(idx)" title="Xóa" class="absolute top-3 right-3 text-gray-400 hover:text-rose-600 p-1 rounded-lg hover:bg-rose-50">
                                <span class="material-symbols-outlined text-[18px]">delete</span>
                            </button>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pr-8">
                                <div>
                                    <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Học sinh</label>
                                    <select :name="'supports[' + idx + '][student_id]'" x-model="sup.student_id" class="w-full rounded-lg border-gray-200 text-xs bg-white py-2">
                                        <option value="">-- Chọn học sinh --</option>
                                        @foreach($students as $st)
                                            <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->code }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Buổi vắng (Tùy chọn)</label>
                                    <input type="text" :name="'supports[' + idx + '][absence_session]'" x-model="sup.absence_session" placeholder="VD: Buổi 3 - Speaking"
                                           class="w-full rounded-lg border-gray-200 text-xs bg-white py-2">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Lý do <span class="text-rose-500">*</span></label>
                                <textarea :name="'supports[' + idx + '][reason]'" x-model="sup.reason" rows="2" placeholder="Mô tả lý do cần bổ trợ..."
                                          class="w-full rounded-lg border-gray-200 text-xs bg-white"></textarea>
                            </div>

                            <div>
                                <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Kế hoạch xử lý</label>
                                <textarea :name="'supports[' + idx + '][action_plan]'" x-model="sup.action_plan" rows="2" placeholder="Kế hoạch xử lý bài tập hoặc phụ đạo..."
                                          class="w-full rounded-lg border-blue-200 text-xs bg-blue-50/50 text-blue-900"></textarea>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" @click="addSupport()" class="w-full py-3 border border-dashed border-primary-container text-primary-container font-semibold text-xs rounded-xl flex items-center justify-center gap-1.5 hover:bg-orange-50/50 transition">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Thêm học sinh cần bổ trợ
                </button>
            </div>

            <!-- Footer Action -->
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm flex flex-col items-center gap-2">
                <button type="submit" class="w-full bg-primary-container text-white hover:bg-primary font-bold text-sm py-3.5 rounded-xl flex items-center justify-center gap-2 shadow-md hover:shadow-lg transition">
                    <span class="material-symbols-outlined text-[20px]">send</span>
                    Nộp báo cáo trực lớp
                </button>
                <p class="text-xs text-gray-500 text-center">
                    <span class="font-bold text-emerald-700">Có ảnh đính kèm</span> → Hoàn thành ngay. <span class="font-bold text-rose-700">Không có ảnh</span> → Chờ GV chính xác nhận.
                </p>
            </div>
        </form>
    </div>
</x-app-layout>
