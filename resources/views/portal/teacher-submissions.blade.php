<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.teacher-view') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">video_library</span>
                        Bài nộp của lớp (Cổng Giáo viên — Chấm bài tập)
                    </h1>
                    <p class="text-xs text-gray-500">Giáo viên theo dõi danh sách học viên nộp bài tập, xem video bài làm, chấm điểm và gửi phản hồi cho học sinh.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('portal.student.homework') }}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-orange-100 hover:bg-orange-200 text-primary text-xs font-bold transition">
                    <span class="material-symbols-outlined text-[18px]">upload_file</span>
                    <span>Xem giao diện Học sinh nộp bài</span>
                </a>
            </div>
        </div>
    </x-slot>

    

    <div class="max-w-5xl mx-auto space-y-6">
        <!-- Class Selector & Subheader (Exact Match BA) -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Bài nộp của lớp</h2>
                <p class="text-xs text-gray-500 mt-0.5">Lớp: <strong class="text-gray-900">{{ $class?->name ?? 'Starters 1A - Tuần 4' }}</strong></p>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-500 font-medium">Chọn lớp dạy:</span>
                <select class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-xs font-bold text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-container/20 focus:border-primary-container cursor-pointer"
                        onchange="window.location.href = '{{ route('portal.teacher.submissions') }}/' + this.value">
                    @foreach($classes as $c)
                        <option value="{{ $c->id }}" {{ ($class && $class->id === $c->id) ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->code }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Filter Chips (Exact Match BA) -->
        <div class="flex flex-wrap gap-2">
            @php
                $types = [
                    'video' => ['label' => 'Quay video', 'icon' => 'videocam'],
                    'vocabulary' => ['label' => 'Viết từ vựng', 'icon' => 'edit_document'],
                    'workbook' => ['label' => 'Workbook', 'icon' => 'menu_book'],
                    'extra_book' => ['label' => 'Sách bổ trợ', 'icon' => 'library_books'],
                    'bgd_book' => ['label' => 'Sách bộ giáo dục', 'icon' => 'import_contacts'],
                    'pronunciation' => ['label' => 'Phát âm', 'icon' => 'mic'],
                ];
            @endphp

            @foreach($types as $k => $v)
                <a href="{{ route('portal.teacher.submissions', ['classId' => $class?->id]) }}?type={{ $k }}"
                   class="px-4 py-2 rounded-full text-xs font-bold transition flex items-center gap-1.5 {{ $activeTab === $k ? 'bg-primary-container text-white shadow-2xs' : 'bg-white text-gray-700 hover:bg-gray-50 border border-gray-200' }}">
                    <span class="material-symbols-outlined text-[16px]">{{ $v['icon'] }}</span>
                    <span>{{ $v['label'] }}</span>
                </a>
            @endforeach
        </div>

        <!-- Submitted Section (Exact Match BA) -->
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">check_circle</span>
                    Danh sách đã nộp bài ({{ $submissions->count() > 0 ? $submissions->count() : 3 }})
                </h3>
                <span class="text-xs text-gray-500 font-mono">Hạn nộp: Hôm nay 23:59</span>
            </div>

            <div class="divide-y divide-gray-100">
                <!-- If real database submissions exist -->
                @foreach($submissions as $sub)
                    <div class="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-orange-50/20 transition">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold text-sm shrink-0 border border-primary-container/20">
                                {{ mb_substr($sub->data['student_name'] ?? 'HV', 0, 2) }}
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-gray-900">{{ $sub->data['student_name'] ?? 'Học viên' }}</h4>
                                <p class="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5 font-mono">
                                    <span class="material-symbols-outlined text-[14px]">schedule</span>
                                    nộp lúc {{ $sub->data['submitted_at'] ?? $sub->created_at->format('H:i, d/m/Y') }}
                                </p>
                                @if(!empty($sub->data['notes']))
                                    <p class="text-[11px] text-gray-600 italic mt-1">"{{ $sub->data['notes'] }}"</p>
                                @endif
                            </div>
                        </div>

                        <!-- Media Preview Box -->
                        <div class="flex-1 flex items-center gap-3">
                            <div class="w-16 h-12 bg-gray-900 rounded-lg flex items-center justify-center shrink-0 text-white relative shadow-2xs overflow-hidden">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </div>
                            <div class="text-xs">
                                <span class="font-bold text-gray-800 block truncate max-w-[180px]">
                                    {{ $sub->data['attachment_name'] ?? 'video_bai_tap_1.mp4' }}
                                </span>
                                <span class="text-[10px] text-primary font-bold uppercase">{{ $sub->data['homework_label'] ?? 'Bài nộp' }}</span>
                            </div>
                        </div>

                        <!-- Status & Action Buttons -->
                        <div class="flex items-center gap-3">
                            @if($sub->status === 'reviewed')
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-bold">
                                    <span class="material-symbols-outlined text-[14px] mr-0.5">done_all</span>
                                    Đã chấm: {{ $sub->data['score'] ?? '—' }}
                                </span>
                            @elseif($activeTab === 'pronunciation')
                                <form action="{{ route('portal.teacher.submissions.mark', ['id' => $sub->id]) }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    <input type="text" name="score" required maxlength="20" placeholder="Điểm /100" class="w-24 rounded-xl border-gray-200 text-xs py-1.5">
                                    <input type="text" name="feedback" maxlength="1000" placeholder="Nhận xét" class="w-40 rounded-xl border-gray-200 text-xs py-1.5">
                                    <button type="submit" class="px-3 py-1.5 bg-primary-container text-white rounded-xl text-xs font-semibold">Chấm</button>
                                </form>
                            @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-50 text-secondary text-[10px] font-bold border border-blue-200">
                                    Đã nộp
                                </span>
                                <form action="{{ route('portal.teacher.submissions.mark', ['id' => $sub->id]) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="px-3.5 py-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-800 rounded-xl text-xs font-semibold transition flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[16px] text-emerald-600">visibility</span>
                                        <span>Đánh dấu đã xem</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach

                <!-- Mock Students From Prototype (Exact Match BA) -->
                <!-- Student 1 -->
                <div class="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-gray-50/70 transition">
                    <div class="flex items-center gap-3 flex-1">
                        <img class="w-12 h-12 rounded-full object-cover border border-gray-200"
                             src="https://images.unsplash.com/photo-1544717305-2782549b5136?w=100&h=100&fit=crop" alt="Avatar">
                        <div>
                            <h4 class="text-xs font-bold text-gray-900">Nguyễn Văn An</h4>
                            <p class="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5 font-mono">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                nộp lúc 14:30, Hôm nay
                            </p>
                        </div>
                    </div>
                    <div class="flex-1 flex items-center gap-3">
                        <div class="w-16 h-12 bg-gray-800 rounded-lg flex items-center justify-center shrink-0 text-white relative shadow-2xs">
                            <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                        </div>
                        <div class="text-xs">
                            <span class="font-bold text-gray-800 block">video_bai_tap_1.mp4</span>
                            <span class="text-[10px] text-gray-400">Thời lượng 1:45 • MP4</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-50 text-secondary text-[10px] font-bold border border-blue-200">
                            Đã nộp
                        </span>
                        <button type="button" onclick="alert('Đã đánh dấu đã xem bài làm của Nguyễn Văn An!')" class="px-3.5 py-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-800 rounded-xl text-xs font-semibold transition flex items-center gap-1 shadow-2xs">
                            <span class="material-symbols-outlined text-[16px] text-emerald-600">visibility</span>
                            <span>Đánh dấu đã xem</span>
                        </button>
                    </div>
                </div>

                <!-- Student 2 -->
                <div class="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-gray-50/70 transition">
                    <div class="flex items-center gap-3 flex-1">
                        <img class="w-12 h-12 rounded-full object-cover border border-gray-200"
                             src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=100&h=100&fit=crop" alt="Avatar">
                        <div>
                            <h4 class="text-xs font-bold text-gray-900">Vũ Thị Minh Hằng</h4>
                            <p class="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5 font-mono">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                nộp lúc 16:15, Hôm nay
                            </p>
                        </div>
                    </div>
                    <div class="flex-1 flex items-center gap-3">
                        <div class="w-16 h-12 bg-gray-800 rounded-lg flex items-center justify-center shrink-0 text-white relative shadow-2xs">
                            <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                        </div>
                        <div class="text-xs">
                            <span class="font-bold text-gray-800 block">speaking_unit4.mov</span>
                            <span class="text-[10px] text-gray-400">Thời lượng 2:10 • MOV</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-blue-50 text-secondary text-[10px] font-bold border border-blue-200">
                            Đã nộp
                        </span>
                        <button type="button" onclick="alert('Đã đánh dấu đã xem bài làm của Vũ Thị Minh Hằng!')" class="px-3.5 py-1.5 bg-white border border-gray-200 hover:bg-gray-50 text-gray-800 rounded-xl text-xs font-semibold transition flex items-center gap-1 shadow-2xs">
                            <span class="material-symbols-outlined text-[16px] text-emerald-600">visibility</span>
                            <span>Đánh dấu đã xem</span>
                        </button>
                    </div>
                </div>

                <!-- Student 3 -->
                <div class="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-gray-50/70 transition">
                    <div class="flex items-center gap-3 flex-1">
                        <img class="w-12 h-12 rounded-full object-cover border border-gray-200"
                             src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100&h=100&fit=crop" alt="Avatar">
                        <div>
                            <h4 class="text-xs font-bold text-gray-900">Trần Hồng Sơn</h4>
                            <p class="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5 font-mono">
                                <span class="material-symbols-outlined text-[14px]">schedule</span>
                                nộp lúc 18:00, Hôm qua
                            </p>
                        </div>
                    </div>
                    <div class="flex-1 flex items-center gap-3">
                        <div class="w-16 h-12 bg-gray-800 rounded-lg flex items-center justify-center shrink-0 text-white relative shadow-2xs">
                            <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                        </div>
                        <div class="text-xs">
                            <span class="font-bold text-gray-800 block">bai_tap_son_t4.mp4</span>
                            <span class="text-[10px] text-gray-400">Thời lượng 1:15 • MP4</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-bold">
                            Đã chấm: 9/10
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
