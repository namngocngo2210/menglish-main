<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('classes.profile') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">dashboard</span>
                        Tổng quan Danh sách lớp Học thuật
                    </h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('classes.academic-list') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-white text-xs font-semibold shadow-sm hover:bg-primary-dark transition">
                    <span class="material-symbols-outlined text-[18px]">table_view</span>
                    <span>Danh sách lớp chi tiết</span>
                </a>
            </div>
        </div>
    </x-slot>


    <div class="max-w-7xl mx-auto space-y-8">
        {{-- Sub-Header & Branch Select --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white p-4 rounded-2xl border border-gray-200 shadow-sm">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Tổng quan Danh sách lớp</h2>
                <p class="text-xs text-gray-500">Chọn chi nhánh để lọc số liệu thống kê lớp học</p>
            </div>
            <div class="relative w-full sm:w-64">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                    <span class="material-symbols-outlined text-[18px]">location_on</span>
                </div>
                <select onchange="window.location.href = '{{ route('classes.academic-overview') }}?branch=' + this.value"
                        class="block w-full pl-9 pr-8 py-2 text-xs font-bold bg-white border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container appearance-none cursor-pointer">
                    <option value="all" {{ $selectedBranch === 'all' ? 'selected' : '' }}>Tất cả cơ sở</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ $selectedBranch == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center pr-2.5 pointer-events-none text-gray-400">
                    <span class="material-symbols-outlined text-[18px]">arrow_drop_down</span>
                </div>
            </div>
        </div>

        {{-- Tổng số lớp đang hoạt động --}}
        <section>
            <a href="{{ route('classes.academic-list') }}"
               class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-shadow cursor-pointer flex items-center gap-6 group block">
                <div class="w-16 h-16 rounded-2xl bg-orange-50 text-primary flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform border border-orange-100">
                    <span class="material-symbols-outlined text-[34px]">school</span>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Tổng số lớp đang hoạt động</h3>
                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-black text-primary tracking-tight">{{ $totalActive }}</span>
                        <span class="text-sm font-semibold text-gray-500">lớp học</span>
                    </div>
                </div>
                <div class="ml-auto text-gray-400 group-hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-[28px]">chevron_right</span>
                </div>
            </a>
        </section>

        {{-- Số lớp theo chương trình (dữ liệu thật) --}}
        <section class="space-y-4">
            <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[20px]">category</span>
                Số lớp theo chương trình
            </h3>
            @if ($programCounts->isEmpty())
                <p class="text-xs text-gray-400">Chưa có dữ liệu.</p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach ($programCounts as $program => $count)
                        @php($programUrl = $program !== '' ? route('classes.academic-list', array_merge(['program' => $program], $selectedBranch !== 'all' ? ['branch_id' => $selectedBranch] : [])) : route('classes.academic-list', $selectedBranch !== 'all' ? ['branch_id' => $selectedBranch] : []))
                        <a href="{{ $programUrl }}"
                           class="bg-white border border-gray-200 rounded-2xl p-4 hover:border-primary-container/40 transition cursor-pointer group flex justify-between items-center relative overflow-hidden shadow-2xs">
                            <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-primary-container"></div>
                            <div class="pl-2 text-sm font-bold text-gray-900 group-hover:text-primary transition-colors">{{ $program !== '' ? $program : 'Chưa gán chương trình' }}</div>
                            <div class="flex items-baseline gap-1">
                                <span class="text-xl font-bold text-gray-900 font-mono">{{ $count }}</span>
                                <span class="text-xs text-gray-400">lớp</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Số lớp theo trình độ / khối (dữ liệu thật) --}}
        <section class="space-y-4">
            <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[20px]">layers</span>
                Số lớp theo trình độ / khối
            </h3>
            @if ($levelCounts->isEmpty())
                <p class="text-xs text-gray-400">Chưa có dữ liệu.</p>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                    @foreach ($levelCounts as $level => $count)
                        @php($levelUrl = $level !== '' ? route('classes.academic-list', array_merge(['level' => $level], $selectedBranch !== 'all' ? ['branch_id' => $selectedBranch] : [])) : route('classes.academic-list', $selectedBranch !== 'all' ? ['branch_id' => $selectedBranch] : []))
                        <a href="{{ $levelUrl }}"
                           class="bg-white border border-gray-200 rounded-2xl p-4 hover:shadow-md hover:border-primary-container/40 transition cursor-pointer flex flex-col gap-3 group">
                            <div class="text-xs font-bold text-gray-700 truncate group-hover:text-primary transition-colors">{{ $level !== '' ? $level : 'Chưa gán trình độ' }}</div>
                            <div class="flex justify-between items-end">
                                <div class="flex items-baseline gap-1">
                                    <span class="text-2xl font-black text-primary tracking-tight font-mono">{{ $count }}</span>
                                    <span class="text-xs text-gray-400">lớp</span>
                                </div>
                                <span class="material-symbols-outlined text-gray-300 group-hover:text-primary group-hover:translate-x-1 transition-all text-[18px]">arrow_forward</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
