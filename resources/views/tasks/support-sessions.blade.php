<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-bold">Danh sách bổ trợ & xếp lịch phụ đạo</h1>
            <p class="text-xs text-gray-500">Học viên vắng học, điểm mini test / Big Test dưới 7 được tự đưa vào danh sách; Học vụ xếp buổi bổ trợ, buổi hoàn thành chuyển bảng công chờ duyệt.</p>
        </div>
    </x-slot>

    @php
        $sourceColors = ['attendance' => 'warning', 'mini_test' => 'info', 'big_test' => 'error', 'class_report' => 'neutral'];
        $selectedClassId = old('class_id', $selectedSupport?->resolvedClassId());
        $selectedStudentId = old('student_id', $selectedSupport?->student_id);
    @endphp

    <div class="space-y-6">
        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <div class="grid lg:grid-cols-3 gap-6">
            @can('work_task.assign')
                <form method="POST" action="{{ route('tasks.support-sessions.store') }}" class="bg-white border border-gray-200 rounded-2xl p-5 space-y-3 shadow-sm h-fit">
                    @csrf
                    <h2 class="font-bold text-sm">Xếp buổi phụ đạo</h2>
                    @if ($selectedSupport)
                        <input type="hidden" name="class_report_student_support_id" value="{{ $selectedSupport->id }}">
                        <div class="rounded-xl bg-orange-50 border border-orange-200 p-3 text-xs">
                            <div class="font-bold text-gray-900">{{ $selectedSupport->student?->name }} · {{ $selectedSupport->source_label }}</div>
                            <div class="text-gray-600 mt-0.5">{{ $selectedSupport->reason }}</div>
                            <a href="{{ route('tasks.support-sessions') }}" class="mt-1 inline-block text-primary font-semibold hover:underline">Bỏ chọn</a>
                        </div>
                    @endif
                    <label class="block text-[11px] font-bold text-gray-600 uppercase">Lớp</label>
                    <select name="class_id" required class="w-full text-xs rounded-lg border-gray-200">
                        <option value="">Chọn lớp</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}" @selected((int) $selectedClassId === $class->id)>{{ $class->name }}</option>
                        @endforeach
                    </select>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase">Học viên</label>
                    <select name="student_id" required class="w-full text-xs rounded-lg border-gray-200">
                        <option value="">Chọn học viên</option>
                        @foreach ($classes as $class)
                            @if ($classRosters[$class->id]->isNotEmpty())
                                <optgroup label="{{ $class->name }}">
                                    @foreach ($classRosters[$class->id] as $student)
                                        <option value="{{ $student->id }}" @selected((int) $selectedStudentId === $student->id && (int) $selectedClassId === $class->id)>{{ $student->name }} ({{ $student->code }})</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    <label class="block text-[11px] font-bold text-gray-600 uppercase">Người dạy</label>
                    <select name="teacher_id" required class="w-full text-xs rounded-lg border-gray-200">
                        <option value="">Chọn giáo viên / trợ giảng / học vụ</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}" @selected((int) old('teacher_id') === $teacher->id)>{{ $teacher->name }}</option>
                        @endforeach
                    </select>
                    <div class="grid grid-cols-3 gap-2">
                        <input type="date" name="session_date" value="{{ old('session_date', now()->addDay()->format('Y-m-d')) }}" required class="text-xs rounded-lg border-gray-200" aria-label="Ngày">
                        <input type="time" name="start_time" value="{{ old('start_time') }}" required class="text-xs rounded-lg border-gray-200" aria-label="Giờ bắt đầu">
                        <input type="time" name="end_time" value="{{ old('end_time') }}" required class="text-xs rounded-lg border-gray-200" aria-label="Giờ kết thúc">
                    </div>
                    <input name="room" value="{{ old('room') }}" placeholder="Phòng" class="w-full text-xs rounded-lg border-gray-200">
                    <textarea name="reason" placeholder="Mục tiêu phụ đạo (để trống sẽ dùng lý do trong danh sách bổ trợ)" class="w-full text-xs rounded-lg border-gray-200">{{ old('reason') }}</textarea>
                    <p class="text-[11px] text-gray-500">Hệ thống kiểm tra trùng lịch người dạy (kể cả vai trò trợ giảng/GVNN) và phòng, bỏ qua buổi đã hủy.</p>
                    <button class="w-full bg-primary-container text-white rounded-lg py-2 text-xs font-bold">Xếp lịch</button>
                </form>
            @endcan

            <div class="{{ auth()->user()->can('work_task.assign') ? 'lg:col-span-2' : 'lg:col-span-3' }} bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="px-4 py-3 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <h2 class="font-bold text-sm">Danh sách cần bổ trợ (chưa xếp buổi)</h2>
                    <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                        <a href="{{ route('tasks.support-sessions') }}" class="px-2.5 py-1 rounded-full border {{ ! $source ? 'bg-primary-container text-white border-primary-container' : 'border-gray-200 text-gray-600' }}">Tất cả</a>
                        @foreach ($sources as $key => $label)
                            <a href="{{ route('tasks.support-sessions', ['source' => $key]) }}" class="px-2.5 py-1 rounded-full border {{ $source === $key ? 'bg-primary-container text-white border-primary-container' : 'border-gray-200 text-gray-600' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr><th class="p-3 text-left">Học viên</th><th class="p-3 text-left">Nguồn</th><th class="p-3 text-left">Lý do</th><th class="p-3">Ngày ghi nhận</th><th class="p-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($pendingSupports as $item)
                                <tr>
                                    <td class="p-3 font-bold">{{ $item->student?->name }}<small class="block text-gray-400 font-normal">{{ $item->classModel?->name ?? $item->classReport?->classModel?->name }}</small></td>
                                    <td class="p-3"><x-ui.badge :color="$sourceColors[$item->source] ?? 'neutral'">{{ $item->source_label }}</x-ui.badge></td>
                                    <td class="p-3 text-gray-700">{{ $item->reason }}@if ($item->action_plan)<small class="block text-gray-400">KH: {{ $item->action_plan }}</small>@endif</td>
                                    <td class="p-3 text-center text-gray-500">{{ $item->created_at?->format('d/m/Y') }}</td>
                                    <td class="p-3 text-right">
                                        @can('work_task.assign')
                                            <a href="{{ route('tasks.support-sessions', ['support' => $item->id]) }}" class="text-primary font-bold hover:underline">Xếp buổi</a>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="p-8 text-center text-gray-400">Không có học viên nào đang chờ xếp buổi bổ trợ.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-gray-100"><x-ui.pagination :paginator="$pendingSupports" :options="[]" unit="học viên" /></div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="px-4 py-3 border-b border-gray-100"><h2 class="font-bold text-sm">Buổi phụ đạo đã xếp</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="p-3 text-left">Học viên</th><th class="p-3 text-left">Nguồn / lý do</th><th class="p-3">Lịch</th><th class="p-3">Người dạy</th><th class="p-3">Trạng thái</th><th class="p-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($sessions as $session)
                            <tr>
                                <td class="p-3 font-bold">{{ $session->student?->name }}<small class="block text-gray-400 font-normal">{{ $session->classModel?->name }}</small></td>
                                <td class="p-3 text-gray-700">
                                    @if ($session->supportItem)
                                        <x-ui.badge :color="$sourceColors[$session->supportItem->source] ?? 'neutral'">{{ $session->supportItem->source_label }}</x-ui.badge>
                                    @endif
                                    <span class="block mt-0.5">{{ $session->reason }}</span>
                                </td>
                                <td class="p-3 text-center">{{ $session->session_date->format('d/m/Y') }}<br>{{ substr((string) $session->start_time, 0, 5) }}-{{ substr((string) $session->end_time, 0, 5) }}@if ($session->room)<br>P. {{ $session->room }}@endif</td>
                                <td class="p-3 text-center">{{ $session->teacher?->name }}</td>
                                <td class="p-3 text-center"><x-ui.badge :color="$session->status === 'completed' ? 'success' : 'warning'">{{ $session->status_label }}</x-ui.badge></td>
                                <td class="p-3 text-right">
                                    @if ($session->status !== 'completed' && ((int) $session->teacher_id === (int) auth()->id() || auth()->user()->can('work_task.approve')))
                                        <form method="POST" action="{{ route('tasks.support-sessions.complete', $session->id) }}">@csrf<button class="text-emerald-700 font-bold">Hoàn thành</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-8 text-center text-gray-400">Chưa có lịch phụ đạo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100"><x-ui.pagination :paginator="$sessions" :options="[]" unit="buổi" /></div>
        </div>
    </div>
</x-app-layout>
