<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-black text-gray-900 tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-2xl">comment</span>
                    Nhận xét buổi học: {{ $class->name }}
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

        

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <form action="{{ route('teacher.remarks.store', $class->id) }}" method="POST">
                @csrf
                <div class="w-full overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[1200px]">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-200">
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase sticky left-0 bg-gray-50 z-10 border-r border-gray-200 w-[200px]">Học sinh</th>
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase w-[120px]">Điểm danh</th>
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase w-[100px]">Monsters</th>
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase w-[150px]">Thực hành ngữ pháp</th>
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase w-[150px]">Tinh thần học tập</th>
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase w-[150px]">Kết quả</th>
                                <th class="px-4 py-3 text-xs font-semibold tracking-wider text-gray-500 uppercase min-w-[250px]">Nhận xét chi tiết</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($class->students as $student)
                                @php
                                    $att = $attendance->get($student->id);
                                    $attStatus = $att ? $att->status : 'none';
                                    $isAbsent = $attStatus === 'absent' || $attStatus === 'excused';
                                    $remark = $existing->get($student->id);
                                @endphp
                                <tr class="hover:bg-gray-50 {{ $isAbsent ? 'bg-gray-50/50 opacity-75' : '' }}">
                                    <td class="px-4 py-3 sticky left-0 bg-white {{ $isAbsent ? 'bg-gray-50' : '' }} z-10 border-r border-gray-200">
                                        <div class="flex items-center gap-3">
                                            <div class="h-8 w-8 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold text-xs">
                                                {{ substr($student->name, 0, 1) }}
                                            </div>
                                            <span class="text-sm font-medium text-gray-900">{{ $student->name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($attStatus === 'present')
                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-emerald-100 text-emerald-800">
                                                <span class="material-symbols-outlined text-[14px] mr-1">check</span> Có mặt
                                            </span>
                                        @elseif ($attStatus === 'late')
                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                                <span class="material-symbols-outlined text-[14px] mr-1">schedule</span> Đi muộn
                                            </span>
                                        @elseif ($isAbsent)
                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-rose-100 text-rose-800">
                                                <span class="material-symbols-outlined text-[14px] mr-1">close</span> Vắng mặt
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                <span class="material-symbols-outlined text-[14px] mr-1">help</span> Chưa DD
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <input name="remarks[{{ $student->id }}][monsters]" value="{{ $remark['monsters'] ?? '' }}" type="text" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-container focus:ring focus:ring-primary-container focus:ring-opacity-50 text-sm" placeholder="+5" {{ $isAbsent ? 'disabled' : '' }}>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input name="remarks[{{ $student->id }}][grammar]" value="{{ $remark['grammar'] ?? '' }}" type="text" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-container focus:ring focus:ring-primary-container focus:ring-opacity-50 text-sm" placeholder="Khá" {{ $isAbsent ? 'disabled' : '' }}>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input name="remarks[{{ $student->id }}][attitude]" value="{{ $remark['attitude'] ?? '' }}" type="text" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-container focus:ring focus:ring-primary-container focus:ring-opacity-50 text-sm" placeholder="Hăng hái" {{ $isAbsent ? 'disabled' : '' }}>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input name="remarks[{{ $student->id }}][result]" value="{{ $remark['result'] ?? '' }}" type="text" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-container focus:ring focus:ring-primary-container focus:ring-opacity-50 text-sm" placeholder="Đạt mục tiêu" {{ $isAbsent ? 'disabled' : '' }}>
                                    </td>
                                    <td class="px-4 py-3">
                                        <textarea name="remarks[{{ $student->id }}][comment]" rows="1" class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-container focus:ring focus:ring-primary-container focus:ring-opacity-50 text-sm" placeholder="{{ $isAbsent ? 'Học sinh vắng mặt' : 'Nhận xét chi tiết...' }}" {{ $isAbsent ? 'disabled' : '' }}>{{ $remark['comment'] ?? '' }}</textarea>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex items-center justify-end gap-3">
                    <button type="submit" class="px-4 py-2 bg-primary-container text-white text-sm font-medium rounded-lg hover:bg-primary-dark transition-colors flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                        Lưu nhận xét
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
