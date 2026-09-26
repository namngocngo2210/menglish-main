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
            @can('class.delete')
                @if ($class->userCan(auth()->user(), 'delete'))
                    <form method="POST" action="{{ route('classes.destroy', $class->id) }}" onsubmit="return confirm('Xóa lớp {{ $class->name }}?')" class="inline">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger-text" icon="delete">Xóa lớp</x-ui.button>
                    </form>
                @endif
            @endcan
            @if ($nextAction && $nextAction['tab'] !== $tab)
                <x-ui.button icon="arrow_forward" :href="route('classes.show', ['id' => $class->id, 'tab' => $nextAction['tab']])">{{ $nextAction['label'] }}</x-ui.button>
            @endif
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
                        default => 'text-on-surface-variant/70',
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
                            <span class="pl-[22px] font-code text-[11px] opacity-80">{{ $step['hint'] }}</span>
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
