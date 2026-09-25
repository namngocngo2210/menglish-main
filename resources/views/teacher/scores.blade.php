<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">grading</span>
                    Nhập điểm Mini Test — {{ $class->name }}
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

        @if ($class->students->isEmpty())
            <div class="bg-white rounded-2xl p-10 border border-gray-200 shadow-sm text-center text-gray-500">
                <span class="material-symbols-outlined text-4xl text-gray-300">group_off</span>
                <p class="mt-2 text-sm">Lớp này chưa có học sinh nào.</p>
            </div>
        @else
            <form method="POST" action="{{ route('teacher.scores.store', $class->id) }}" class="space-y-4">
                @csrf
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 mb-1">Tên bài kiểm tra</label>
                        <input type="text" name="name" required value="{{ $testName }}" class="w-full text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 mb-1">Ngày kiểm tra</label>
                        <input type="date" name="test_date" required value="{{ $testDate }}" class="w-full text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 mb-1">Thang điểm tối đa</label>
                        <input type="number" name="max_score" required value="10" min="1" step="0.5" class="w-full text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm divide-y divide-gray-100">
                    @foreach ($class->students as $student)
                        @php $sc = $existing->get($student->id); @endphp
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-sm text-gray-900">{{ $student->name }}</div>
                                <div class="text-[11px] text-gray-400">{{ $student->code }}</div>
                            </div>
                            <input type="number" name="score[{{ $student->id }}]" value="{{ $sc?->score }}"
                                   min="0" step="0.25" placeholder="Điểm"
                                   class="w-24 text-sm rounded-lg border-gray-200 focus:border-primary focus:ring-primary text-center font-bold">
                            <input type="text" name="note[{{ $student->id }}]" value="{{ $sc?->note }}"
                                   placeholder="Nhận xét..."
                                   class="w-full sm:w-56 text-xs rounded-lg border-gray-200 focus:border-primary focus:ring-primary">
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-primary hover:bg-primary-hover text-white text-xs font-bold shadow-lg transition flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">save</span> Lưu điểm
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
