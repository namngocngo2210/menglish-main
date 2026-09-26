{{-- Portal trợ giảng — "Nhiệm vụ hằng ngày" dạng điện thoại (mockup nhi_m_v_h_m_nay_ta), có thanh điều hướng dưới. --}}
@php
    $groups = [
        ['key' => 'before', 'title' => 'Trước giờ học', 'icon' => 'schedule', 'tasks' => $beforeTasks],
        ['key' => 'during', 'title' => 'Trong giờ học', 'icon' => 'play_circle', 'tasks' => $duringTasks],
        ['key' => 'after', 'title' => 'Sau giờ học', 'icon' => 'task_alt', 'tasks' => $afterTasks],
    ];
    $firstOpen = collect($groups)->first(fn ($g) => $g['tasks']->isNotEmpty())['key'] ?? 'before';
@endphp
<x-app-layout title="Nhiệm vụ hôm nay">
    <div class="mx-auto max-w-md pb-24 md:max-w-2xl md:pb-0"
         x-data="{
            modalOpen: false,
            selectedTask: null,
            proofName: '',
            openCompleteModal(task) { this.selectedTask = task; this.proofName = ''; this.modalOpen = true; }
         }">

        {{-- Tiêu đề + người được xem --}}
        <header class="mb-md flex items-center justify-between gap-sm">
            <div class="min-w-0">
                <h1 class="font-h2 text-h2 text-primary">{{ $isToday ? 'Nhiệm vụ hằng ngày' : 'Nhiệm vụ ngày '.$date->format('d/m/Y') }}</h1>
                <p class="truncate font-body-small text-body-small text-on-surface-variant">
                    @if ($taUser)
                        Trợ giảng: <span class="font-semibold text-on-surface">{{ $taUser->name }}</span> · {{ $date->format('d/m/Y') }}
                    @else
                        Chưa có trợ giảng nào trong hệ thống.
                    @endif
                </p>
            </div>
            @if ($taUser)
                <x-ui.avatar :name="$taUser->name" />
            @endif
        </header>

        @if (session('success'))
            <x-ui.alert type="success" class="mb-md" dismissible>{{ session('success') }}</x-ui.alert>
        @endif

        {{-- Bộ lọc: ngày (+ chọn trợ giảng cho admin / quản lý) --}}
        <form method="GET" action="{{ route('portal.ta-tasks') }}" class="mb-md flex flex-wrap items-end gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-sm">
            @if ($canPickTa)
                <label class="min-w-[160px] flex-1">
                    <span class="mb-xs block font-caption text-caption text-on-surface-variant">Trợ giảng</span>
                    <select name="ta_id" onchange="this.form.submit()" aria-label="Chọn trợ giảng"
                            class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-xs pl-sm pr-lg font-body-small text-body-small">
                        @forelse ($assistants as $assistant)
                            <option value="{{ $assistant->id }}" @selected($taUser && $taUser->id === $assistant->id)>{{ $assistant->name }}</option>
                        @empty
                            <option value="">Chưa có trợ giảng</option>
                        @endforelse
                    </select>
                </label>
            @endif
            <label class="min-w-[140px] flex-1">
                <span class="mb-xs block font-caption text-caption text-on-surface-variant">Ngày</span>
                <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" aria-label="Chọn ngày"
                       class="w-full rounded-lg border border-outline-variant px-sm py-xs font-body-small text-body-small">
            </label>
            @unless ($isToday)
                <x-ui.button size="sm" variant="ghost" icon="today" :href="route('portal.ta-tasks', array_filter(['ta_id' => $canPickTa ? $taUser?->id : null]))">Hôm nay</x-ui.button>
            @endunless
            <noscript><x-ui.button type="submit" size="sm" variant="secondary">Xem</x-ui.button></noscript>
        </form>

        @if ($overdueCount > 0)
            <x-ui.alert type="warning" class="mb-md">Còn <strong>{{ $overdueCount }}</strong> nhiệm vụ của các ngày trước chưa hoàn thành.</x-ui.alert>
        @endif

        {{-- Nhiệm vụ theo ca --}}
        <section id="nhiem-vu" class="space-y-md" aria-label="Nhiệm vụ">
            @foreach ($groups as $group)
                <div class="overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest" x-data="{ open: @js($group['key'] === $firstOpen || $group['tasks']->isNotEmpty()) }">
                    <button type="button" x-on:click="open = !open" :aria-expanded="open"
                            class="flex w-full items-center justify-between gap-sm px-md py-md text-left">
                        <span class="flex items-center gap-sm">
                            <span class="material-symbols-outlined text-primary-container" aria-hidden="true">{{ $group['icon'] }}</span>
                            <span class="font-h3 text-h3 text-on-surface">{{ $group['title'] }}</span>
                            <span class="rounded-full bg-surface-container-high px-sm font-code text-caption text-on-surface-variant">{{ $group['tasks']->count() }}</span>
                        </span>
                        <span class="material-symbols-outlined text-on-surface-variant transition-transform" :class="open ? 'rotate-180' : ''" aria-hidden="true">expand_more</span>
                    </button>
                    <div x-show="open" x-collapse class="space-y-sm px-md pb-md">
                        @forelse ($group['tasks'] as $task)
                            @include('tasks.partials.ta-task-card', ['task' => $task, 'canComplete' => $canComplete, 'isToday' => $isToday])
                        @empty
                            <p class="py-sm font-body-small text-body-small italic text-on-surface-variant">Không có nhiệm vụ {{ mb_strtolower($group['title']) }}.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach

            @if ($taUser && $tasks->isEmpty())
                <x-ui.empty-state icon="task_alt" title="Không có nhiệm vụ trong ngày"
                    description="{{ $taUser->name }} chưa được giao nhiệm vụ nào cho ngày {{ $date->format('d/m/Y') }}." />
            @endif
        </section>

        {{-- Lớp trực trong ngày (từ buổi học thật) --}}
        <section id="lop-hoc" class="mt-lg space-y-sm" aria-label="Lớp trực">
            <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface">
                <span class="material-symbols-outlined text-primary-container" aria-hidden="true">school</span>
                Lớp trực {{ $isToday ? 'hôm nay' : 'ngày '.$date->format('d/m') }}
            </h2>
            @forelse ($sessions as $session)
                <div class="flex items-center justify-between gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest p-md">
                    <div class="min-w-0">
                        <p class="truncate font-body-medium text-body-medium font-semibold text-on-surface">{{ $session->classModel?->name }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">
                            <span class="font-code">{{ $session->start_time?->format('H:i') }} - {{ $session->end_time?->format('H:i') }}</span>
                            · {{ $session->room ?: 'Chưa có phòng' }}{{ $session->branch ? ' · '.$session->branch->name : '' }}
                        </p>
                    </div>
                    @if ($session->type === \App\Models\ClassSession::TYPE_MAKEUP)
                        <x-ui.badge color="warning">Học bù</x-ui.badge>
                    @endif
                </div>
            @empty
                <p class="font-body-small text-body-small italic text-on-surface-variant">Không có buổi học nào trong ngày.</p>
            @endforelse
        </section>

        {{-- Thanh điều hướng dưới (điện thoại) --}}
        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-outline-variant bg-surface-container-lowest md:hidden" aria-label="Điều hướng portal trợ giảng">
            <ul class="mx-auto grid max-w-md grid-cols-4 gap-xs px-sm py-xs">
                <li>
                    <a href="#nhiem-vu" class="flex flex-col items-center gap-[2px] rounded-xl bg-primary-container px-xs py-xs text-white" aria-current="page">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">assignment</span>
                        <span class="text-[11px] font-semibold">Nhiệm vụ</span>
                    </a>
                </li>
                <li>
                    <a href="#lop-hoc" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">school</span>
                        <span class="text-[11px] font-semibold">Lớp học</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('tasks.class-reports.create') }}" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">bar_chart</span>
                        <span class="text-[11px] font-semibold">Báo cáo</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}" class="flex flex-col items-center gap-[2px] rounded-xl px-xs py-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-[22px]" aria-hidden="true">person</span>
                        <span class="text-[11px] font-semibold">Cá nhân</span>
                    </a>
                </li>
            </ul>
        </nav>

        {{-- Modal hoàn thành nhiệm vụ --}}
        <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-md">
            <div x-on:click.outside="modalOpen = false" class="flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl bg-surface-container-lowest shadow-xl sm:rounded-2xl">
                <div class="flex items-center justify-between border-b border-surface-container px-md py-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Cập nhật tiến độ</h2>
                    <button type="button" x-on:click="modalOpen = false" class="rounded-full p-xs text-on-surface-variant" aria-label="Đóng">
                        <span class="material-symbols-outlined text-[20px]">close</span>
                    </button>
                </div>
                <form :action="'{{ url('/tasks') }}/' + (selectedTask ? selectedTask.id : '') + '/complete'" method="POST" enctype="multipart/form-data" class="space-y-md overflow-y-auto p-md">
                    @csrf
                    <div class="rounded-lg bg-surface-container-low p-sm">
                        <p class="font-body-medium text-body-medium font-semibold" x-text="selectedTask ? 'Nhiệm vụ: ' + selectedTask.title : ''"></p>
                        <p class="font-caption text-caption text-on-surface-variant" x-text="'Hạn chót: ' + (selectedTask ? selectedTask.due_label : '—')"></p>
                    </div>
                    <div class="space-y-xs">
                        <span class="block font-label text-label uppercase text-on-surface-variant">Bằng chứng hình ảnh</span>
                        <label for="ta_proof" class="flex cursor-pointer flex-col items-center justify-center gap-xs rounded-lg border-2 border-dashed border-outline-variant p-md text-center text-on-surface-variant hover:border-primary-container">
                            <span class="material-symbols-outlined text-[32px]" aria-hidden="true">cloud_upload</span>
                            <span class="font-body-small text-body-small font-semibold" x-text="proofName || 'Nhấn để tải ảnh lên'"></span>
                            <span class="font-caption text-caption">PNG, JPG tối đa 10MB</span>
                        </label>
                        <input id="ta_proof" type="file" name="proof_image" accept="image/*" class="sr-only" x-on:change="proofName = $event.target.files[0]?.name || ''">
                    </div>
                    <x-ui.alert type="info"><strong>Lưu ý:</strong> Có ảnh đính kèm, nhiệm vụ sẽ được <strong>hoàn thành ngay</strong>. Nếu không có ảnh, trạng thái sẽ chuyển sang <strong>chờ người giao việc xác nhận</strong>.</x-ui.alert>
                    <x-ui.field label="Ghi chú (tùy chọn)" name="note" for="ta_note">
                        <textarea id="ta_note" name="note" rows="3" class="w-full rounded-lg border border-outline-variant p-sm font-body-small text-body-small" placeholder="Kết quả hoặc vấn đề phát sinh..."></textarea>
                    </x-ui.field>
                    <div class="flex justify-end gap-sm border-t border-surface-container pt-sm">
                        <x-ui.button variant="secondary" x-on:click="modalOpen = false">Hủy</x-ui.button>
                        <x-ui.button type="submit" icon="send">Xác nhận</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
