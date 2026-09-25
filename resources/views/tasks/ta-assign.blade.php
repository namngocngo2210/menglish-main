<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tasks.index') }}" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Tạo lượt giao việc cho Trợ giảng</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Phân công nhiệm vụ chi tiết theo ngày và ca học cho trợ giảng</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto space-y-6" x-data="{
        tasks: [
            { id: 1, category: 'before', content: 'Chuẩn bị tài liệu in ấn cho học viên', attach_class: false, class_id: '', session: '' },
            { id: 2, category: 'during', content: 'Hỗ trợ giảng viên điều phối hoạt động trên lớp', attach_class: true, class_id: '{{ $classes->first()?->id ?? '' }}', session: 'Buổi 5 - Listening Practice' },
            { id: 3, category: 'after', content: 'Cập nhật điểm danh và kiểm tra phòng học', attach_class: false, class_id: '', session: '' }
        ],
        addTask() {
            this.tasks.push({
                id: Date.now(),
                category: 'during',
                content: '',
                attach_class: false,
                class_id: '',
                session: ''
            });
        },
        removeTask(index) {
            if (this.tasks.length > 1) {
                this.tasks.splice(index, 1);
            }
        }
    }">

        <form action="{{ route('tasks.ta-assign.store') }}" method="POST" class="space-y-6">
            @csrf

            <!-- Master Selection Card -->
            <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Select TA -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-600 mb-1.5" for="assistant_id">
                            Chọn Trợ giảng <span class="text-rose-500">*</span>
                        </label>
                        <select name="assistant_id" id="assistant_id" required class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary focus:border-primary p-3">
                            <option value="" disabled selected>-- Chọn Trợ giảng --</option>
                            @foreach($assistants as $ta)
                                <option value="{{ $ta->id }}">{{ $ta->name }} ({{ $ta->email }})</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Date -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-600 mb-1.5" for="assign_date">
                            Ngày giao việc <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" name="assign_date" id="assign_date" required value="{{ now()->format('Y-m-d') }}"
                               class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary focus:border-primary p-3">
                    </div>

                    <!-- Branch -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-600 mb-1.5" for="branch_id">
                            Chi nhánh
                        </label>
                        <select name="branch_id" id="branch_id" class="w-full rounded-xl border-gray-200 text-sm focus:ring-primary focus:border-primary p-3">
                            <option value="">-- Chọn Chi nhánh --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <hr class="border-gray-200">

                <!-- Dynamic Task Rows Section -->
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#F5691A]">checklist</span>
                            Danh sách nhiệm vụ cần giao
                        </h2>
                        <span class="text-xs text-gray-500" x-text="tasks.length + ' đầu việc'"></span>
                    </div>

                    <div class="space-y-4">
                        <template x-for="(item, index) in tasks" :key="item.id">
                            <div class="bg-gray-50/80 border border-gray-200 rounded-xl p-4 relative group transition hover:border-gray-300">
                                <!-- Delete button -->
                                <button type="button" @click="removeTask(index)" title="Xóa đầu việc"
                                        class="absolute top-3 right-3 text-gray-400 hover:text-rose-600 p-1 rounded-lg hover:bg-rose-50 transition">
                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                </button>

                                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 pr-8">
                                    <!-- Nhóm đầu mục -->
                                    <div class="md:col-span-3">
                                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Nhóm đầu mục</label>
                                        <select :name="'tasks[' + index + '][category]'" x-model="item.category"
                                                class="w-full rounded-lg border-gray-200 text-xs focus:ring-primary focus:border-primary bg-white py-2">
                                            <option value="before">Trước giờ học</option>
                                            <option value="during">Trong giờ học</option>
                                            <option value="after">Sau giờ học</option>
                                        </select>
                                    </div>

                                    <!-- Nội dung -->
                                    <div class="md:col-span-6">
                                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Nội dung công việc</label>
                                        <input type="text" :name="'tasks[' + index + '][content]'" x-model="item.content" required placeholder="Nhập nội dung công việc..."
                                               class="w-full rounded-lg border-gray-200 text-xs focus:ring-primary focus:border-primary bg-white py-2">
                                    </div>

                                    <!-- Gắn lớp toggle -->
                                    <div class="md:col-span-3 flex items-center pt-2 md:pt-5">
                                        <label class="inline-flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" :name="'tasks[' + index + '][attach_class]'" x-model="item.attach_class" value="1"
                                                   class="rounded border-gray-300 text-primary focus:ring-primary h-4 w-4">
                                            <span class="text-xs font-medium text-gray-700">Gắn lớp học?</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Class & Session selector (Conditional) -->
                                <div x-show="item.attach_class" x-cloak class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3 pt-3 border-t border-gray-200 border-dashed">
                                    <div>
                                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Lớp học</label>
                                        <select :name="'tasks[' + index + '][class_id]'" x-model="item.class_id"
                                                class="w-full rounded-lg border-gray-200 text-xs focus:ring-primary focus:border-primary bg-white py-2">
                                            <option value="">-- Chọn lớp học --</option>
                                            @foreach($classes as $c)
                                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-bold uppercase text-gray-500 mb-1">Buổi học</label>
                                        <input type="text" :name="'tasks[' + index + '][session]'" x-model="item.session" placeholder="VD: Buổi 5 - Listening Practice"
                                               class="w-full rounded-lg border-gray-200 text-xs focus:ring-primary focus:border-primary bg-white py-2">
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Add row button -->
                    <button type="button" @click="addTask()" class="mt-4 w-full py-3 border-2 border-dashed border-gray-300 hover:border-primary text-primary rounded-xl font-medium text-xs hover:bg-orange-50/50 transition flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">add</span>
                        Thêm đầu việc
                    </button>
                </div>

                <!-- Submission Footer -->
                <div class="pt-6 border-t border-gray-200 flex flex-col items-center gap-2">
                    <button type="submit" class="bg-[#F5691A] text-white hover:bg-[#d85a15] transition px-8 py-3 rounded-xl font-semibold text-sm shadow-md hover:shadow-lg w-full sm:w-auto min-w-[220px] flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Gửi nhiệm vụ cho Trợ giảng
                    </button>
                    <p class="text-xs text-gray-500 text-center">
                        <span class="font-medium text-amber-700">Khuyến nghị:</span> Gửi trước 15h30 — gửi trễ vẫn được, hệ thống sẽ lưu nhật ký vận hành.
                    </p>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
