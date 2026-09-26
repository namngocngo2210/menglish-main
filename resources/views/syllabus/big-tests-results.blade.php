<x-app-layout>
    <x-ui.page-header title="Duyệt kết quả Big Test & gửi phụ huynh" description="Bảng điểm 4 kỹ năng, nhận xét, link video; Học thuật duyệt và gửi kết quả cho phụ huynh qua Zalo." :back="route('syllabus.big-tests.distribution')">
        <x-slot:breadcrumbs>
            <span>Học thuật</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="font-semibold text-on-surface">Quản lý Big Test</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @if($test)
                @can('big_test.approve')
                    <form method="POST" action="{{ route('syllabus.big-tests.results.approve', $test->id) }}">@csrf
                        <x-ui.button type="submit" variant="secondary" icon="task_alt">Duyệt kết quả</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('syllabus.big-tests.send-zalo', $test->id) }}">@csrf
                        <x-ui.button type="submit" icon="send">Gửi kết quả đã duyệt</x-ui.button>
                    </form>
                @endcan
            @endif
            <x-ui.button variant="secondary" icon="print" onclick="window.print();">In bảng điểm</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-5">
        {{-- Mockup 01_Web_Admin/07: khung xét duyệt kết quả từng học viên (thông tin, điểm chi tiết, video, nhận xét, tổng điểm, hạn trả KQ, người gửi / người duyệt). --}}
        @if ($test && $selectedResult)
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm">
                        <div class="flex items-center gap-sm mb-md"><span class="material-symbols-outlined text-primary">info</span><h3 class="font-h3 text-h3 text-on-surface">Thông tin chung</h3></div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-md">
                            <div class="flex items-start gap-sm">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined">person</span></span>
                                <div>
                                    <p class="font-caption text-caption text-on-surface-variant">Học viên</p>
                                    <h4 class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $selectedResult->student?->name }}</h4>
                                    <p class="font-caption text-caption text-on-surface-variant">Mã HV: {{ $selectedResult->student?->code ?? 'HV-'.$selectedResult->student_id }}</p>
                                </div>
                            </div>
                            <div>
                                <p class="font-caption text-caption text-on-surface-variant">Lớp học</p>
                                <p class="font-body-medium text-body-medium text-on-surface">{{ $test->classModel?->name }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">GV: {{ $test->classModel?->teacher?->name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="font-caption text-caption text-on-surface-variant">Chặng học</p>
                                <span class="inline-flex rounded-md bg-secondary/10 px-sm py-0.5 font-label text-label uppercase text-secondary">{{ $test->stage ? 'Big Test - '.$test->stage->label : 'Big Test - '.($test->test_type === 'final' ? 'Cuối khóa' : 'Giữa kỳ') }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm">
                        <div class="flex items-center gap-sm mb-md"><span class="material-symbols-outlined text-primary">edit_note</span><h3 class="font-h3 text-h3 text-on-surface">Điểm chi tiết</h3></div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-md">
                            @foreach (['listening_score' => 'Listening', 'reading_score' => 'Reading', 'writing_score' => 'Writing', 'speaking_score' => 'Speaking'] as $field => $label)
                                <div class="flex items-center justify-between rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm">
                                    <span class="font-body-small text-body-small text-on-surface-variant">{{ $label }}</span>
                                    <span class="font-h3 text-h3 text-on-surface">{{ $selectedResult->is_absent ? '—' : ($selectedResult->$field ?? '—') }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-md">
                            <p class="mb-xs flex items-center gap-xs font-label text-label text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">link</span>Link video bài thi</p>
                            @if ($selectedResult->video_url)
                                <div class="flex items-center gap-sm rounded-lg border border-outline-variant px-md py-sm">
                                    <a href="{{ $selectedResult->video_url }}" target="_blank" rel="noopener" class="flex-1 truncate font-body-small text-body-small text-primary hover:underline">{{ $selectedResult->video_url }}</a>
                                    <a href="{{ $selectedResult->video_url }}" target="_blank" rel="noopener" title="Xem video" class="text-primary"><span class="material-symbols-outlined">video_library</span></a>
                                </div>
                            @else
                                <p class="font-body-small text-body-small text-on-surface-variant">Chưa có link video.</p>
                            @endif
                        </div>
                    </section>

                    <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm">
                        <div class="flex items-center gap-sm mb-md"><span class="material-symbols-outlined text-primary">comment</span><h3 class="font-h3 text-h3 text-on-surface">Nhận xét của giáo viên/HT</h3></div>
                        <div class="whitespace-pre-line rounded-lg bg-surface-container-low p-md font-body-base text-body-base italic text-on-surface">{{ $selectedResult->progress_note ?: 'Chưa có nhận xét.' }}</div>
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm space-y-md">
                        <div class="flex items-center justify-between gap-sm">
                            <h3 class="font-h3 text-h3 text-on-surface">Tổng điểm (Big Test)</h3>
                            @if (! is_null($test->resultsDaysLeft()))
                                <span title="Hạn trả kết quả" class="inline-flex items-center gap-xs rounded-full px-sm py-0.5 font-label text-label {{ $test->resultsDaysLeft() < 0 ? 'bg-error/10 text-error' : 'bg-amber-50 text-amber-800' }}">
                                    <span class="material-symbols-outlined text-[14px]">timer</span>{{ $test->resultsDaysLeft() < 0 ? 'Quá hạn '.abs($test->resultsDaysLeft()).' ngày' : 'Còn '.$test->resultsDaysLeft().' ngày' }}
                                </span>
                            @endif
                        </div>
                        <div class="font-h1 text-[48px] leading-none text-primary">{{ $selectedResult->is_absent ? 'Vắng thi' : ($selectedResult->overall_score ?? '—') }}</div>
                        <div class="flex items-center justify-between rounded-lg bg-surface-container-low p-md">
                            <div>
                                <p class="font-caption text-caption text-on-surface-variant">Trạng thái dữ liệu</p>
                                <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $selectedResult->status_label }}</p>
                            </div>
                            <span class="material-symbols-outlined text-on-surface-variant">analytics</span>
                        </div>
                    </section>

                    <section class="bg-surface-container-lowest border border-outline-variant rounded-xl p-lg shadow-sm space-y-md">
                        <div>
                            <p class="mb-xs font-label text-label text-on-surface-variant">Người gửi kết quả</p>
                            <div class="flex items-center gap-sm">
                                <x-ui.avatar :name="$selectedResult->grader?->name ?? '?'" size="sm" />
                                <span class="font-body-medium text-body-medium text-on-surface">{{ $selectedResult->grader?->name ?? '—' }}</span>
                            </div>
                        </div>
                        <div>
                            <p class="mb-xs font-label text-label text-on-surface-variant">{{ $selectedResult->approver ? 'Người duyệt' : 'Người duyệt (Hiện tại)' }}</p>
                            <div class="flex items-center gap-sm">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-secondary/10 text-secondary"><span class="material-symbols-outlined text-[18px]">shield_person</span></span>
                                <span class="font-body-medium text-body-medium text-on-surface">{{ $selectedResult->approver?->name ?? auth()->user()->name.' (Bạn)' }}</span>
                            </div>
                        </div>
                        @can('big_test.approve')
                            @if (in_array($selectedResult->status, ['pending_review', 'approved'], true) && ! $selectedResult->parent_notified)
                                <form method="POST" action="{{ route('syllabus.big-tests.results.approve-send', $selectedResult->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" icon="send" class="w-full">{{ $selectedResult->is_absent ? 'Duyệt (vắng thi)' : 'Duyệt & Gửi phụ huynh' }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                        <x-ui.button variant="secondary" class="w-full" :href="route('syllabus.big-tests.results', $test->id)">Quay lại</x-ui.button>
                    </section>

                    @if ($selectedResult->parent_notified)
                        <div class="flex items-start gap-sm rounded-xl border border-tertiary/20 bg-tertiary/5 p-md">
                            <span class="material-symbols-outlined text-tertiary">verified</span>
                            <div>
                                <p class="font-body-medium text-body-medium font-semibold text-tertiary">Đã gửi phụ huynh</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $selectedResult->notified_at?->format('H:i d/m/Y') }} — kết quả đã ghi nhận vào hồ sơ học tập của học viên.</p>
                            </div>
                        </div>
                    @elseif ($selectedResult->status !== 'draft')
                        <div class="flex items-start gap-sm rounded-xl border border-tertiary/20 bg-tertiary/5 p-md">
                            <span class="material-symbols-outlined text-tertiary">verified</span>
                            <div>
                                <p class="font-body-medium text-body-medium font-semibold text-tertiary">Hợp lệ</p>
                                <p class="font-caption text-caption text-on-surface-variant">Thông tin sẽ được ghi nhận vào hệ thống học tập của học viên.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- 1. Test Filter & Quick Stats --}}
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            {{-- Test Selector Card --}}
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 space-y-3">
                <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider">Chọn Kỳ Thi Big Test:</label>
                <div class="flex items-center gap-2">
                    <select onchange="window.location.href='{{ route('syllabus.big-tests.results') }}/' + this.value" class="w-full text-xs rounded-xl border border-gray-300 p-2.5 font-semibold text-gray-900 bg-white focus:ring-primary-container focus:border-primary-container">
                        @foreach ($allTests as $t)
                            <option value="{{ $t->id }}" {{ $test?->id === $t->id ? 'selected' : '' }}>
                                [{{ $t->code }}] {{ $t->title }} · {{ $t->classModel?->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @if ($test)
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-500 font-mono pt-1">
                        <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 font-bold">Lớp: {{ $test->classModel?->name }}</span>
                        <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">Mã: {{ $test->code }}</span>
                        <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">Phòng: {{ $test->room }}</span>
                        @if ($test->resultsDueAt())
                            <span class="px-2 py-0.5 rounded-md {{ $test->resultsDaysLeft() < 0 && ! $test->results_completed_at ? 'bg-rose-50 text-rose-700 font-bold' : 'bg-amber-50 text-amber-800' }}" title="Hạn trả kết quả = ngày thi + {{ \App\Models\BigTest::RESULT_DEADLINE_DAYS }} ngày">Hạn trả KQ: {{ $test->resultsDueAt()->format('d/m/Y') }}</span>
                        @endif
                        @if ($test->stage)
                            <span class="px-2 py-0.5 rounded-md bg-purple-50 text-purple-700 font-bold" title="Duyệt và gửi đủ kết quả cho phụ huynh sẽ đóng chặng này và tự mở chặng kế tiếp">Big Test cuối {{ $test->stage->label }}{{ $test->results_completed_at ? ' · đã hoàn tất' : '' }}</span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Stats Summary 1 --}}
            @php
                $totalCount = $results->count();
                $taken = $results->where('is_absent', false)->whereNotNull('overall_score');
                $takenCount = $taken->count();
                $absentCount = $results->where('is_absent', true)->count();
                $avgOverall = $takenCount > 0 ? round($taken->avg('overall_score'), 1) : 0;
                $highestScore = $takenCount > 0 ? $taken->max('overall_score') : 0;
            @endphp
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 flex items-center justify-between">
                <div>
                    <div class="text-[11px] font-bold text-gray-500 uppercase">Điểm Trung Bình Cả Lớp</div>
                    <div class="text-2xl font-black text-indigo-600 font-mono mt-1">{{ $avgOverall }} <span class="text-xs font-normal text-gray-400">/ 10</span></div>
                    <div class="text-[10px] text-gray-400 mt-0.5">Dựa trên {{ $takenCount }} học viên dự thi ({{ $absentCount }} vắng)</div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">award_star</span>
                </div>
            </div>

            {{-- Stats Summary 2 --}}
            <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs p-4 flex items-center justify-between">
                <div>
                    <div class="text-[11px] font-bold text-gray-500 uppercase">Điểm Cao Nhất (Top Score)</div>
                    <div class="text-2xl font-black text-emerald-600 font-mono mt-1">
                        {{ $highestScore }} <span class="text-xs font-normal text-gray-400">/ 10</span>
                    </div>
                    <div class="text-[10px] text-emerald-600 font-semibold mt-0.5">
                        Tổng số thí sinh: {{ $totalCount }} học viên
                    </div>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">military_tech</span>
                </div>
            </div>
        </div>

        {{-- 2. Results Table --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xs overflow-hidden">
            <div class="p-4 bg-slate-50/70 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Danh Sách Bảng Điểm Chi Tiết ({{ $results->count() }} Học viên)</h2>
                    <p class="text-[11px] text-gray-500">Kết quả khảo thí định kỳ được lưu trữ phục vụ xếp lớp và đánh giá năng lực</p>
                </div>
            </div>

            @php($resultsByStudent = $results->keyBy('student_id'))
            @php($canGrade = $test && auth()->user()->can('syllabus.update'))
            @php($canSend = $test && auth()->user()->can('big_test.approve'))
            @if ($canSend)
                {{-- Form gửi từng học viên nằm ngoài form nhập điểm (không lồng form); nút bấm tham chiếu qua thuộc tính form= --}}
                @foreach ($results as $res)
                    @if ($res->status === 'approved' && ! $res->parent_notified && ! $res->is_absent)
                        <form id="send-ph-{{ $res->id }}" method="POST" action="{{ route('syllabus.big-tests.send-single-zalo', $res->id) }}" class="hidden">@csrf</form>
                    @endif
                @endforeach
            @endif
            @if($canGrade)
            <form method="POST" action="{{ route('syllabus.big-tests.results.store', $test->id) }}">
                @csrf
            @endif
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs min-w-[1100px]">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-500 font-bold uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-4">Học viên &amp; Mã số</th>
                            <th class="py-3 px-2 text-center">Vắng thi</th>
                            <th class="py-3 px-3 text-center">Listening</th>
                            <th class="py-3 px-3 text-center">Reading</th>
                            <th class="py-3 px-3 text-center">Writing</th>
                            <th class="py-3 px-3 text-center">Speaking</th>
                            <th class="py-3 px-4 text-center bg-orange-50/60 text-orange-950">Overall</th>
                            <th class="py-3 px-4">Nhận xét &amp; link video bài thi</th>
                            <th class="py-3 px-4">Trạng thái</th>
                            <th class="py-3 px-4">Đã gửi PH</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 font-normal text-gray-700">
                        @forelse ($students as $index => $student)
                            @php($res = $resultsByStudent->get($student->id))
                            @php($locked = ! $canGrade || ($res?->isLocked() ?? false))
                            @php($absent = (bool) old("results.$index.is_absent", $res?->is_absent))
                            <tr class="hover:bg-blue-50/30 transition" x-data="{ absent: @js($absent) }">
                                <td class="py-3.5 px-4">
                                    <input type="hidden" name="results[{{ $index }}][student_id]" value="{{ $student->id }}" @disabled($locked)>
                                    <div class="font-bold text-gray-900">{{ $student->name }}</div>
                                    <div class="text-[11px] text-gray-400 font-mono mt-0.5">Mã HV: {{ $student->code ?? 'HV-' . $student->id }}</div>
                                </td>
                                <td class="py-3.5 px-2 text-center">
                                    <input type="checkbox" name="results[{{ $index }}][is_absent]" value="1" x-model="absent" @checked($absent) @disabled($locked)
                                           class="rounded border-gray-300 text-rose-600 focus:ring-rose-500 h-4 w-4" title="Đánh dấu học viên vắng thi">
                                </td>
                                @foreach (['listening_score', 'reading_score', 'writing_score', 'speaking_score'] as $skill)
                                    <td class="py-3.5 px-3">
                                        <input type="number" step=".1" min="0" max="10" name="results[{{ $index }}][{{ $skill }}]" value="{{ old("results.$index.$skill", $res?->$skill) }}" placeholder="—"
                                               @disabled($locked) :disabled="absent || @js($locked)" class="w-16 rounded border-gray-200 text-xs disabled:bg-gray-50">
                                    </td>
                                @endforeach
                                <td class="py-3.5 px-4 text-center font-mono font-black text-orange-600 bg-orange-50/40 text-base">
                                    @if ($res?->is_absent)
                                        <span class="text-xs font-bold text-rose-600">Vắng thi</span>
                                    @else
                                        {{ $res?->overall_score ?? '—' }}
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 space-y-1 min-w-[220px]">
                                    <textarea name="results[{{ $index }}][progress_note]" rows="2" @disabled($locked) placeholder="Nhận xét tiến độ" class="w-full rounded border-gray-200 text-xs disabled:bg-gray-50">{{ old("results.$index.progress_note", $res?->progress_note) }}</textarea>
                                    @if ($locked)
                                        @if ($res?->video_url)
                                            <a href="{{ $res->video_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11px] text-primary font-semibold hover:underline">
                                                <span class="material-symbols-outlined text-[14px]">video_library</span>Link video bài thi
                                            </a>
                                        @endif
                                    @else
                                        <input type="url" name="results[{{ $index }}][video_url]" value="{{ old("results.$index.video_url", $res?->video_url) }}" placeholder="Link video bài thi (https://...)" class="w-full rounded border-gray-200 text-xs">
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <x-ui.badge :color="match ($res?->status) { 'approved' => 'success', 'sent' => 'info', 'pending_review' => 'warning', default => 'neutral' }">{{ $res?->status === 'draft' ? 'Nháp (GV chưa gửi duyệt)' : ($res?->status_label ?? 'Chưa nhập') }}</x-ui.badge>
                                    @if ($res && $res->status !== 'draft')
                                        <a href="{{ route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $res->id]) }}" class="mt-1 flex items-center gap-0.5 text-[11px] font-semibold text-primary hover:underline">
                                            <span class="material-symbols-outlined text-[14px]">rate_review</span>Xem &amp; duyệt
                                        </a>
                                    @endif
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    @if ($res?->parent_notified)
                                        <span class="inline-flex items-center gap-1 text-emerald-700 font-semibold text-[11px]">
                                            <span class="material-symbols-outlined text-[16px]">mark_email_read</span>{{ $res->notified_at?->format('d/m H:i') ?? 'Đã gửi' }}
                                        </span>
                                    @elseif ($canSend && $res?->status === 'approved' && ! $res->is_absent)
                                        <button type="submit" form="send-ph-{{ $res->id }}" class="px-2.5 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[14px]">send</span>Gửi PH
                                        </button>
                                    @else
                                        <span class="text-[11px] text-gray-400">{{ $res?->is_absent ? 'Vắng thi' : 'Chưa gửi' }}</span>
                                    @endif
                                    @if ($res && in_array((int) $res->student_id, $missingParentPhone, true))
                                        <span class="mt-1 block text-[11px] font-semibold text-error">{{ \App\Http\Controllers\SyllabusController::MISSING_PARENT_PHONE }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-10 text-gray-400 text-xs">
                                    <div class="flex flex-col items-center justify-center space-y-2">
                                        <span class="material-symbols-outlined text-4xl text-gray-300">sentiment_neutral</span>
                                        <span>Chưa có kết quả thi cho kỳ thi Big Test này.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($canGrade)
                @if($students->contains(fn ($s) => ! ($resultsByStudent->get($s->id)?->isLocked() ?? false)))
                    <div class="p-4 border-t flex items-center justify-between gap-3">
                        <span class="text-[11px] text-gray-500">Học viên vắng: tích "Vắng thi" (không nhập điểm). Dòng để trống sẽ bỏ qua. "Lưu nháp" chưa gửi Học thuật (sửa tiếp được); "Gửi duyệt" cần đủ 4 kỹ năng. Điểm đã duyệt/đã gửi phụ huynh không thể sửa.</span>
                        <div class="flex items-center gap-2">
                            <x-ui.button type="submit" name="action" value="draft" variant="secondary" icon="draft">Lưu nháp</x-ui.button>
                            <x-ui.button type="submit" name="action" value="submit" icon="send">Gửi duyệt</x-ui.button>
                        </div>
                    </div>
                @endif
            </form>
            @endif
        </div>

    </div>
</x-app-layout>
