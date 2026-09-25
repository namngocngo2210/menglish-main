<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">assignment</span>
                    Giao bài tập về nhà — {{ $class->name }}
                </h1>
                <p class="text-xs text-gray-500 mt-0.5">{{ $class->code }}</p>
            </div>
            <a href="{{ route('teacher.home') }}" class="text-xs font-semibold text-gray-500 hover:text-primary flex items-center gap-1">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span> Về trang chủ
            </a>
        </div>
    </x-slot>

    <div class="space-y-6">
        
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('teacher.homework.store', $class->id) }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-gray-900">Giao bài tập mới</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input type="text" name="title" required placeholder="Tiêu đề bài tập *" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('title') }}">
                <input type="date" name="due_date" value="{{ now()->addDays(3)->toDateString() }}" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
            </div>
            <textarea name="description" rows="3" placeholder="Mô tả / hướng dẫn làm bài..." class="w-full text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">{{ old('description') }}</textarea>
            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">add</span> Giao bài
                </button>
            </div>
        </form>

        <div class="space-y-3">
            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Bài tập đã giao</h2>
            @forelse ($homeworks as $hw)
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm flex items-start justify-between gap-3">
                    <div>
                        <div class="font-bold text-sm text-gray-900">{{ $hw->title }}</div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            Hạn nộp: {{ $hw->due_date?->format('d/m/Y') ?? 'Không giới hạn' }} · GV: {{ $hw->teacher?->name }}
                        </div>
                        @if ($hw->description)
                            <p class="text-sm text-gray-600 mt-2 whitespace-pre-line">{{ $hw->description }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('teacher.homework.destroy', [$class->id, $hw->id]) }}" onsubmit="return confirm('Xoá bài tập này?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Xoá">
                            <span class="material-symbols-outlined text-[18px]">delete</span>
                        </button>
                    </form>
                </div>
            @empty
                <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">assignment</span>
                    <p class="mt-2 text-sm">Chưa giao bài tập nào cho lớp này.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
