<x-app-layout>
    <x-ui.page-header title="Bài nộp của lớp" icon="video_library" :back="route('syllabus.teacher-view')">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="upload_file" :href="route('portal.student.homework')">Xem giao diện Học sinh nộp bài</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    

    <div class="max-w-5xl mx-auto space-y-6">
        {{-- Class Selector & Subheader --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-on-surface">Bài nộp của lớp</h2>
                <p class="text-xs text-on-surface-variant mt-0.5">Lớp: <strong class="text-on-surface">{{ $class?->name ?? 'Chưa có lớp' }}</strong></p>
            </div>

            <x-ui.select inline-label="Chọn lớp dạy:"
                         onchange="window.location.href = '{{ route('portal.teacher.submissions') }}/' + this.value">
                @foreach($classes as $c)
                    <option value="{{ $c->id }}" {{ ($class && $class->id === $c->id) ? 'selected' : '' }}>
                        {{ $c->name }} ({{ $c->code }})
                    </option>
                @endforeach
            </x-ui.select>
        </div>

        {{-- Filter Chips --}}
        <div class="flex flex-wrap gap-2">
            @php
                $types = [
                    'video' => ['label' => 'Quay video', 'icon' => 'videocam'],
                    'vocabulary' => ['label' => 'Viết từ vựng', 'icon' => 'edit_document'],
                    'workbook' => ['label' => 'Workbook', 'icon' => 'menu_book'],
                    'extra_book' => ['label' => 'Sách bổ trợ', 'icon' => 'library_books'],
                    'bgd_book' => ['label' => 'Sách bộ giáo dục', 'icon' => 'import_contacts'],
                    'quiz' => ['label' => 'Quiz', 'icon' => 'quiz'],
                    'pronunciation' => ['label' => 'Phát âm', 'icon' => 'mic'],
                ];
            @endphp

            @foreach($types as $k => $v)
                <a href="{{ route('portal.teacher.submissions', ['classId' => $class?->id, 'type' => $k]) }}"
                   class="px-4 py-2 rounded-full text-xs font-bold transition flex items-center gap-1.5 {{ $activeTab === $k ? 'bg-primary-container text-white shadow-2xs' : 'bg-surface-container-lowest text-on-surface-variant hover:bg-surface-container-low border border-surface-container-highest' }}">
                    <span class="material-symbols-outlined text-[16px]">{{ $v['icon'] }}</span>
                    <span>{{ $v['label'] }}</span>
                </a>
            @endforeach
        </div>

        {{-- Submitted Section --}}
        <div class="bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-surface-container-highest bg-surface-container-low flex items-center justify-between">
                <h3 class="text-sm font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">check_circle</span>
                    Danh sách đã nộp bài ({{ $submissions->count() }})
                </h3>
            </div>

            <div class="divide-y divide-surface-container-highest">
                {{-- If real database submissions exist --}}
                @foreach($submissions as $sub)
                    <div class="p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-primary-container/10 transition">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="w-12 h-12 rounded-full bg-primary-container/10 text-primary flex items-center justify-center font-bold text-sm shrink-0 border border-primary-container/20">
                                {{ mb_substr($sub->data['student_name'] ?? 'HV', 0, 2) }}
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-on-surface">{{ $sub->data['student_name'] ?? 'Học viên' }}</h4>
                                <p class="text-[11px] text-on-surface-variant/70 flex items-center gap-1 mt-0.5 font-mono">
                                    <span class="material-symbols-outlined text-[14px]">schedule</span>
                                    nộp lúc {{ $sub->data['submitted_at'] ?? $sub->created_at->format('H:i, d/m/Y') }}
                                </p>
                                @if(!empty($sub->data['notes']))
                                    <p class="text-[11px] text-on-surface-variant italic mt-1">"{{ $sub->data['notes'] }}"</p>
                                @endif
                            </div>
                        </div>

                        {{-- Media Preview Box --}}
                        <div class="flex-1 flex items-center gap-3">
                            <div class="w-16 h-12 bg-inverse-surface rounded-lg flex items-center justify-center shrink-0 text-white relative shadow-2xs overflow-hidden">
                                <span class="material-symbols-outlined text-[20px]">play_arrow</span>
                            </div>
                            <div class="text-xs">
                                <span class="font-bold text-on-surface block truncate max-w-[180px]">
                                    @if(!empty($sub->data['attachment_path']))<a href="{{ $sub->data['attachment_path'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $sub->data['attachment_name'] ?? 'Tệp đính kèm' }}</a>@else Không có tệp đính kèm @endif
                                </span>
                                <span class="text-[10px] text-primary font-bold uppercase">{{ $sub->data['homework_label'] ?? 'Bài nộp' }}</span>
                            </div>
                        </div>

                        {{-- Status & Action Buttons --}}
                        <div class="flex items-center gap-3">
                            @if($sub->status === 'reviewed')
                                <x-ui.badge color="success" :pill="true" :dot="false">
                                    <span class="material-symbols-outlined text-[14px] mr-0.5">done_all</span>
                                    {{ filled($sub->data['score'] ?? null) ? 'Đã chấm: '.$sub->data['score'] : 'Đã xem' }}
                                </x-ui.badge>
                            @elseif($activeTab === 'pronunciation')
                                <form action="{{ route('portal.teacher.submissions.mark', ['id' => $sub->id]) }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    <input type="text" name="score" required maxlength="20" placeholder="Điểm /100" class="w-24 rounded-lg border-outline-variant bg-surface-container-lowest text-xs py-1.5 text-on-surface focus:border-primary-container focus:ring-primary-container/20">
                                    <input type="text" name="feedback" maxlength="1000" placeholder="Nhận xét" class="w-40 rounded-lg border-outline-variant bg-surface-container-lowest text-xs py-1.5 text-on-surface focus:border-primary-container focus:ring-primary-container/20">
                                    <x-ui.button type="submit" size="sm">Chấm</x-ui.button>
                                </form>
                            @else
                                <x-ui.badge color="secondary" :pill="true">
                                    Đã nộp
                                </x-ui.badge>
                                <form action="{{ route('portal.teacher.submissions.mark', ['id' => $sub->id]) }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    <input type="text" name="score" maxlength="20" placeholder="Điểm (tùy chọn)" class="w-24 rounded-lg border-outline-variant bg-surface-container-lowest text-xs py-1.5 text-on-surface focus:border-primary-container focus:ring-primary-container/20">
                                    <input type="text" name="feedback" maxlength="1000" placeholder="Nhận xét" class="w-36 rounded-lg border-outline-variant bg-surface-container-lowest text-xs py-1.5 text-on-surface focus:border-primary-container focus:ring-primary-container/20">
                                    <x-ui.button type="submit" variant="secondary" size="sm" icon="visibility">
                                        <span>Đánh dấu đã xem</span>
                                    </x-ui.button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach

                @if($submissions->isEmpty())
                    <x-ui.empty-state icon="inbox" :title="$class ? 'Chưa có học viên nào nộp bài loại này.' : 'Bạn chưa được phân công lớp nào.'" />
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
