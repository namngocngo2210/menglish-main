<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Xác nhận hoàn thành thủ công</h1>
                <p class="text-sm text-gray-500 mt-0.5">Danh sách các đầu việc và báo cáo chờ xác nhận hoàn thành từ Trợ giảng / Giảng viên</p>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        

        @if(session('info'))
            <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl flex items-center gap-3">
                <span class="material-symbols-outlined text-amber-600">info</span>
                <span class="font-medium text-sm">{{ session('info') }}</span>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <!-- Left List Panel (2 cols) -->
            <section class="lg:col-span-2 bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                <!-- List Header -->
                <div class="hidden md:grid grid-cols-12 gap-4 p-4 bg-gray-50 border-b border-gray-200 text-[11px] font-bold text-gray-500 uppercase tracking-wider">
                    <div class="col-span-5">Đầu việc</div>
                    <div class="col-span-3">Trợ giảng</div>
                    <div class="col-span-2">Hạn / Ngày</div>
                    <div class="col-span-2 text-right">Thao tác</div>
                </div>

                <!-- List Items -->
                <div class="divide-y divide-gray-100">
                    @forelse($pendingTasks as $task)
                        @php
                            $isSelected = $selectedTask && $selectedTask->id === $task->id;
                        @endphp
                        <div onclick="window.location.href='{{ route('tasks.manual-approvals', ['selected_id' => $task->id]) }}'"
                             class="grid grid-cols-1 md:grid-cols-12 gap-4 p-4 items-center cursor-pointer transition relative {{ $isSelected ? 'bg-orange-50/50 border-l-4 border-[#F5691A]' : 'hover:bg-gray-50/80' }}">
                            <div class="col-span-1 md:col-span-5 space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="bg-orange-100 text-orange-800 text-[10px] font-bold px-2 py-0.5 rounded-full border border-orange-200">
                                        Chờ xác nhận
                                    </span>
                                    <h3 class="font-semibold text-gray-900 text-xs line-clamp-1 hover:text-primary transition">
                                        {{ $task->title }}
                                    </h3>
                                </div>
                                @if($task->classModel)
                                    <div class="text-gray-500 text-xs flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px]">school</span>
                                        {{ $task->classModel->name }}
                                    </div>
                                @endif
                            </div>

                            <div class="col-span-1 md:col-span-3 flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ Str::substr($task->assignee?->name ?? 'TA', 0, 2) }}
                                </div>
                                <span class="text-gray-900 text-xs font-medium truncate">{{ $task->assignee?->name ?? 'Chưa phân công' }}</span>
                            </div>

                            <div class="col-span-1 md:col-span-2 text-gray-500 text-xs font-mono">
                                {{ $task->due_date ? $task->due_date->format('d/m/Y') : now()->format('d/m/Y') }}
                            </div>

                            <div class="col-span-1 md:col-span-2 flex justify-end" onclick="event.stopPropagation()">
                                <form action="{{ route('tasks.approve', $task->id) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="bg-[#F5691A] text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-[#d85a15] transition shadow-xs">
                                        Xác nhận
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="p-12 text-center text-gray-400">
                            <span class="material-symbols-outlined text-4xl mb-2 text-gray-300">task_alt</span>
                            <p class="font-medium text-sm text-gray-600">Không có đầu việc nào cần xác nhận thủ công.</p>
                            <p class="text-xs text-gray-400 mt-1">Tất cả các báo cáo đã được xử lý hoặc hoàn thành tự động.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <!-- Right Detail Panel (1 col) -->
            <section class="lg:col-span-1 sticky top-6">
                @if($selectedTask)
                    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                        <!-- Detail Header -->
                        <div class="p-5 border-b border-gray-100 bg-gray-50/70">
                            <div class="flex items-center justify-between mb-2">
                                <span class="bg-orange-100 text-orange-800 text-[10px] font-bold px-2 py-0.5 rounded-full border border-orange-200">
                                    Chờ xác nhận
                                </span>
                                <span class="text-xs text-gray-400 font-mono">ID #{{ $selectedTask->id }}</span>
                            </div>
                            <h2 class="text-base font-bold text-gray-900">{{ $selectedTask->title }}</h2>
                            @if($selectedTask->classModel)
                                <div class="text-xs text-blue-700 font-medium flex items-center gap-1 mt-1">
                                    <span class="material-symbols-outlined text-[14px]">school</span>
                                    {{ $selectedTask->classModel->name }} {{ $selectedTask->lesson_session ? '· ' . $selectedTask->lesson_session : '' }}
                                </div>
                            @endif
                        </div>

                        <!-- Detail Body -->
                        <div class="p-5 space-y-4 text-xs">
                            <!-- Info Block -->
                            <div class="bg-gray-50 rounded-xl p-3 border border-gray-200 space-y-2">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-500">Người thực hiện:</span>
                                    <span class="font-bold text-gray-900">{{ $selectedTask->assignee?->name ?? '—' }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-500">Người giao việc:</span>
                                    <span class="font-medium text-gray-800">{{ $selectedTask->creator?->name ?? 'Admin' }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-500">Hạn chót:</span>
                                    <span class="font-mono font-bold text-rose-600">
                                        {{ $selectedTask->due_date ? $selectedTask->due_date->format('d/m/Y') : '' }} {{ $selectedTask->due_time ?? '23:59' }}
                                    </span>
                                </div>
                            </div>

                            <!-- Report Block -->
                            <div>
                                <h4 class="font-bold text-gray-700 uppercase text-[11px] mb-1.5 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[14px] text-amber-500">report</span>
                                    Báo cáo từ Trợ giảng
                                </h4>
                                <div class="bg-orange-50/50 rounded-xl p-3.5 border border-orange-200 text-gray-800 space-y-2">
                                    <p class="leading-relaxed whitespace-pre-line text-xs">
                                        {{ $selectedTask->completion_note ?: 'Trợ giảng đã báo cáo hoàn thành nhiệm vụ này và đang chờ xét duyệt.' }}
                                    </p>

                                    @if(str_contains($selectedTask->completion_note, 'http'))
                                        <div class="p-2 bg-white rounded-lg border border-orange-200 flex items-center gap-2">
                                            <span class="material-symbols-outlined text-primary text-[16px]">link</span>
                                            <a href="#" class="text-blue-600 hover:underline truncate text-xs font-medium">Link tài liệu / Drive đính kèm</a>
                                        </div>
                                    @endif

                                    @if(!$selectedTask->completion_proof_image)
                                        <div class="text-[11px] text-rose-700 bg-rose-50 border border-rose-200 rounded-lg p-2 flex items-start gap-1.5">
                                            <span class="material-symbols-outlined text-[14px] text-rose-600 shrink-0 mt-0.5">info</span>
                                            <span><strong>Ghi chú hệ thống:</strong> TA báo cáo hoàn thành nhưng không đính kèm hình ảnh minh chứng trực tiếp lên hệ thống.</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Admin Note Form -->
                            <form id="approvalForm" action="{{ route('tasks.approve', $selectedTask->id) }}" method="POST" class="space-y-3">
                                @csrf
                                <div>
                                    <label class="block font-bold text-gray-700 uppercase text-[11px] mb-1" for="admin_note">Ghi chú xác nhận (Tùy chọn)</label>
                                    <textarea name="admin_note" id="admin_note" rows="2" placeholder="Nhập ghi chú hoặc phản hồi cho TA..."
                                              class="w-full rounded-xl border-gray-200 text-xs focus:ring-primary focus:border-primary"></textarea>
                                </div>
                            </form>
                        </div>

                        <!-- Detail Actions -->
                        <div class="p-5 border-t border-gray-100 bg-gray-50 flex flex-col gap-3">
                            <button type="submit" form="approvalForm" class="w-full bg-[#F5691A] text-white hover:bg-[#d85a15] font-bold text-xs py-3 rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                Xác nhận hoàn thành
                            </button>

                            <div x-data="{ openReject: false }" class="w-full">
                                <button type="button" @click="openReject = !openReject" class="w-full bg-white border border-gray-300 text-gray-700 hover:bg-gray-100 font-semibold text-xs py-2.5 rounded-xl transition flex items-center justify-center gap-1.5">
                                    <span class="material-symbols-outlined text-[16px] text-rose-500">cancel</span>
                                    <span>Từ chối / Yêu cầu bổ sung</span>
                                </button>

                                <div x-show="openReject" x-cloak class="mt-2 p-3 bg-rose-50/70 border border-rose-200 rounded-xl space-y-2">
                                    <form action="{{ route('tasks.reject', $selectedTask->id) }}" method="POST" class="space-y-2">
                                        @csrf
                                        <label for="reject_reason_{{ $selectedTask->id }}" class="block text-[11px] font-bold text-rose-800 uppercase">
                                            Lý do từ chối / Yêu cầu hoàn thiện lại <span class="text-rose-600">*</span>
                                        </label>
                                        <textarea id="reject_reason_{{ $selectedTask->id }}" name="admin_note" rows="2" required placeholder="Nhập chi tiết lý do từ chối (ví dụ: thiếu ảnh lớp, thông tin chưa chính xác...) để nhân sự làm lại..." class="w-full text-xs rounded-xl border-rose-300 focus:border-rose-500 focus:ring-rose-500 bg-white p-2"></textarea>
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" @click="openReject = false" class="px-3 py-1.5 bg-white border border-gray-300 text-gray-600 text-xs rounded-lg hover:bg-gray-50">Hủy</button>
                                            <button type="submit" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-lg flex items-center gap-1 shadow-xs">
                                                <span class="material-symbols-outlined text-[14px]">send</span>
                                                Gửi yêu cầu làm lại
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="bg-white border border-gray-200 rounded-2xl p-8 text-center text-gray-400 shadow-sm">
                        <span class="material-symbols-outlined text-4xl mb-2 text-gray-300">touch_app</span>
                        <p class="font-medium text-xs text-gray-600">Chọn một công việc bên trái để xem chi tiết và xác nhận.</p>
                    </div>
                @endif
            </section>
        </div>

    </div>
</x-app-layout>
