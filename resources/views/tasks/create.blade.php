<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('tasks.index') }}" class="p-2 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-gray-900 transition">
                    <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Giao việc mới</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Tạo và phân công nhiệm vụ cho nhân sự, giảng viên hoặc trợ giảng</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 sm:p-8" x-data="{ isRecurring: false }">
            <form action="{{ route('tasks.store') }}" method="POST" class="space-y-6 text-sm">
                @csrf

                <!-- Tiêu đề công việc -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1.5" for="taskTitle">
                        Tiêu đề công việc <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="taskTitle" name="taskTitle" required placeholder="Nhập tiêu đề công việc..."
                           class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-3" value="{{ old('taskTitle') }}">
                    <x-input-error :messages="$errors->get('taskTitle')" class="mt-1" />
                </div>

                <!-- Mô tả chi tiết -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 uppercase mb-1.5" for="taskDescription">
                        Mô tả chi tiết
                    </label>
                    <textarea id="taskDescription" name="taskDescription" rows="4" placeholder="Mô tả nội dung công việc chi tiết..."
                              class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-3">{{ old('taskDescription') }}</textarea>
                    <x-input-error :messages="$errors->get('taskDescription')" class="mt-1" />
                </div>

                <!-- Người nhận & Hạn hoàn thành -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1.5" for="assignee">
                            Người nhận <span class="text-rose-500">*</span>
                        </label>
                        <select id="assignee" name="assignee" required class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-3">
                            <option value="" disabled selected>-- Chọn nhân sự --</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" @selected((string) old('assignee') === (string) $u->id)>{{ $u->name }} ({{ $u->getRoleNames()->implode(', ') ?: 'Nhân viên' }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('assignee')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1.5" for="dueDate">
                            Hạn hoàn thành <span class="text-rose-500">*</span>
                        </label>
                        <input type="date" id="dueDate" name="dueDate" required value="{{ old('dueDate', now()->addDays(2)->format('Y-m-d')) }}"
                               class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-3">
                        <x-input-error :messages="$errors->get('dueDate')" class="mt-1" />
                    </div>
                </div>

                <!-- Chi nhánh & Gắn lớp (Tùy chọn) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1.5" for="branch_id">
                            Chi nhánh
                        </label>
                        <select id="branch_id" name="branch_id" class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-3">
                            <option value="">-- Không chỉ định --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected((string) old('branch_id') === (string) $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('branch_id')" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1.5" for="class_id">
                            Gắn lớp (Nếu có)
                        </label>
                        <select id="class_id" name="class_id" class="w-full rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-3">
                            <option value="">-- Không gắn lớp --</option>
                            @foreach($classes as $c)
                                <option value="{{ $c->id }}" @selected((string) old('class_id') === (string) $c->id)>{{ $c->name }} ({{ $c->code }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('class_id')" class="mt-1" />
                    </div>
                </div>

                <!-- Loại công việc & Tần suất -->
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 space-y-4">
                    <span class="block text-xs font-semibold text-gray-700 uppercase">Loại công việc</span>
                    <div class="flex items-center gap-8">
                        <label class="inline-flex items-center gap-2.5 cursor-pointer">
                            <input type="radio" name="taskType" value="one-time" checked @change="isRecurring = false" class="text-primary focus:ring-primary-container h-4 w-4">
                            <span class="text-sm font-medium text-gray-800">Phát sinh (Một lần)</span>
                        </label>
                        <label class="inline-flex items-center gap-2.5 cursor-pointer">
                            <input type="radio" name="taskType" value="recurring" @change="isRecurring = true" class="text-primary focus:ring-primary-container h-4 w-4">
                            <span class="text-sm font-medium text-gray-800">Lặp đi lặp lại</span>
                        </label>
                    </div>

                    <!-- Tần suất -->
                    <div x-show="isRecurring" x-cloak class="pt-3 border-t border-gray-200">
                        <label class="block text-xs font-semibold text-gray-600 uppercase mb-1.5" for="frequency">Tần suất lặp lại</label>
                        <select id="frequency" name="frequency" class="w-full sm:w-1/2 rounded-xl border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container p-2.5">
                            <option value="daily">Hàng ngày</option>
                            <option value="weekly" selected>Hàng tuần</option>
                            <option value="monthly">Hàng tháng</option>
                        </select>
                        <x-input-error :messages="$errors->get('frequency')" class="mt-1" />
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 flex items-center justify-end gap-3">
                    <a href="{{ route('tasks.index') }}" class="px-5 py-2.5 bg-white border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 font-medium text-sm transition">
                        Hủy
                    </a>
                    <button type="submit" class="px-6 py-2.5 bg-primary-container text-white rounded-xl hover:bg-primary font-medium text-sm flex items-center gap-2 shadow-sm transition">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Lưu và Giao việc
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
