<x-app-layout>
    <x-ui.page-header title="Xem tài liệu giáo trình" :back="route('syllabus.documents')">
        <x-slot:breadcrumbs>
            <span class="material-symbols-outlined text-[16px]">menu_book</span>
            <span>Giáo trình &amp; Tài liệu</span>
            @if ($overviewCurriculum)
                <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                <span class="font-semibold text-on-surface">{{ $overviewCurriculum->title }}</span>
            @endif
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <form method="GET" action="{{ route('syllabus.teacher-view') }}" class="flex items-center gap-2">
                @if ($class)<input type="hidden" name="class" value="{{ $class->id }}">@endif
                <x-ui.input name="q" icon="search" :value="$search" placeholder="Tìm kiếm tài liệu..." />
                <x-ui.button type="submit" variant="secondary" icon="filter_list">Lọc</x-ui.button>
            </form>
            <x-ui.button variant="secondary" icon="edit_attributes" :href="route('syllabus.teacher-propose')">Đề xuất sửa</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>


    @php($user = auth()->user())
    {{-- Mockup 03_Cong_Giao_Vien/08: 3 tab Tài liệu / Tổng quan syllabus / Nội dung buổi học. --}}
    <div x-data="{ tab: @js(in_array(request('tab'), ['docs', 'overview', 'lessons'], true) ? request('tab') : ($class && ! $documents->count() ? 'lessons' : 'docs')) }" class="space-y-6">
        <nav class="no-scrollbar flex items-center gap-lg overflow-x-auto border-b border-surface-container-highest" role="tablist">
            @foreach (['docs' => 'Tài liệu', 'overview' => 'Tổng quan syllabus', 'lessons' => 'Nội dung buổi học'] as $key => $label)
                <button type="button" role="tab" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'border-primary-container font-semibold text-primary' : 'border-transparent text-on-surface-variant hover:text-primary'"
                        class="-mb-px inline-flex shrink-0 items-center gap-xs whitespace-nowrap border-b-2 px-sm py-md font-body-medium text-body-medium transition-colors">{{ $label }}</button>
            @endforeach
        </nav>

        {{-- Tab 1: Tài liệu (danh sách theo chặng + khung xem trực tuyến) --}}
        <div x-show="tab === 'docs'" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <section class="lg:col-span-4 bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm overflow-hidden flex flex-col min-w-0">
                <div class="p-md border-b border-outline-variant flex justify-between items-center bg-surface-container-low">
                    <h2 class="font-h3 text-h3 text-on-surface">Danh sách tài liệu</h2>
                    <x-ui.badge color="primary">{{ $documents->count() }} tài liệu</x-ui.badge>
                </div>
                <div class="overflow-y-auto p-md space-y-md flex-1 custom-scrollbar max-h-[720px]">
                    @forelse ($documents->groupBy(fn ($d) => $d->curriculum?->title.($d->stage_label ? ' · '.$d->stage_label : '')) as $group => $docs)
                        <div class="space-y-sm">
                            <h3 class="font-label text-label text-on-surface-variant uppercase tracking-wider pl-1">{{ $group }}</h3>
                            @foreach ($docs as $doc)
                                <a href="{{ route('syllabus.teacher-view', array_filter(['document' => $doc->id, 'class' => $class?->id, 'q' => $search])) }}"
                                   class="relative block p-md rounded-lg border transition-all {{ $selected?->id === $doc->id ? 'bg-primary-fixed/30 border-primary-container' : 'bg-surface-container-lowest border-outline-variant hover:bg-surface-container-low' }}">
                                    @if ($selected?->id === $doc->id)<span class="absolute left-0 top-0 bottom-0 w-1 rounded-l-lg bg-primary-container"></span>@endif
                                    <div class="flex items-start gap-3">
                                        <div class="bg-surface-container-lowest p-2 rounded-lg text-primary border border-outline-variant">
                                            <span class="material-symbols-outlined text-[20px]">{{ $doc->icon }}</span>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h4 class="font-body-medium text-body-medium text-on-surface truncate mb-1">{{ $doc->title }}</h4>
                                            <div class="flex flex-wrap items-center gap-2 font-caption text-caption text-on-surface-variant">
                                                <span class="inline-flex items-center gap-0.5 font-mono uppercase"><span class="material-symbols-outlined text-[14px]">description</span>{{ $doc->extension }} • {{ $doc->size_human }}</span>
                                                @if ($doc->canDownload($user))
                                                    <span class="inline-flex items-center gap-0.5 text-tertiary font-medium"><span class="material-symbols-outlined text-[14px]">download</span>Có thể tải</span>
                                                @else
                                                    <span class="inline-flex items-center gap-0.5 text-error font-medium"><span class="material-symbols-outlined text-[14px]">lock</span>Chỉ xem</span>
                                                @endif
                                                @if ($viewedIds->contains($doc->id))
                                                    <span class="inline-flex items-center gap-0.5 text-tertiary"><span class="material-symbols-outlined text-[14px]">check_circle</span>Đã xem</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @empty
                        <x-ui.empty-state icon="folder_off" :title="$search !== '' ? 'Không tìm thấy tài liệu phù hợp' : 'Chưa có tài liệu nào'" description="Học thuật chưa chia sẻ tài liệu nào cho vai trò của bạn." />
                    @endforelse
                </div>
            </section>

            <section class="lg:col-span-8 bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm flex flex-col overflow-hidden min-w-0" x-ref="viewer">
                @if ($selected)
                    @php($canDownloadSelected = $selected->canDownload($user))
                    <div class="p-md border-b border-outline-variant flex flex-wrap justify-between items-center bg-surface-container-low gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-primary-fixed text-primary flex items-center justify-center">
                                <span class="material-symbols-outlined text-[20px]">{{ $selected->icon }}</span>
                            </div>
                            <div class="min-w-0">
                                <h2 class="font-body-medium text-body-medium font-semibold text-on-surface truncate">{{ $selected->title }}</h2>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $selected->stage_label ?: 'Chưa gắn chặng' }} • {{ $selected->curriculum?->title }} • {{ $selected->size_human }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @unless ($canDownloadSelected)
                                <x-ui.badge color="warning" :dot="false" :pill="true"><span class="material-symbols-outlined text-[14px]">shield</span>Bảo mật nội dung</x-ui.badge>
                            @endunless
                            <x-ui.button variant="ghost" size="sm" icon="fullscreen" title="Toàn màn hình" @click="$refs.viewer.requestFullscreen && $refs.viewer.requestFullscreen()" />
                            @if ($canDownloadSelected)
                                <x-ui.button variant="secondary" size="sm" icon="download" :href="route('syllabus.documents.file', ['id' => $selected->id, 'download' => 1])">Tải về</x-ui.button>
                            @endif
                        </div>
                    </div>
                    @php($fileUrl = route('syllabus.documents.file', $selected->id))
                    <div class="flex-1 bg-surface-container flex justify-center items-center p-4 relative min-h-[560px] overflow-hidden">
                        @switch($selected->kind)
                            @case('pdf')
                                <iframe src="{{ $fileUrl }}#toolbar=0" class="w-full h-[680px] rounded-xl bg-surface-container-lowest border border-surface-container-highest" title="{{ $selected->title }}"></iframe>
                                @break
                            @case('image')
                                <img src="{{ $fileUrl }}" alt="{{ $selected->title }}" class="max-h-[680px] rounded-xl border border-surface-container-highest bg-surface-container-lowest">
                                @break
                            @case('audio')
                                <audio controls controlsList="nodownload" src="{{ $fileUrl }}" class="w-full max-w-xl"></audio>
                                @break
                            @case('video')
                                <video controls controlsList="nodownload" src="{{ $fileUrl }}" class="w-full max-h-[680px] rounded-xl bg-black"></video>
                                @break
                            @default
                                <x-ui.empty-state icon="{{ $selected->icon }}" title="Định dạng {{ strtoupper($selected->extension) }} không xem trực tuyến được"
                                                  description="{{ $canDownloadSelected ? 'Bấm Tải về để mở bằng phần mềm tương ứng.' : 'Tài liệu này không cho phép tải về; liên hệ Học thuật nếu cần bản xem được.' }}" />
                        @endswitch
                        @unless ($canDownloadSelected)
                            {{-- Watermark bảo mật: tên đăng nhập người xem, không chặn thao tác cuộn/xem. --}}
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-around opacity-[0.08] select-none" aria-hidden="true">
                                <p class="font-h1 text-h1 -rotate-12 tracking-widest">MENGLISH INTERNAL ONLY</p>
                                <p class="font-h2 text-h2 -rotate-12 uppercase">{{ $user->email }}</p>
                                <p class="font-h1 text-h1 -rotate-12 tracking-widest">MENGLISH INTERNAL ONLY</p>
                            </div>
                        @endunless
                    </div>
                    <div class="bg-surface-container-lowest p-md border-t border-outline-variant flex flex-wrap items-center justify-between gap-3">
                        <p class="font-body-small text-body-small text-on-surface-variant flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px] {{ $canDownloadSelected ? 'text-tertiary' : 'text-warning' }}">info</span>
                            {{ $canDownloadSelected ? 'Tài liệu được phép tải về.' : 'Tài liệu này không hỗ trợ tải về để bảo mật nội dung theo chính sách của MENGLISH.' }}
                        </p>
                        @if ($viewedIds->contains($selected->id))
                            <span class="inline-flex items-center gap-1 font-body-small text-body-small font-semibold text-tertiary"><span class="material-symbols-outlined text-[18px]">task_alt</span>Đã xem</span>
                        @else
                            <form method="POST" action="{{ route('syllabus.documents.viewed', $selected->id) }}">
                                @csrf
                                @if ($class)<input type="hidden" name="class" value="{{ $class->id }}">@endif
                                <x-ui.button type="submit" size="sm" icon="done_all">Đánh dấu đã xem</x-ui.button>
                            </form>
                        @endif
                    </div>
                @else
                    <x-ui.empty-state icon="menu_book" title="Chưa chọn tài liệu" description="Chọn một tài liệu ở danh sách bên trái để xem." class="flex-1" />
                @endif
            </section>
        </div>

        {{-- Tab 2: Tổng quan syllabus — lộ trình các chặng của giáo trình --}}
        <section x-show="tab === 'overview'" x-cloak class="space-y-md">
            <h2 class="font-h2 text-h2 text-on-surface">Lộ trình tổng quát{{ $overviewCurriculum ? ' — '.$overviewCurriculum->title : '' }}</h2>
            @if ($overviewStages->isEmpty())
                <x-ui.empty-state icon="route" title="Chưa có lộ trình" description="Học thuật chưa soạn chặng cho giáo trình này." />
            @else
                <div class="grid grid-cols-1 md:grid-cols-3 gap-md">
                    @foreach ($overviewStages as $s)
                        @php($state = $assignment && $s->id === $assignment->stage_id ? 'open' : ($closedStageIds->contains($s->id) ? 'done' : 'todo'))
                        <article class="rounded-xl border bg-surface-container-lowest p-lg shadow-sm flex flex-col gap-sm {{ $state === 'open' ? 'border-primary-container ring-1 ring-primary-container/20' : 'border-outline-variant' }}">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-h3 text-h3 text-on-surface">{{ $s->label }}</h3>
                                @if ($assignment)
                                    <x-ui.badge :color="['open' => 'primary', 'done' => 'success', 'todo' => 'neutral'][$state]">{{ ['open' => 'Đang học', 'done' => 'Đã xong', 'todo' => 'Chưa mở'][$state] }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="font-body-small text-body-small text-on-surface-variant flex-1">{{ $s->description ?: $s->units->count().' unit · '.$s->units->sum(fn ($u) => $u->lessons->count()).' buổi' }}</p>
                            @if ($s->overview_link)
                                <a href="{{ $s->overview_link }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-body-medium text-body-small text-primary hover:underline">Xem mục lục chặng <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>
                            @else
                                <button type="button" @click="tab = 'lessons'" class="inline-flex items-center gap-1 self-start font-body-medium text-body-small text-primary hover:underline">Xem mục lục chặng <span class="material-symbols-outlined text-[16px]">arrow_forward</span></button>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Tab 3: Nội dung buổi học — chặng đang mở của lớp: Chặng → Unit → Buổi, buổi đang dạy --}}
        <section x-show="tab === 'lessons'" x-cloak class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm p-lg space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <h2 class="font-h3 text-h3 text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">list_alt</span>
                    {{ $assignment?->stage?->label ?? 'Nội dung giảng dạy theo chặng' }}
                </h2>
                @if ($classes->isNotEmpty())
                    <form method="GET" action="{{ route('syllabus.teacher-view') }}">
                        @if ($selected)<input type="hidden" name="document" value="{{ $selected->id }}">@endif
                        <input type="hidden" name="tab" value="lessons">
                        <x-ui.select name="class" inline-label="Lớp" :value="$class?->id" :options="$classes->pluck('name', 'id')" onchange="this.form.submit()" />
                    </form>
                @endif
            </div>

            @if (! $assignment)
                <x-ui.empty-state icon="school" title="Chưa có lớp nào đang học chặng" description="Học thuật mở chặng cho lớp ở màn Giao chặng; nội dung buổi học sẽ hiện ở đây." />
            @else
                <ol class="flex flex-wrap gap-2 text-[11px]">
                    @foreach ($stages as $s)
                        @php($state = $s->id === $assignment->stage_id ? 'open' : ($closedStageIds->contains($s->id) ? 'done' : 'todo'))
                        <li class="px-2.5 py-1 rounded-lg border {{ ['open' => 'border-primary-container bg-primary-container/10 text-primary font-bold', 'done' => 'border-tertiary/30 bg-tertiary/10 text-tertiary', 'todo' => 'border-surface-container-highest text-on-surface-variant/70'][$state] }}">
                            <span class="material-symbols-outlined text-[13px] align-middle">{{ ['open' => 'play_circle', 'done' => 'check_circle', 'todo' => 'lock'][$state] }}</span>
                            {{ $s->label }}
                        </li>
                    @endforeach
                </ol>

                @php($current = $position['current'])
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                    <div class="rounded-xl border border-surface-container-highest p-3">
                        <p class="text-[10px] uppercase font-bold text-on-surface-variant/70">Chặng đang học</p>
                        <p class="font-bold text-on-surface">{{ $assignment->stage?->label ?? $assignment->stage_name }}</p>
                        <p class="text-on-surface-variant">{{ $assignment->curriculum?->title }} · mở {{ ($assignment->opened_at ?? $assignment->created_at)?->format('d/m/Y') }}</p>
                    </div>
                    <div class="rounded-xl border border-surface-container-highest p-3">
                        <p class="text-[10px] uppercase font-bold text-on-surface-variant/70">Tiến độ</p>
                        <p class="font-bold text-on-surface">Đã dạy {{ $position['taught'] }} / {{ $position['lessons']->count() }} buổi của chặng</p>
                        @if ($assignment->extra_sessions)
                            <p class="text-warning">+{{ $assignment->extra_sessions }} buổi giãn tiến độ đã duyệt</p>
                        @endif
                    </div>
                    <div class="rounded-xl border border-surface-container-highest p-3">
                        <p class="text-[10px] uppercase font-bold text-on-surface-variant/70">Big Test cuối chặng</p>
                        <p class="font-bold text-secondary">{{ $assignment->stage?->big_test_title ?: 'Big Test cuối chặng' }}</p>
                        <p class="text-on-surface-variant">Chặng đóng khi kết quả được duyệt và gửi phụ huynh.</p>
                    </div>
                </div>

                @if ($current)
                    <x-ui.alert type="info" title="Buổi tiếp theo: Buổi {{ $current->session_no }} — Unit {{ $current->unit?->unit_number }}">{{ $current->title }}</x-ui.alert>
                @elseif ($position['lessons']->isNotEmpty())
                    <x-ui.alert type="warning">Lớp đã dạy hết {{ $position['lessons']->count() }} buổi của chặng{{ $position['over'] ? ' (vượt '.$position['over'].' buổi)' : '' }} — ôn tập và tổ chức Big Test cuối chặng, hoặc gửi yêu cầu giãn tiến độ.</x-ui.alert>
                @endif

                <div class="space-y-3">
                    @forelse ($assignment->stage?->units()->with('lessons')->get() ?? [] as $u)
                        <div class="border border-outline-variant rounded-xl overflow-hidden">
                            <p class="px-4 py-2.5 font-body-medium text-body-medium font-semibold text-on-surface bg-surface-container-low">Unit {{ $u->unit_number }}: {{ $u->title }}</p>
                            <div class="divide-y divide-surface-container-highest">
                                @forelse ($u->lessons as $lesson)
                                    <details class="px-4 py-2.5 text-xs group {{ $current?->id === $lesson->id ? 'bg-primary-container/10' : '' }}" @if ($current?->id === $lesson->id) open @endif>
                                        <summary class="flex items-center justify-between gap-2 font-semibold text-on-surface cursor-pointer list-none">
                                            <span>Buổi {{ $lesson->session_no }}: {{ $lesson->title }}
                                                @if ($current?->id === $lesson->id)<x-ui.badge color="primary">Buổi tiếp theo</x-ui.badge>@endif
                                            </span>
                                            <span class="material-symbols-outlined text-[18px] text-on-surface-variant/70 group-open:rotate-180 transition">expand_more</span>
                                        </summary>
                                        <div class="space-y-1.5 mt-3 text-on-surface-variant">
                                            <p class="whitespace-pre-line"><strong>Mục tiêu:</strong> {{ $lesson->objectives ?: '—' }}</p>
                                            <p class="whitespace-pre-line"><strong>Từ vựng:</strong> {{ $lesson->vocabulary_focus ?: '—' }}</p>
                                            <p class="whitespace-pre-line"><strong>Ngữ pháp:</strong> {{ $lesson->grammar_focus ?: '—' }}</p>
                                            <p class="whitespace-pre-line"><strong>Hoạt động:</strong> {{ $lesson->content ?: '—' }}</p>
                                            <p class="whitespace-pre-line"><strong>Bài tập về nhà:</strong> {{ $lesson->homework_guide ?: '—' }}</p>
                                        </div>
                                    </details>
                                @empty
                                    <p class="px-4 py-2.5 text-[11px] text-on-surface-variant/70">Unit chưa có buổi học.</p>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <x-ui.empty-state icon="description" title="Học thuật chưa soạn nội dung cho chặng này" />
                    @endforelse
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
