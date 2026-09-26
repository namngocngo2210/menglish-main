<x-app-layout>
    <x-ui.page-header title="Danh sách bổ trợ & xếp lịch phụ đạo" description="Học viên vắng học, điểm mini test / Big Test dưới 7 được tự đưa vào danh sách; Học vụ xếp buổi bổ trợ, buổi hoàn thành chuyển bảng công chờ duyệt." />

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
                <form method="POST" action="{{ route('tasks.support-sessions.store') }}" class="bg-surface-container-lowest border border-surface-container-highest rounded-2xl p-5 space-y-3 shadow-sm h-fit">
                    @csrf
                    <h2 class="font-bold text-sm">Xếp buổi phụ đạo</h2>
                    @if ($selectedSupport)
                        <input type="hidden" name="class_report_student_support_id" value="{{ $selectedSupport->id }}">
                        <div class="rounded-xl bg-primary-container/10 border border-primary-container/30 p-3 text-xs">
                            <div class="font-bold text-on-surface">{{ $selectedSupport->student?->name }} · {{ $selectedSupport->source_label }}</div>
                            <div class="text-on-surface-variant mt-0.5">{{ $selectedSupport->reason }}</div>
                            <a href="{{ route('tasks.support-sessions') }}" class="mt-1 inline-block text-primary font-semibold hover:underline">Bỏ chọn</a>
                        </div>
                    @endif
                    <x-ui.select name="class_id" label="Lớp" required placeholder="Chọn lớp">
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}" @selected((int) $selectedClassId === $class->id)>{{ $class->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="student_id" label="Học viên" required placeholder="Chọn học viên">
                        @foreach ($classes as $class)
                            @if ($classRosters[$class->id]->isNotEmpty())
                                <optgroup label="{{ $class->name }}">
                                    @foreach ($classRosters[$class->id] as $student)
                                        <option value="{{ $student->id }}" @selected((int) $selectedStudentId === $student->id && (int) $selectedClassId === $class->id)>{{ $student->name }} ({{ $student->code }})</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="teacher_id" label="Người dạy" required placeholder="Chọn giáo viên / trợ giảng / học vụ">
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}" @selected((int) old('teacher_id') === $teacher->id)>{{ $teacher->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="grid grid-cols-3 gap-2">
                        <x-ui.date name="session_date" :value="now()->addDay()->format('Y-m-d')" required aria-label="Ngày" />
                        <x-ui.input type="time" name="start_time" required aria-label="Giờ bắt đầu" />
                        <x-ui.input type="time" name="end_time" required aria-label="Giờ kết thúc" />
                    </div>
                    <x-ui.input name="room" placeholder="Phòng" />
                    <x-ui.textarea name="reason" rows="2" placeholder="Mục tiêu phụ đạo (để trống sẽ dùng lý do trong danh sách bổ trợ)" />
                    <p class="text-[11px] text-on-surface-variant">Hệ thống kiểm tra trùng lịch người dạy (kể cả vai trò trợ giảng/GVNN) và phòng, bỏ qua buổi đã hủy.</p>
                    <x-ui.button type="submit" class="w-full">Xếp lịch</x-ui.button>
                </form>
            @endcan

            <x-ui.data-table class="{{ auth()->user()->can('work_task.assign') ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                <x-slot:header>
                    <h2 class="font-bold text-sm">Danh sách cần bổ trợ (chưa xếp buổi)</h2>
                    <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                        <a href="{{ route('tasks.support-sessions') }}" class="px-2.5 py-1 rounded-full border {{ ! $source ? 'bg-primary-container text-white border-primary-container' : 'border-surface-container-highest text-on-surface-variant' }}">Tất cả</a>
                        @foreach ($sources as $key => $label)
                            <a href="{{ route('tasks.support-sessions', ['source' => $key]) }}" class="px-2.5 py-1 rounded-full border {{ $source === $key ? 'bg-primary-container text-white border-primary-container' : 'border-surface-container-highest text-on-surface-variant' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </x-slot:header>
                <table>
                    <thead>
                        <tr><th>Học viên</th><th>Nguồn</th><th>Lý do</th><th class="text-center">Ngày ghi nhận</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($pendingSupports as $item)
                            <tr>
                                <td class="font-bold">{{ $item->student?->name }}<small class="block text-on-surface-variant/70 font-normal">{{ $item->classModel?->name ?? $item->classReport?->classModel?->name }}</small></td>
                                <td><x-ui.badge :color="$sourceColors[$item->source] ?? 'neutral'">{{ $item->source_label }}</x-ui.badge></td>
                                <td class="text-on-surface-variant">{{ $item->reason }}@if ($item->action_plan)<small class="block text-on-surface-variant/70">KH: {{ $item->action_plan }}</small>@endif</td>
                                <td class="text-center text-on-surface-variant">{{ $item->created_at?->format('d/m/Y') }}</td>
                                <td class="text-right">
                                    @can('work_task.assign')
                                        <a href="{{ route('tasks.support-sessions', ['support' => $item->id]) }}" class="text-primary font-bold hover:underline">Xếp buổi</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-ui.empty-state title="Không có học viên nào đang chờ xếp buổi bổ trợ." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                <x-slot:footer><x-ui.pagination :paginator="$pendingSupports" :options="[]" unit="học viên" /></x-slot:footer>
            </x-ui.data-table>
        </div>

        <x-ui.data-table>
            <x-slot:header><h2 class="font-bold text-sm">Buổi phụ đạo đã xếp</h2></x-slot:header>
            <table>
                <thead>
                    <tr><th>Học viên</th><th>Nguồn / lý do</th><th class="text-center">Lịch</th><th class="text-center">Người dạy</th><th class="text-center">Trạng thái</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr>
                            <td class="font-bold">{{ $session->student?->name }}<small class="block text-on-surface-variant/70 font-normal">{{ $session->classModel?->name }}</small></td>
                            <td class="text-on-surface-variant">
                                @if ($session->supportItem)
                                    <x-ui.badge :color="$sourceColors[$session->supportItem->source] ?? 'neutral'">{{ $session->supportItem->source_label }}</x-ui.badge>
                                @endif
                                <span class="block mt-0.5">{{ $session->reason }}</span>
                            </td>
                            <td class="text-center">{{ $session->session_date->format('d/m/Y') }}<br>{{ substr((string) $session->start_time, 0, 5) }}-{{ substr((string) $session->end_time, 0, 5) }}@if ($session->room)<br>P. {{ $session->room }}@endif</td>
                            <td class="text-center">{{ $session->teacher?->name }}</td>
                            <td class="text-center"><x-ui.badge :color="$session->status === 'completed' ? 'success' : 'warning'">{{ $session->status_label }}</x-ui.badge></td>
                            <td class="text-right">
                                @if ($session->status !== 'completed' && ((int) $session->teacher_id === (int) auth()->id() || auth()->user()->can('work_task.approve')))
                                    <form method="POST" action="{{ route('tasks.support-sessions.complete', $session->id) }}">@csrf<x-ui.button type="submit" variant="success" size="sm">Hoàn thành</x-ui.button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state title="Chưa có lịch phụ đạo." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$sessions" :options="[]" unit="buổi" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
