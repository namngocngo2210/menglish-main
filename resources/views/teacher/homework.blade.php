{{-- Giao bài tập về nhà (mockup 03_Cong_Giao_Vien/03_giao_bai_tap_ve_nha): Thông tin chung (buổi học, hạn nộp, ghi chú, tài liệu
     tham khảo) + Hạng mục bài tập (≥ 1, mỗi hạng mục có yêu cầu chi tiết; hạng mục đã có học sinh nộp thì khóa). --}}
@php
    $categories = \App\Models\Homework::CATEGORIES;
    $locked = $editing ? ($lockedTypes[$editing->id] ?? []) : [];
    $picked = old('categories', $editing ? array_keys($editing->items ?? []) : []);
    $picked = array_values(array_unique(array_merge($picked, $locked)));
    $itemTexts = old('items', $editing?->items ?? []);
    $input = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';
    $dueDefault = $editing?->due_at?->format('Y-m-d\TH:i') ?? ($editing?->due_date ? $editing->due_date->format('Y-m-d').'T23:59' : now()->addDays(3)->format('Y-m-d').'T20:00');
@endphp
<x-app-layout title="Giao bài tập về nhà — {{ $class->name }}">
    <div class="mx-auto max-w-4xl space-y-lg pb-24 md:pb-0">
        <header>
            <a href="{{ route('teacher.home') }}" class="mb-xs inline-flex items-center gap-xs font-body-small text-body-small text-on-surface-variant hover:text-primary">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_back</span> Về lịch dạy
            </a>
            <h1 class="font-h2 text-h2 text-on-surface">{{ $editing ? 'Sửa bài tập về nhà' : 'Giao bài tập về nhà' }}</h1>
            <p class="mt-xs inline-flex items-center gap-xs rounded-full bg-secondary-fixed/50 px-md py-[2px] font-body-small text-body-small text-on-secondary-fixed">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true">info</span> Lớp {{ $class->name }} <span class="font-code">({{ $class->code }})</span>
            </p>
        </header>

        @if ($errors->any())
            <x-ui.alert type="error">{{ $errors->first() }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ $editing ? route('teacher.homework.update', [$class->id, $editing->id]) : route('teacher.homework.store', $class->id) }}"
              enctype="multipart/form-data" class="space-y-lg"
              x-data="{ picked: @js($picked), locked: @js($locked), toggle(k) { if (this.locked.includes(k)) return; this.picked.includes(k) ? this.picked = this.picked.filter(x => x !== k) : this.picked.push(k); } }">
            @csrf
            @if ($editing) @method('PUT') @endif

            {{-- 1. Thông tin chung --}}
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
                <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">info</span> Thông tin chung</h2>
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <x-ui.field label="Buổi học" name="class_session_id" for="hw_session" required>
                        <select id="hw_session" name="class_session_id" required class="{{ $input }}">
                            <option value="">Chọn buổi học...</option>
                            @foreach ($sessions as $s)
                                @php $lesson = $lessons[$s->id] ?? null; @endphp
                                <option value="{{ $s->id }}" @selected((string) $selectedSessionId === (string) $s->id)>
                                    {{ $lesson ? 'Buổi '.$lesson['no'].': ' : '' }}{{ $s->date->format('d/m') }} {{ $s->start_time?->format('H:i') }}{{ $lesson['title'] ?? null ? ' — '.$lesson['title'] : ($lesson['unit'] ?? null ? ' — '.$lesson['unit'] : '') }}{{ $s->type === \App\Models\ClassSession::TYPE_MAKEUP ? ' (học bù)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field label="Hạn nộp" name="due_at" for="hw_due" required>
                        <input id="hw_due" type="datetime-local" name="due_at" required value="{{ old('due_at', $dueDefault) }}" class="{{ $input }}">
                    </x-ui.field>
                </div>
                <x-ui.textarea name="class_note" label="Ghi chú nhắc nhở cả lớp (Không bắt buộc)" rows="2" :value="$editing?->class_note"
                    placeholder="Ví dụ: Các em nhớ làm bài tập trước 12h trưa chủ nhật nhé..." />
                <div class="space-y-sm rounded-lg bg-surface-container-low p-md">
                    <p class="font-body-medium text-body-medium text-on-surface">Tài liệu tham khảo (Không bắt buộc)</p>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <x-ui.input name="youtube_url" type="url" label="Link YouTube nghe mẫu" icon="smart_display" :value="$editing?->youtube_url" placeholder="https://youtube.com/..." />
                        <x-ui.field label="File nghe đính kèm" name="audio" for="hw_audio" :hint="$editing?->audio_path ? 'Đã có file — chọn file mới để thay' : null">
                            <input id="hw_audio" type="file" name="audio" accept="audio/*" class="block w-full font-body-small text-body-small text-on-surface-variant file:mr-sm file:rounded-lg file:border-0 file:bg-primary-container/10 file:px-md file:py-xs file:font-semibold file:text-primary">
                        </x-ui.field>
                        <x-ui.input name="quizizz_url" type="url" label="Link Quizizz luyện thêm" icon="quiz" :value="$editing?->quizizz_url" placeholder="https://quizizz.com/..." />
                    </div>
                </div>
            </section>

            {{-- 2. Hạng mục bài tập --}}
            <section class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm md:p-lg">
                <div class="flex items-center justify-between gap-sm">
                    <h2 class="flex items-center gap-xs font-h3 text-h3 text-on-surface"><span class="material-symbols-outlined text-primary-container" aria-hidden="true">assignment_add</span> Hạng mục bài tập <span class="text-error">*</span></h2>
                    <span class="font-caption text-caption text-on-surface-variant">Chọn ít nhất 1 hạng mục</span>
                </div>
                <div class="grid grid-cols-2 gap-sm md:grid-cols-3">
                    @foreach ($categories as $key => [$label, $icon])
                        <label class="relative flex cursor-pointer items-center justify-between gap-sm rounded-lg border p-md transition"
                               :class="picked.includes('{{ $key }}') ? 'border-primary-container bg-primary-container/5' : 'border-outline-variant hover:bg-surface-container-low'"
                               @if (in_array($key, $locked, true)) title="Hạng mục này đã có học sinh nộp bài, không thể chỉnh sửa phân loại." @endif>
                            <span class="flex items-center gap-sm">
                                <input type="checkbox" name="categories[]" value="{{ $key }}" :checked="picked.includes('{{ $key }}')" x-on:change="toggle('{{ $key }}')"
                                       @disabled(in_array($key, $locked, true)) class="rounded border-outline-variant text-primary-container focus:ring-primary-container/40">
                                <span class="font-body-medium text-body-medium text-on-surface">{{ $label }}</span>
                            </span>
                            <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">{{ in_array($key, $locked, true) ? 'lock' : $icon }}</span>
                            @if (in_array($key, $locked, true))
                                <input type="hidden" name="categories[]" value="{{ $key }}">
                                <span class="absolute -top-2 right-2 rounded bg-amber-100 px-xs font-caption text-caption font-semibold text-amber-800">Đã có học sinh nộp</span>
                            @endif
                        </label>
                    @endforeach
                </div>

                <div class="space-y-sm">
                    @foreach ($categories as $key => [$label, $icon, $placeholder])
                        <div x-show="picked.includes('{{ $key }}')" x-cloak class="rounded-lg border border-outline-variant p-md">
                            <div class="mb-sm flex items-center justify-between">
                                <h4 class="flex items-center gap-xs font-body-semibold text-body-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary-container" aria-hidden="true">{{ $icon }}</span> Yêu cầu: {{ $label }}</h4>
                                @if (in_array($key, $locked, true))
                                    <span class="material-symbols-outlined text-[18px] text-on-surface-variant" title="Không thể xóa do đã có người nộp" aria-hidden="true">lock</span>
                                @else
                                    <button type="button" class="rounded p-xs text-on-surface-variant hover:bg-surface-container-high" x-on:click="toggle('{{ $key }}')" aria-label="Bỏ hạng mục {{ $label }}"><span class="material-symbols-outlined text-[18px]">close</span></button>
                                @endif
                            </div>
                            <textarea name="items[{{ $key }}]" rows="2" placeholder="{{ $placeholder }}" :required="picked.includes('{{ $key }}')" :disabled="!picked.includes('{{ $key }}')"
                                      class="{{ $input }} {{ $errors->has('items.'.$key) ? 'border-error' : '' }}">{{ $itemTexts[$key] ?? '' }}</textarea>
                            @error('items.'.$key)<p class="mt-xs font-caption text-caption text-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <p class="flex items-center gap-xs font-caption text-caption text-error" x-show="picked.length === 0" x-cloak>
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">error</span> Cần giao ít nhất 1 hạng mục.
                </p>
            </section>

            <footer class="flex justify-end gap-sm">
                <x-ui.button variant="secondary" :href="$editing ? route('teacher.homework', $class->id) : route('teacher.home')">Hủy</x-ui.button>
                <x-ui.button type="submit" icon="save" x-bind:disabled="picked.length === 0">Lưu bài tập</x-ui.button>
            </footer>
        </form>

        {{-- Bài tập đã giao --}}
        <section class="space-y-sm">
            <h2 class="font-label-caps text-label-caps uppercase text-on-surface-variant">Bài tập đã giao ({{ $homeworks->count() }})</h2>
            @forelse ($homeworks as $hw)
                @php $hwLocked = $lockedTypes[$hw->id] ?? []; @endphp
                <div class="flex items-start justify-between gap-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm">
                    <div class="min-w-0 space-y-xs">
                        <div class="font-body-semibold text-body-semibold text-on-surface">{{ $hw->title }}</div>
                        <div class="font-caption text-caption text-on-surface-variant">
                            Hạn nộp: {{ $hw->due_at?->format('H:i d/m/Y') ?? $hw->due_date?->format('d/m/Y') ?? 'Không giới hạn' }}
                            @if ($hw->classSession) · Buổi {{ $hw->classSession->date->format('d/m') }} @endif
                            · GV: {{ $hw->teacher?->name }}
                        </div>
                        @if ($hw->items)
                            <div class="flex flex-wrap gap-xs">
                                @foreach ($hw->items as $key => $text)
                                    <x-ui.badge :color="in_array($key, $hwLocked, true) ? 'warning' : 'secondary'" :dot="false" title="{{ $text }}">{{ $categories[$key][0] ?? $key }}{{ in_array($key, $hwLocked, true) ? ' · đã có bài nộp' : '' }}</x-ui.badge>
                                @endforeach
                            </div>
                        @elseif ($hw->description)
                            <p class="whitespace-pre-line font-body-small text-body-small text-on-surface-variant">{{ $hw->description }}</p>
                        @endif
                        @if ($hw->class_note)<p class="font-body-small text-body-small italic text-on-surface-variant">“{{ $hw->class_note }}”</p>@endif
                        <div class="flex flex-wrap gap-sm font-caption text-caption">
                            @if ($hw->youtube_url)<a href="{{ $hw->youtube_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">smart_display</span>YouTube</a>@endif
                            @if ($hw->audio_path)<a href="{{ asset('storage/'.$hw->audio_path) }}" target="_blank" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">audio_file</span>File nghe</a>@endif
                            @if ($hw->quizizz_url)<a href="{{ $hw->quizizz_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-xs text-primary hover:underline"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">quiz</span>Quizizz</a>@endif
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-xs">
                        <x-ui.button variant="ghost" icon="edit" :href="route('teacher.homework', ['classId' => $class->id, 'edit' => $hw->id])" title="Sửa" aria-label="Sửa {{ $hw->title }}" />
                        @if ($hwLocked === [])
                            <form method="POST" action="{{ route('teacher.homework.destroy', [$class->id, $hw->id]) }}" onsubmit="return confirm('Xoá bài tập này?');">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="danger-text" icon="delete" title="Xoá" aria-label="Xoá {{ $hw->title }}" />
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-outline-variant bg-surface-container-lowest">
                    <x-ui.empty-state icon="assignment" title="Chưa giao bài tập nào" description="Bài tập đã giao cho lớp sẽ hiện ở đây." />
                </div>
            @endforelse
        </section>
    </div>

    @include('teacher.partials.bottom-nav')
</x-app-layout>
