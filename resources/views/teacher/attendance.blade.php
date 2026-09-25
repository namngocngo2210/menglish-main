<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">fact_check</span>
                    Điểm danh lớp {{ $class->name }}
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">{{ $class->code }} · Buổi ngày {{ \Carbon\Carbon::parse($today)->format('d/m/Y') }}</p>
            </div>
            <a href="{{ route('teacher.home') }}" class="text-xs font-semibold text-gray-500 hover:text-primary flex items-center gap-1">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Về trang chủ
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">
                {{ $errors->first() }}
            </div>
        @endif

        @if ($class->students->isEmpty())
            <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300">group_off</span>
                <p class="mt-2 text-sm">Lớp này chưa có học sinh nào.</p>
            </div>
        @else
            <form method="POST" action="{{ route('teacher.attendance.store', $class->id) }}" class="space-y-4">
                @csrf
                @php
                    $options = [
                        'present' => ['label' => 'Có mặt', 'class' => 'peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500'],
                        'late' => ['label' => 'Đi muộn', 'class' => 'peer-checked:bg-amber-500 peer-checked:text-white peer-checked:border-amber-500'],
                        'absent' => ['label' => 'Vắng', 'class' => 'peer-checked:bg-rose-500 peer-checked:text-white peer-checked:border-rose-500'],
                        'excused' => ['label' => 'Có phép', 'class' => 'peer-checked:bg-blue-500 peer-checked:text-white peer-checked:border-blue-500'],
                    ];
                @endphp

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
                    @foreach ($class->students as $student)
                        @php $current = $existing->get($student->id)?->status ?? 'present'; @endphp
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-gray-900">{{ $student->name }}</div>
                                <div class="text-[11px] text-gray-400">{{ $student->code }}</div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                @foreach ($options as $value => $opt)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="status[{{ $student->id }}]" value="{{ $value }}"
                                               class="sr-only peer" {{ $current === $value ? 'checked' : '' }}>
                                        <span class="inline-block px-3 py-1.5 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 transition {{ $opt['class'] }}">
                                            {{ $opt['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <input type="text" name="note[{{ $student->id }}]"
                                   value="{{ $existing->get($student->id)?->note }}"
                                   placeholder="Ghi chú..."
                                   class="w-full sm:w-48 text-xs rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        Lưu điểm danh
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
