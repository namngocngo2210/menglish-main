{{-- Trang lớp: một lớp, một trang. Thanh vòng đời cho biết lớp đang ở bước nào; tab con gom mọi việc của lớp. --}}
<x-app-layout>
    @php $status = \App\Support\ClassLifecycle::status($class->status); @endphp

    <x-ui.page-header :title="$class->name" :back="route('classes.index')" back-label="Danh sách lớp">
        <x-slot:badges>
            <x-ui.badge :color="$status['color']" :pill="true">{{ $status['label'] }}</x-ui.badge>
            <span class="font-code text-body-small text-on-surface-variant">{{ $class->code }}{{ $class->branch ? ' · '.$class->branch->name : '' }}</span>
        </x-slot:badges>
        <x-slot:actions>
            @if ($canManage)
                <x-ui.button variant="secondary" icon="edit" :href="route('classes.edit', $class->id)">Sửa thông tin</x-ui.button>
            @endif
            {{-- Lớp chờ lịch: nút chính mở thẳng màn Lịch & TKB lớp (lọc sẵn lớp), không qua tab Lịch & buổi học rồi bấm lần nữa. --}}
            @if ($nextAction && $class->status === 'pending_schedule' && $tab !== 'schedule' && $canManage && auth()->user()->can('work_task.view'))
                <x-ui.button icon="edit_calendar" :href="route('tasks.schedule-config', ['class_id' => $class->id])">{{ $nextAction['label'] }}</x-ui.button>
            @elseif ($nextAction && $nextAction['tab'] !== $tab)
                {{-- Tình trạng tiếp theo của lớp là nhãn (bấm để mở tab liên quan), không phải nút hành động chính. --}}
                <a href="{{ route('classes.show', ['id' => $class->id, 'tab' => $nextAction['tab']]) }}" class="inline-flex items-center gap-xs rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-container/40 max-md:min-h-11">
                    <x-ui.badge :color="$nextAction['tone']" pill>{{ $nextAction['label'] }}</x-ui.badge>
                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" aria-hidden="true">arrow_forward</span>
                </a>
            @endif
            {{-- Xóa lớp là thao tác hiếm và nguy hiểm: để trong menu "⋯", có hộp xác nhận, không đặt cạnh nút chính. --}}
            @can('class.delete')
                @if ($class->userCan(auth()->user(), 'delete'))
                    <x-ui.dropdown align="right" width="56">
                        <x-slot name="trigger">
                            <x-ui.button type="button" variant="ghost" icon="more_horiz" aria-label="Thao tác khác" aria-haspopup="menu" />
                        </x-slot>
                        <x-slot name="content">
                            <form method="POST" action="{{ route('classes.destroy', $class->id) }}" data-confirm="Xóa lớp {{ $class->name }}? Lớp sẽ bị ẩn khỏi danh sách." data-confirm-label="Xóa" data-confirm-danger role="menu">
                                @csrf
                                @method('DELETE')
                                <button type="submit" role="menuitem" class="flex w-full items-center gap-sm px-md py-sm text-left font-body-medium text-body-medium text-error transition-colors hover:bg-error/5">
                                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">delete</span>Xóa lớp
                                </button>
                            </form>
                        </x-slot>
                    </x-ui.dropdown>
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-5">
        {{-- Vòng đời lớp --}}
        @if ($class->status === 'cancelled')
            <x-ui.alert type="error">Lớp đã hủy. Thông tin bên dưới chỉ để tra cứu.</x-ui.alert>
        @endif
        <ol class="no-scrollbar flex overflow-x-auto rounded-xl border border-surface-container-highest bg-surface-container-lowest" aria-label="Vòng đời lớp" data-lifecycle>
            @foreach ($steps as $step)
                @php
                    $stepClass = match ($step['state']) {
                        'done' => 'text-tertiary',
                        'current' => 'bg-primary-container/10 text-primary font-semibold',
                        default => 'text-on-surface-subtle',
                    };
                @endphp
                <li class="min-w-[140px] flex-1 border-r border-surface-container-highest last:border-r-0">
                    <a href="{{ route('classes.show', ['id' => $class->id, 'tab' => $step['tab']]) }}"
                       class="flex h-full flex-col gap-0.5 px-md py-sm transition-colors hover:bg-surface-container-low {{ $stepClass }}"
                       @if ($step['state'] === 'current') aria-current="step" @endif data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                        <span class="flex items-center gap-xs text-body-small">
                            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">{{ $step['state'] === 'done' ? 'check_circle' : ($step['state'] === 'current' ? 'radio_button_checked' : 'radio_button_unchecked') }}</span>
                            {{ $step['label'] }}
                        </span>
                        @if ($step['hint'])
                            <span class="pl-[22px] font-code text-xs opacity-80">{{ $step['hint'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ol>

        {{-- Tab con --}}
        <x-ui.tabs>
            @foreach (\App\Support\ClassLifecycle::TABS as $key => $meta)
                @php
                    $count = match ($key) {
                        'students' => $seat['occupied'],
                        'incidents' => $openIncidents ?: null,
                        default => null,
                    };
                @endphp
                <x-ui.tab :href="route('classes.show', ['id' => $class->id, 'tab' => $key])" :active="$tab === $key" :icon="$meta['icon']" :count="$count">{{ $meta['label'] }}</x-ui.tab>
            @endforeach
        </x-ui.tabs>

        @include('classes.show.'.$tab)
    </div>
</x-app-layout>
