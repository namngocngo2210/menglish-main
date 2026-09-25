<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('syllabus.documents') }}" class="p-2 rounded-xl border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition shadow-2xs">
                    <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">menu_book</span>
                        Xem tài liệu giáo trình
                    </h1>
                    <p class="text-xs text-gray-500">Tài liệu bài giảng được Học thuật chia sẻ cho giáo viên / trợ giảng.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.button variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Đề xuất sửa (Bước #5)</x-ui.button>
            </div>
        </div>
    </x-slot>

    @include('syllabus.partials.flow-header', ['activeStep' => 4])

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Danh sách tài liệu được xem, nhóm theo giáo trình / chặng --}}
        <section class="lg:col-span-4 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col min-w-0">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h2 class="text-sm font-bold text-gray-900">Tài liệu được chia sẻ</h2>
                <x-ui.badge color="primary">{{ $documents->count() }} tài liệu</x-ui.badge>
            </div>
            <div class="overflow-y-auto p-4 space-y-4 flex-1 custom-scrollbar max-h-[720px]">
                @forelse ($documents->groupBy(fn ($d) => $d->curriculum?->title.($d->stage_name ? ' · '.$d->stage_name : '')) as $group => $docs)
                    <div class="space-y-2.5">
                        <h3 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider pl-1">{{ $group }}</h3>
                        @foreach ($docs as $doc)
                            <a href="{{ route('syllabus.teacher-view', ['document' => $doc->id]) }}"
                               class="block p-3.5 rounded-xl border transition-all {{ $selected?->id === $doc->id ? 'bg-orange-50/50 border-primary-container' : 'bg-white border-gray-200 hover:bg-gray-50' }}">
                                <div class="flex items-start gap-3">
                                    <div class="bg-white p-2 rounded-lg text-primary border border-gray-100">
                                        <span class="material-symbols-outlined text-[20px]">{{ $doc->icon }}</span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-xs font-bold text-gray-900 truncate mb-1">{{ $doc->title }}</h4>
                                        <div class="flex items-center gap-2 text-[11px] text-gray-500">
                                            <span class="font-mono uppercase">{{ $doc->extension }} • {{ $doc->size_human }}</span>
                                            <span class="text-gray-300">•</span>
                                            @if ($doc->downloadable)
                                                <span class="text-emerald-600 font-medium">Có thể tải</span>
                                            @else
                                                <span class="text-rose-600 font-medium">Chỉ xem</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @empty
                    <x-ui.empty-state icon="folder_off" title="Chưa có tài liệu nào" description="Học thuật chưa chia sẻ tài liệu nào cho vai trò của bạn." />
                @endforelse
            </div>
        </section>

        {{-- Trình xem tài liệu --}}
        <section class="lg:col-span-8 bg-white rounded-2xl border border-gray-200 shadow-sm flex flex-col overflow-hidden min-w-0">
            @if ($selected)
                <div class="p-4 border-b border-gray-100 flex flex-wrap justify-between items-center bg-gray-50/50 gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-8 h-8 rounded-lg bg-orange-100 text-primary flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px]">{{ $selected->icon }}</span>
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-xs font-bold text-gray-900 truncate">{{ $selected->title }}</h2>
                            <p class="text-[10px] text-gray-400">{{ $selected->curriculum?->title }} · {{ $selected->stage_name ?: 'Chưa gắn chặng' }} · {{ $selected->size_human }}</p>
                        </div>
                    </div>
                    @if ($selected->canDownload(auth()->user()))
                        <x-ui.button variant="secondary" size="sm" icon="download" :href="route('syllabus.documents.file', ['id' => $selected->id, 'download' => 1])">Tải về</x-ui.button>
                    @endif
                </div>
                @php($fileUrl = route('syllabus.documents.file', $selected->id))
                <div class="flex-1 bg-slate-100 flex justify-center items-center p-4 relative min-h-[560px]">
                    @switch($selected->kind)
                        @case('pdf')
                            <iframe src="{{ $fileUrl }}#toolbar=0" class="w-full h-[680px] rounded-xl bg-white border border-gray-200" title="{{ $selected->title }}"></iframe>
                            @break
                        @case('image')
                            <img src="{{ $fileUrl }}" alt="{{ $selected->title }}" class="max-h-[680px] rounded-xl border border-gray-200 bg-white">
                            @break
                        @case('audio')
                            <audio controls controlsList="nodownload" src="{{ $fileUrl }}" class="w-full max-w-xl"></audio>
                            @break
                        @case('video')
                            <video controls controlsList="nodownload" src="{{ $fileUrl }}" class="w-full max-h-[680px] rounded-xl bg-black"></video>
                            @break
                        @default
                            <x-ui.empty-state icon="{{ $selected->icon }}" title="Định dạng {{ strtoupper($selected->extension) }} không xem trực tuyến được"
                                              description="{{ $selected->canDownload(auth()->user()) ? 'Bấm Tải về để mở bằng phần mềm tương ứng.' : 'Tài liệu này không cho phép tải về; liên hệ Học thuật nếu cần bản xem được.' }}" />
                    @endswitch
                </div>
                @unless ($selected->downloadable)
                    <div class="bg-white p-3.5 border-t border-gray-100 text-xs text-gray-500 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[16px] text-amber-600">lock</span>
                        Tài liệu chỉ xem trực tuyến để bảo vệ bản quyền giáo trình.
                    </div>
                @endunless
            @else
                <x-ui.empty-state icon="menu_book" title="Chưa chọn tài liệu" description="Chọn một tài liệu ở danh sách bên trái để xem." class="flex-1" />
            @endif
        </section>
    </div>

    {{-- Chặng đang mở của lớp: Chặng → Unit → Buổi, buổi đang dạy --}}
    <section class="mt-6 bg-white rounded-2xl border border-gray-200 shadow-sm p-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">list_alt</span>
                Nội dung giảng dạy theo chặng
            </h2>
            @if ($classes->isNotEmpty())
                <form method="GET" action="{{ route('syllabus.teacher-view') }}">
                    @if ($selected)<input type="hidden" name="document" value="{{ $selected->id }}">@endif
                    <x-ui.select name="class" inline-label="Lớp" :value="$class?->id" :options="$classes->pluck('name', 'id')" onchange="this.form.submit()" />
                </form>
            @endif
        </div>

        @if (! $assignment)
            <x-ui.empty-state icon="school" title="Chưa có lớp nào đang học chặng" description="Học thuật mở chặng cho lớp ở màn Chặng học của lớp; nội dung buổi học sẽ hiện ở đây." />
        @else
            {{-- Tiến trình các chặng của giáo trình --}}
            <ol class="flex flex-wrap gap-2 text-[11px]">
                @foreach ($stages as $s)
                    @php($state = $s->id === $assignment->stage_id ? 'open' : ($closedStageIds->contains($s->id) ? 'done' : 'todo'))
                    <li class="px-2.5 py-1 rounded-lg border {{ ['open' => 'border-primary-container bg-primary-container/10 text-primary font-bold', 'done' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'todo' => 'border-gray-200 text-gray-400'][$state] }}">
                        <span class="material-symbols-outlined text-[13px] align-middle">{{ ['open' => 'play_circle', 'done' => 'check_circle', 'todo' => 'lock'][$state] }}</span>
                        {{ $s->label }}
                    </li>
                @endforeach
            </ol>

            @php($current = $position['current'])
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                <div class="rounded-xl border border-gray-200 p-3">
                    <p class="text-[10px] uppercase font-bold text-gray-400">Chặng đang học</p>
                    <p class="font-bold text-gray-900">{{ $assignment->stage?->label ?? $assignment->stage_name }}</p>
                    <p class="text-gray-500">{{ $assignment->curriculum?->title }} · mở {{ ($assignment->opened_at ?? $assignment->created_at)?->format('d/m/Y') }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-3">
                    <p class="text-[10px] uppercase font-bold text-gray-400">Tiến độ</p>
                    <p class="font-bold text-gray-900">Đã dạy {{ $position['taught'] }} / {{ $position['lessons']->count() }} buổi của chặng</p>
                    @if ($assignment->extra_sessions)
                        <p class="text-amber-700">+{{ $assignment->extra_sessions }} buổi giãn tiến độ đã duyệt</p>
                    @endif
                </div>
                <div class="rounded-xl border border-gray-200 p-3">
                    <p class="text-[10px] uppercase font-bold text-gray-400">Big Test cuối chặng</p>
                    <p class="font-bold text-purple-700">{{ $assignment->stage?->big_test_title ?: 'Big Test cuối chặng' }}</p>
                    <p class="text-gray-500">Chặng đóng khi kết quả được duyệt và gửi phụ huynh.</p>
                </div>
            </div>

            @if ($current)
                <x-ui.alert type="info" title="Buổi tiếp theo: Buổi {{ $current->session_no }} — Unit {{ $current->unit?->unit_number }}">{{ $current->title }}</x-ui.alert>
            @elseif ($position['lessons']->isNotEmpty())
                <x-ui.alert type="warning">Lớp đã dạy hết {{ $position['lessons']->count() }} buổi của chặng{{ $position['over'] ? ' (vượt '.$position['over'].' buổi)' : '' }} — ôn tập và tổ chức Big Test cuối chặng, hoặc gửi yêu cầu giãn tiến độ.</x-ui.alert>
            @endif

            <div class="space-y-3">
                @forelse ($assignment->stage?->units()->with('lessons')->get() ?? [] as $u)
                    <div class="border border-gray-200 rounded-xl">
                        <p class="px-4 py-2.5 text-xs font-bold text-gray-900 bg-gray-50/60 rounded-t-xl">Unit {{ $u->unit_number }}: {{ $u->title }}</p>
                        <div class="divide-y divide-gray-100">
                            @forelse ($u->lessons as $lesson)
                                <details class="px-4 py-2.5 text-xs {{ $current?->id === $lesson->id ? 'bg-orange-50/60' : '' }}" @if ($current?->id === $lesson->id) open @endif>
                                    <summary class="font-semibold text-gray-800 cursor-pointer">
                                        Buổi {{ $lesson->session_no }}: {{ $lesson->title }}
                                        @if ($current?->id === $lesson->id)<x-ui.badge color="primary">Buổi tiếp theo</x-ui.badge>@endif
                                    </summary>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3 text-gray-700">
                                        <p class="whitespace-pre-line"><span class="font-semibold">Mục tiêu:</span> {{ $lesson->objectives ?: '—' }}</p>
                                        <p class="whitespace-pre-line"><span class="font-semibold">Bài tập về nhà:</span> {{ $lesson->homework_guide ?: '—' }}</p>
                                        <p class="whitespace-pre-line"><span class="font-semibold">Từ vựng:</span> {{ $lesson->vocabulary_focus ?: '—' }}</p>
                                        <p class="whitespace-pre-line"><span class="font-semibold">Ngữ pháp:</span> {{ $lesson->grammar_focus ?: '—' }}</p>
                                    </div>
                                </details>
                            @empty
                                <p class="px-4 py-2.5 text-[11px] text-gray-400">Unit chưa có buổi học.</p>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">Chặng chưa có unit / buổi học nào.</p>
                @endforelse
            </div>
        @endif
    </section>
</x-app-layout>
