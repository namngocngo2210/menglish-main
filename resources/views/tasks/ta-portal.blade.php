<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-orange-100 text-[#F5691A] flex items-center justify-center font-bold text-sm">
                    {{ Str::substr($taUser->name ?? 'TA', 0, 2) }}
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Nhiệm vụ hôm nay</h1>
                    <p class="text-xs text-gray-500">Trợ giảng: <span class="font-semibold text-gray-800">{{ $taUser->name }}</span> ({{ now()->format('d/m/Y') }})</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('tasks.class-reports.create') }}" class="bg-[#F5691A] text-white px-4 py-2 rounded-xl text-xs font-semibold hover:bg-[#d85a15] transition shadow-sm flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">assignment</span>
                    Nộp báo cáo trực lớp
                </a>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto space-y-5" x-data="{
        modalOpen: false,
        selectedTask: null,
        note: '',
        openCompleteModal(task) {
            this.selectedTask = task;
            this.note = '';
            this.modalOpen = true;
        }
    }">

        

        <!-- Accordion 1: Trước giờ học -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-xs" x-data="{ open: true }">
            <button @click="open = !open" class="w-full px-6 py-4 flex items-center justify-between bg-white hover:bg-gray-50/80 transition">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-[#F5691A]">schedule</span>
                    <h2 class="text-base font-bold text-gray-900">Trước giờ học</h2>
                    <span class="text-xs text-gray-500 font-normal">({{ $beforeTasks->count() }} nhiệm vụ)</span>
                </div>
                <span class="material-symbols-outlined text-gray-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''">expand_more</span>
            </button>
            <div x-show="open" x-collapse class="px-5 pb-5 space-y-3">
                @forelse($beforeTasks as $task)
                    <div class="bg-gray-50/60 border border-gray-200 rounded-xl p-4 flex flex-col gap-3 hover:border-gray-300 transition">
                        <div class="flex justify-between items-start gap-2">
                            <div class="space-y-1">
                                <h3 class="font-semibold text-gray-900 text-sm {{ $task->status === 'completed' ? 'line-through text-gray-500' : '' }}">{{ $task->title }}</h3>
                                @if($task->classModel)
                                    <div class="inline-flex items-center gap-1 text-[11px] text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded">
                                        <span class="material-symbols-outlined text-[13px]">school</span>
                                        Trực lớp: {{ $task->classModel->name }} {{ $task->lesson_session ? '· ' . $task->lesson_session : '' }}
                                    </div>
                                @endif
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold border {{ $task->status_badge_class }}">
                                {{ $task->status_label }}
                            </span>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between pt-2 border-t border-gray-100 gap-3">
                            <div class="text-gray-500 text-xs flex items-center gap-1 font-mono">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                Hạn: {{ $task->due_time ?? '14:00' }} Hôm nay
                            </div>

                            @if($task->status === 'new' || $task->status === 'in_progress')
                                <button @click="openCompleteModal({{ json_encode($task) }})" class="bg-[#F5691A] text-white hover:bg-[#d85a15] font-semibold text-xs px-4 py-2 rounded-xl transition flex items-center justify-center gap-1.5 shadow-xs">
                                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                    Hoàn thành
                                </button>
                            @elseif($task->status === 'overdue')
                                <button @click="openCompleteModal({{ json_encode($task) }})" class="bg-rose-600 text-white hover:bg-rose-700 font-semibold text-xs px-4 py-2 rounded-xl transition flex items-center justify-center gap-1.5 shadow-xs">
                                    <span class="material-symbols-outlined text-[16px]">warning</span>
                                    Hoàn thành gấp
                                </button>
                            @elseif($task->status === 'pending_confirmation')
                                <span class="text-orange-700 bg-orange-50 border border-orange-200 px-3 py-1 rounded-lg text-xs font-medium flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[14px]">hourglass_top</span> Đang chờ duyệt
                                </span>
                            @elseif($task->status === 'completed')
                                <span class="text-emerald-700 text-xs font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">done_all</span> Đã hoàn thành
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 italic py-2">Không có nhiệm vụ nào trước giờ học.</p>
                @endforelse
            </div>
        </div>

        <!-- Accordion 2: Trong giờ học -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-xs" x-data="{ open: true }">
            <button @click="open = !open" class="w-full px-6 py-4 flex items-center justify-between bg-white hover:bg-gray-50/80 transition">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-[#F5691A]">play_circle</span>
                    <h2 class="text-base font-bold text-gray-900">Trong giờ học</h2>
                    <span class="text-xs text-gray-500 font-normal">({{ $duringTasks->count() }} nhiệm vụ)</span>
                </div>
                <span class="material-symbols-outlined text-gray-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''">expand_more</span>
            </button>
            <div x-show="open" x-collapse class="px-5 pb-5 space-y-3">
                @forelse($duringTasks as $task)
                    <div class="bg-gray-50/60 border border-gray-200 rounded-xl p-4 flex flex-col gap-3 hover:border-gray-300 transition">
                        <div class="flex justify-between items-start gap-2">
                            <div class="space-y-1">
                                <h3 class="font-semibold text-gray-900 text-sm {{ $task->status === 'completed' ? 'line-through text-gray-500' : '' }}">{{ $task->title }}</h3>
                                @if($task->classModel)
                                    <div class="inline-flex items-center gap-1 text-[11px] text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded">
                                        <span class="material-symbols-outlined text-[13px]">school</span>
                                        Trực lớp: {{ $task->classModel->name }} {{ $task->lesson_session ? '· ' . $task->lesson_session : '' }}
                                    </div>
                                @endif
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold border {{ $task->status_badge_class }}">
                                {{ $task->status_label }}
                            </span>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between pt-2 border-t border-gray-100 gap-3">
                            <div class="text-gray-500 text-xs flex items-center gap-1 font-mono">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                Hạn: {{ $task->due_time ?? '18:00' }} Hôm nay
                            </div>

                            <div class="flex items-center gap-2">
                                @if($task->classModel)
                                    <a href="{{ route('tasks.class-reports.create', ['task_id' => $task->id, 'class_id' => $task->class_id]) }}" class="border border-gray-300 text-gray-800 hover:bg-gray-100 font-semibold text-xs px-3 py-2 rounded-xl transition flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-[16px]">assignment</span>
                                        Nộp báo cáo trực lớp
                                    </a>
                                @endif

                                @if($task->status === 'new' || $task->status === 'in_progress')
                                    <button @click="openCompleteModal({{ json_encode($task) }})" class="bg-[#F5691A] text-white hover:bg-[#d85a15] font-semibold text-xs px-4 py-2 rounded-xl transition flex items-center gap-1.5 shadow-xs">
                                        <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                        Hoàn thành
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 italic py-2">Không có nhiệm vụ nào trong giờ học.</p>
                @endforelse
            </div>
        </div>

        <!-- Accordion 3: Sau giờ học -->
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-xs" x-data="{ open: true }">
            <button @click="open = !open" class="w-full px-6 py-4 flex items-center justify-between bg-white hover:bg-gray-50/80 transition">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-[#F5691A]">task_alt</span>
                    <h2 class="text-base font-bold text-gray-900">Sau giờ học</h2>
                    <span class="text-xs text-gray-500 font-normal">({{ $afterTasks->count() }} nhiệm vụ)</span>
                </div>
                <span class="material-symbols-outlined text-gray-400 transition-transform duration-200" :class="open ? 'rotate-180' : ''">expand_more</span>
            </button>
            <div x-show="open" x-collapse class="px-5 pb-5 space-y-3">
                @forelse($afterTasks as $task)
                    <div class="bg-gray-50/60 border border-gray-200 rounded-xl p-4 flex flex-col gap-3 hover:border-gray-300 transition {{ $task->status === 'completed' ? 'opacity-70' : '' }}">
                        <div class="flex justify-between items-start gap-2">
                            <div class="space-y-1">
                                <h3 class="font-semibold text-gray-900 text-sm {{ $task->status === 'completed' ? 'line-through text-gray-500' : '' }}">{{ $task->title }}</h3>
                                @if($task->classModel)
                                    <div class="inline-flex items-center gap-1 text-[11px] text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded">
                                        <span class="material-symbols-outlined text-[13px]">school</span>
                                        Trực lớp: {{ $task->classModel->name }}
                                    </div>
                                @endif
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold border {{ $task->status_badge_class }}">
                                {{ $task->status_label }}
                            </span>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between pt-2 border-t border-gray-100 gap-3">
                            <div class="text-gray-500 text-xs flex items-center gap-1 font-mono">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                Hạn: {{ $task->due_time ?? '21:30' }} Hôm nay
                            </div>

                            @if($task->status === 'new' || $task->status === 'in_progress')
                                <button @click="openCompleteModal({{ json_encode($task) }})" class="bg-[#F5691A] text-white hover:bg-[#d85a15] font-semibold text-xs px-4 py-2 rounded-xl transition flex items-center justify-center gap-1.5 shadow-xs">
                                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                    Hoàn thành
                                </button>
                            @elseif($task->status === 'completed')
                                <span class="text-emerald-700 text-xs font-semibold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">done_all</span> Đã hoàn thành lúc {{ $task->completed_at ? $task->completed_at->format('H:i') : '' }}
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400 italic py-2">Không có nhiệm vụ nào sau giờ học.</p>
                @endforelse
            </div>
        </div>

        <!-- ========================================== -->
        <!-- MODAL: CẬP NHẬT TIẾN ĐỘ / HOÀN THÀNH -->
        <!-- ========================================== -->
        <div x-show="modalOpen" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
            <div @click.outside="modalOpen = false" class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden flex flex-col max-h-[90vh]">
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                    <h2 class="text-base font-bold text-gray-900">Cập nhật tiến độ hoàn thành</h2>
                    <button @click="modalOpen = false" class="text-gray-400 hover:text-gray-700 p-1 rounded-full">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>

                <form :action="'/tasks/' + (selectedTask ? selectedTask.id : '') + '/complete'" method="POST" enctype="multipart/form-data" class="p-6 space-y-4 text-sm overflow-y-auto">
                    @csrf

                    <div class="bg-gray-50 p-3 rounded-xl border border-gray-200">
                        <h4 class="font-bold text-gray-900 text-xs mb-0.5" x-text="selectedTask ? selectedTask.title : ''"></h4>
                        <p class="text-[11px] text-gray-500" x-text="'Hạn chót: ' + (selectedTask ? selectedTask.due_time : '') + ', Hôm nay'"></p>
                    </div>

                    <!-- Upload Proof Image -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-700 mb-1.5">Bằng chứng hình ảnh (Tùy chọn)</label>
                        <div class="border-2 border-dashed border-gray-300 rounded-xl p-5 flex flex-col items-center justify-center bg-gray-50/50 hover:bg-gray-50 transition cursor-pointer relative">
                            <span class="material-symbols-outlined text-3xl text-gray-400 mb-1">cloud_upload</span>
                            <p class="text-xs font-semibold text-gray-800">Nhấn để chọn ảnh hoặc kéo thả</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">PNG, JPG tối đa 5MB</p>
                            <input type="file" name="proof_image" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer">
                        </div>
                    </div>

                    <!-- Note / Notification banner -->
                    <div class="bg-blue-50 text-blue-900 border border-blue-200 p-3 rounded-xl flex items-start gap-2.5 text-xs">
                        <span class="material-symbols-outlined text-[18px] text-blue-600 shrink-0 mt-0.5">info</span>
                        <div>
                            <strong>Quy tắc hệ thống:</strong> Có ảnh đính kèm, nhiệm vụ sẽ được <strong class="text-emerald-700">hoàn thành ngay</strong>. Nếu không có ảnh, nhiệm vụ chuyển sang <strong class="text-orange-700">chờ người giao việc xác nhận</strong>.
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-700 mb-1.5" for="note">Ghi chú (Tùy chọn)</label>
                        <textarea name="note" id="note" x-model="note" rows="3" placeholder="Nhập ghi chú kết quả hoặc vấn đề phát sinh..."
                                  class="w-full rounded-xl border-gray-200 text-xs focus:ring-primary focus:border-primary"></textarea>
                    </div>

                    <div class="pt-2 flex justify-end gap-3 border-t border-gray-100">
                        <button type="button" @click="modalOpen = false" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 font-medium text-xs">
                            Hủy bỏ
                        </button>
                        <button type="submit" class="px-5 py-2 bg-[#F5691A] text-white rounded-xl hover:bg-[#d85a15] font-semibold text-xs shadow-sm flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">send</span>
                            Xác nhận nộp
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</x-app-layout>
