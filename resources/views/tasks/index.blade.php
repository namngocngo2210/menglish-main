<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Danh sách công việc</h1>
                <p class="text-sm text-gray-500 mt-0.5">Quản lý, phân công và theo dõi tiến độ công việc toàn diện</p>
            </div>
            <div class="flex items-center gap-3">
                <button @click="$dispatch('open-create-task-modal')" class="bg-primary-container text-white hover:bg-primary transition-colors px-4 py-2 rounded-lg font-medium text-sm flex items-center gap-2 shadow-sm">
                    <span class="material-symbols-outlined text-[20px]">add</span>
                    Giao việc
                </button>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" x-data="{
        createModalOpen: false,
        statusModalOpen: false,
        currentTask: null,
        newStatus: 'blocked',
        statusReason: '',
        openStatusModal(task, status = 'blocked') {
            this.currentTask = task;
            this.newStatus = status;
            this.statusReason = task.blocked_reason || task.rejection_reason || '';
            this.statusModalOpen = true;
        }
    }" @open-create-task-modal.window="createModalOpen = true">

        

        @if(session('info'))
            <div class="p-4 bg-blue-50 border border-blue-200 text-blue-800 rounded-xl flex items-center gap-3">
                <span class="material-symbols-outlined text-blue-600">info</span>
                <span class="font-medium text-sm">{{ session('info') }}</span>
            </div>
        @endif

        <!-- Quick Summary Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Tất cả công việc</p>
                    <h3 class="text-2xl font-bold text-gray-900 mt-1">{{ $counts['all'] }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center text-gray-600">
                    <span class="material-symbols-outlined">assignment</span>
                </div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Việc của tôi</p>
                    <h3 class="text-2xl font-bold text-gray-900 mt-1">{{ $counts['mine'] }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-orange-50 flex items-center justify-center text-primary">
                    <span class="material-symbols-outlined">person</span>
                </div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Chờ xác nhận</p>
                    <h3 class="text-2xl font-bold text-orange-600 mt-1">{{ $counts['pending'] }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-orange-100 flex items-center justify-center text-orange-600">
                    <span class="material-symbols-outlined">pending_actions</span>
                </div>
            </div>
            <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">Quá hạn</p>
                    <h3 class="text-2xl font-bold text-rose-600 mt-1">{{ $counts['overdue'] }}</h3>
                </div>
                <div class="w-10 h-10 rounded-xl bg-rose-50 flex items-center justify-center text-rose-600">
                    <span class="material-symbols-outlined">warning</span>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs & Search/Filter bar -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 space-y-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between border-b border-gray-200 pb-3 gap-4">
                <!-- Tab bar -->
                <div class="flex items-center gap-6 overflow-x-auto">
                    <a href="{{ route('tasks.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                       class="pb-2 text-sm font-semibold transition border-b-2 {{ $tab === 'all' ? 'text-primary border-primary-container' : 'text-gray-500 border-transparent hover:text-gray-900' }}">
                        Tất cả <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{{ $counts['all'] }}</span>
                    </a>
                    <a href="{{ route('tasks.index', array_merge(request()->query(), ['tab' => 'mine'])) }}"
                       class="pb-2 text-sm font-semibold transition border-b-2 {{ $tab === 'mine' ? 'text-primary border-primary-container' : 'text-gray-500 border-transparent hover:text-gray-900' }}">
                        Của tôi <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{{ $counts['mine'] }}</span>
                    </a>
                    <a href="{{ route('tasks.index', array_merge(request()->query(), ['tab' => 'assigned'])) }}"
                       class="pb-2 text-sm font-semibold transition border-b-2 {{ $tab === 'assigned' ? 'text-primary border-primary-container' : 'text-gray-500 border-transparent hover:text-gray-900' }}">
                        Tôi giao <span class="ml-1 px-1.5 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{{ $counts['assigned'] }}</span>
                    </a>
                </div>

                <!-- Search form -->
                <form method="GET" action="{{ route('tasks.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <div class="relative w-full sm:w-64">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-[18px]">search</span>
                        <input type="text" name="q" value="{{ $search }}" placeholder="Tìm công việc, nhân sự..."
                               class="w-full bg-gray-50 border border-gray-200 text-gray-900 text-xs rounded-lg pl-9 pr-3 py-2 focus:ring-1 focus:ring-primary-container focus:border-primary-container">
                    </div>
                    @if(!empty($search))
                        <a href="{{ route('tasks.index', ['tab' => $tab]) }}" class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100">
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </a>
                    @endif
                </form>
            </div>

            <!-- Filter Status Chips -->
            <div class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                <span class="text-gray-500 font-medium mr-1">Trạng thái:</span>
                @php
                    $statuses = [
                        'all' => 'Tất cả',
                        'overdue' => 'Quá hạn',
                        'blocked' => 'Bị chặn',
                        'pending_confirmation' => 'Chờ xác nhận',
                        'in_progress' => 'Đang thực hiện',
                        'new' => 'Mới',
                        'completed' => 'Hoàn thành',
                        'canceled' => 'Đã hủy',
                    ];
                @endphp
                @foreach($statuses as $stKey => $stLabel)
                    <a href="{{ route('tasks.index', array_merge(request()->query(), ['status' => $stKey])) }}"
                       class="px-2.5 py-1 rounded-full border transition {{ $status === $stKey ? 'bg-primary-container text-white border-primary-container font-semibold' : 'bg-gray-50 text-gray-700 border-gray-200 hover:bg-gray-100' }}">
                        {{ $stLabel }}
                    </a>
                @endforeach
            </div>
        </div>

        <!-- Data Table Container -->
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-[11px] font-semibold tracking-wider border-b border-gray-200">
                        <tr>
                            <th class="p-4 whitespace-nowrap">Tiêu đề</th>
                            <th class="p-4 whitespace-nowrap">Loại</th>
                            <th class="p-4 whitespace-nowrap">Người nhận</th>
                            <th class="p-4 whitespace-nowrap">Hạn hoàn thành</th>
                            <th class="p-4 whitespace-nowrap">Trạng thái</th>
                            <th class="p-4 text-right whitespace-nowrap">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($tasks as $task)
                            <tr class="hover:bg-gray-50/80 transition-colors {{ $task->status === 'overdue' ? 'bg-rose-50/30' : ($task->status === 'canceled' ? 'opacity-60 bg-gray-50/50' : '') }}">
                                <td class="p-4">
                                    <div class="font-medium text-gray-900 {{ $task->status === 'canceled' ? 'line-through text-gray-500' : '' }}">
                                        {{ $task->title }}
                                    </div>
                                    @if($task->description)
                                        <div class="text-xs text-gray-500 mt-0.5 line-clamp-1 {{ $task->status === 'canceled' ? 'line-through' : '' }}">
                                            {{ $task->description }}
                                        </div>
                                    @endif
                                    @if($task->classModel)
                                        <div class="inline-flex items-center gap-1 text-[11px] text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded mt-1.5">
                                            <span class="material-symbols-outlined text-[13px]">school</span>
                                            {{ $task->classModel->name }} {{ $task->lesson_session ? '· ' . $task->lesson_session : '' }}
                                        </div>
                                    @endif
                                    @if($task->blocked_reason)
                                        <div class="text-xs text-rose-700 bg-rose-50 border border-rose-200 rounded px-2 py-1 mt-1.5 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[14px]">block</span>
                                            Lý do chặn: {{ $task->blocked_reason }}
                                        </div>
                                    @endif
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs border {{ $task->task_type === 'one_time' ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-purple-50 text-purple-700 border-purple-200' }}">
                                        {{ $task->task_type_label }}
                                        @if($task->frequency)
                                            ({{ match($task->frequency) { 'daily' => 'Hàng ngày', 'weekly' => 'Hàng tuần', 'monthly' => 'Hàng tháng', default => $task->frequency } }})
                                        @endif
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-7 h-7 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold text-xs">
                                            {{ Str::substr($task->assignee?->name ?? 'U', 0, 1) }}
                                        </div>
                                        <span class="text-gray-900 font-medium text-xs">{{ $task->assignee?->name ?? 'Chưa phân công' }}</span>
                                    </div>
                                </td>
                                <td class="p-4 font-mono text-xs {{ $task->status === 'overdue' ? 'text-rose-600 font-bold' : 'text-gray-600' }}">
                                    {{ $task->due_date ? $task->due_date->format('d/m/Y') : '—' }}
                                    @if($task->due_time)
                                        <span class="text-[11px] text-gray-400 block">{{ $task->due_time }}</span>
                                    @endif
                                </td>
                                <td class="p-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $task->status_badge_class }}">
                                        {{ $task->status_label }}
                                    </span>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="relative inline-block text-left" x-data="{ open: false }">
                                        <button @click="open = !open" @click.outside="open = false" class="text-gray-500 hover:text-gray-700 p-1 rounded-full hover:bg-gray-100 transition">
                                            <span class="material-symbols-outlined text-[20px]">more_vert</span>
                                        </button>
                                        <div x-show="open" x-cloak class="origin-top-right absolute right-0 mt-2 w-48 rounded-xl shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-20 divide-y divide-gray-100 py-1">
                                            <button @click="openStatusModal({{ json_encode($task) }}, 'in_progress'); open = false;" class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-gray-50 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-[16px] text-amber-500">play_arrow</span> Đang thực hiện
                                            </button>
                                            <button @click="openStatusModal({{ json_encode($task) }}, 'blocked'); open = false;" class="w-full text-left px-4 py-2 text-xs text-rose-700 hover:bg-rose-50 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-[16px] text-rose-500">block</span> Báo Bị chặn
                                            </button>
                                            <button @click="openStatusModal({{ json_encode($task) }}, 'completed'); open = false;" class="w-full text-left px-4 py-2 text-xs text-emerald-700 hover:bg-emerald-50 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-[16px] text-emerald-500">check_circle</span> Đánh dấu Hoàn thành
                                            </button>
                                            <button @click="openStatusModal({{ json_encode($task) }}, 'canceled'); open = false;" class="w-full text-left px-4 py-2 text-xs text-gray-500 hover:bg-gray-50 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-[16px] text-gray-400">cancel</span> Hủy công việc
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-8 text-center text-gray-500">
                                    <span class="material-symbols-outlined text-4xl text-gray-300 mb-2">assignment_late</span>
                                    <p class="font-medium text-sm">Không tìm thấy công việc nào phù hợp.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <x-pagination :paginator="$tasks" />
        </div>

        <!-- ========================================== -->
        <!-- MODAL: GIAO VIỆC MỚI -->
        <!-- ========================================== -->
        <div x-show="createModalOpen" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
            <div @click.outside="createModalOpen = false" class="bg-white rounded-2xl shadow-xl w-full max-w-xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                    <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">add_task</span>
                        Giao việc mới
                    </h2>
                    <button @click="createModalOpen = false" class="text-gray-400 hover:text-gray-700 p-1 rounded-full hover:bg-gray-100">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>

                <!-- Modal Body -->
                <form id="createTaskForm" action="{{ route('tasks.store') }}" method="POST" class="p-6 overflow-y-auto space-y-4 text-sm">
                    @csrf
                    <!-- Tiêu đề công việc -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="taskTitle">
                            Tiêu đề công việc <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" id="taskTitle" name="taskTitle" required placeholder="Nhập tiêu đề công việc..."
                               class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                    </div>

                    <!-- Mô tả chi tiết -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="taskDescription">
                            Mô tả chi tiết
                        </label>
                        <textarea id="taskDescription" name="taskDescription" rows="3" placeholder="Mô tả nội dung công việc chi tiết..."
                                  class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container"></textarea>
                    </div>

                    <!-- Người nhận & Hạn hoàn thành -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="assignee">
                                Người nhận <span class="text-rose-500">*</span>
                            </label>
                            <select id="assignee" name="assignee" required class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                                <option value="" disabled selected>-- Chọn nhân sự --</option>
                                @foreach($users as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->getRoleNames()->implode(', ') ?: 'Nhân viên' }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="dueDate">
                                Hạn hoàn thành <span class="text-rose-500">*</span>
                            </label>
                            <input type="date" id="dueDate" name="dueDate" required value="{{ now()->addDays(2)->format('Y-m-d') }}"
                                   class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                        </div>
                    </div>

                    <!-- Chi nhánh & Gắn lớp (Tùy chọn) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="branch_id">
                                Chi nhánh
                            </label>
                            <select id="branch_id" name="branch_id" class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                                <option value="">-- Không chỉ định --</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="class_id">
                                Gắn lớp (Nếu có)
                            </label>
                            <select id="class_id" name="class_id" class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                                <option value="">-- Không gắn lớp --</option>
                                @foreach($classes as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Loại công việc & Tần suất -->
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-3" x-data="{ isRecurring: false }">
                        <span class="block text-xs font-semibold text-gray-700 uppercase">Loại công việc</span>
                        <div class="flex items-center gap-6">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="taskType" value="one-time" checked @change="isRecurring = false" class="text-primary focus:ring-primary-container">
                                <span class="text-sm text-gray-800">Phát sinh</span>
                            </label>
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="taskType" value="recurring" @change="isRecurring = true" class="text-primary focus:ring-primary-container">
                                <span class="text-sm text-gray-800">Lặp đi lặp lại</span>
                            </label>
                        </div>

                        <!-- Tần suất -->
                        <div x-show="isRecurring" x-cloak class="pt-2 border-t border-gray-200">
                            <label class="block text-xs font-semibold text-gray-600 uppercase mb-1" for="frequency">Tần suất</label>
                            <select id="frequency" name="frequency" class="w-full sm:w-1/2 rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                                <option value="daily">Hàng ngày</option>
                                <option value="weekly" selected>Hàng tuần</option>
                                <option value="monthly">Hàng tháng</option>
                            </select>
                        </div>
                    </div>
                </form>

                <!-- Modal Footer -->
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
                    <button type="button" @click="createModalOpen = false" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 font-medium text-sm">
                        Hủy
                    </button>
                    <button type="submit" form="createTaskForm" class="px-5 py-2 bg-primary-container text-white rounded-lg hover:bg-primary font-medium text-sm flex items-center gap-2 shadow-sm">
                        <span class="material-symbols-outlined text-[18px]">send</span>
                        Lưu và Giao việc
                    </button>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- MODAL: THAY ĐỔI TRẠNG THÁI -->
        <!-- ========================================== -->
        <div x-show="statusModalOpen" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
            <div @click.outside="statusModalOpen = false" class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                    <h2 class="text-base font-bold text-gray-900">Thay đổi trạng thái</h2>
                    <button @click="statusModalOpen = false" class="text-gray-400 hover:text-gray-700 p-1 rounded-full">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>

                <form :action="'/tasks/' + (currentTask ? currentTask.id : '') + '/status'" method="POST" class="p-6 space-y-4 text-sm">
                    @csrf
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Công việc</label>
                        <p class="font-medium text-gray-900" x-text="currentTask ? currentTask.title : ''"></p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1">Trạng thái mới</label>
                        <select name="status" x-model="newStatus" class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container">
                            <option value="new">Mới</option>
                            <option value="in_progress">Đang thực hiện</option>
                            <option value="pending_confirmation">Chờ xác nhận</option>
                            <option value="blocked">Bị chặn</option>
                            <option value="completed">Hoàn thành</option>
                            <option value="canceled">Đã hủy</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-700 uppercase mb-1" for="reason">Ghi chú lý do / Kết quả</label>
                        <textarea name="reason" id="reason" x-model="statusReason" rows="3" placeholder="Nhập lý do chi tiết hoặc kết quả..."
                                  class="w-full rounded-lg border-gray-200 text-sm focus:border-primary-container focus:ring-primary-container"></textarea>
                    </div>

                    <div class="pt-2 flex justify-end gap-3">
                        <button type="button" @click="statusModalOpen = false" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 font-medium text-xs">
                            Hủy
                        </button>
                        <button type="submit" class="px-5 py-2 bg-primary-container text-white rounded-lg hover:bg-primary-tint font-medium text-xs shadow-sm">
                            Xác nhận cập nhật
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</x-app-layout>
