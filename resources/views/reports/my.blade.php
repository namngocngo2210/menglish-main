<x-app-layout>
    @php
        $typeLabels = \App\Models\StaffReport::TYPE_LABELS;
        $label = $typeLabels[$type] ?? 'Báo cáo';
    @endphp
    <x-slot name="header">
        <div>
            <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">assignment</span>
                {{ $label }} của tôi
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Nộp và theo dõi {{ mb_strtolower($label) }} theo vai trò của bạn</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        
        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm font-medium">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('reports.my.store') }}" class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm space-y-3">
            @csrf
            <h2 class="text-sm font-bold text-gray-900">Nộp {{ mb_strtolower($label) }} mới</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input type="text" name="title" required placeholder="Tiêu đề *" class="sm:col-span-2 text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container" value="{{ old('title') }}">
                <input type="date" name="report_date" value="{{ now()->toDateString() }}" class="text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">
            </div>
            <textarea name="content" rows="5" required placeholder="Nội dung: kết quả thực hiện, tồn đọng, kế hoạch..." class="w-full text-sm rounded-lg border-gray-200 focus:border-primary-container focus:ring-primary-container">{{ old('content') }}</textarea>
            <div class="flex justify-end">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary-container hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                    <span class="material-symbols-outlined text-[18px]">send</span> Nộp báo cáo
                </button>
            </div>
        </form>

        <div class="space-y-3">
            <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider">Lịch sử đã nộp</h2>
            @forelse ($reports as $r)
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sm text-gray-900">{{ $r->title }}</span>
                        <span class="text-[11px] text-gray-400">{{ $r->report_date->format('d/m/Y') }}</span>
                    </div>
                    <p class="text-sm text-gray-600 mt-2 whitespace-pre-line">{{ $r->content }}</p>
                </div>
            @empty
                <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                    <span class="material-symbols-outlined text-4xl text-gray-300">description</span>
                    <p class="mt-2 text-sm">Bạn chưa nộp {{ mb_strtolower($label) }} nào.</p>
                </div>
            @endforelse
            {{ $reports->links() }}
        </div>
    </div>
</x-app-layout>
