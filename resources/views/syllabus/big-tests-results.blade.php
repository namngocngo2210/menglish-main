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
                                <x-ui.badge color="secondary" :dot="false" class="uppercase">{{ $test->stage ? 'Big Test - '.$test->stage->label : 'Big Test - '.($test->test_type === 'final' ? 'Cuối khóa' : 'Giữa kỳ') }}</x-ui.badge>
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
                                <x-ui.badge :color="$test->resultsDaysLeft() < 0 ? 'error' : 'warning'" :dot="false" :pill="true" title="Hạn trả kết quả">
                                    <span class="material-symbols-outlined text-[14px]">timer</span>{{ $test->resultsDaysLeft() < 0 ? 'Quá hạn '.abs($test->resultsDaysLeft()).' ngày' : 'Còn '.$test->resultsDaysLeft().' ngày' }}
                                </x-ui.badge>
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
                        <x-ui.alert type="success" title="Đã gửi phụ huynh">
                            <p class="font-caption text-caption">{{ $selectedResult->notified_at?->format('H:i d/m/Y') }} — kết quả đã ghi nhận vào hồ sơ học tập của học viên.</p>
                        </x-ui.alert>
                    @elseif ($selectedResult->status !== 'draft')
                        <x-ui.alert type="success" title="Hợp lệ">
                            <p class="font-caption text-caption">Thông tin sẽ được ghi nhận vào hệ thống học tập của học viên.</p>
                        </x-ui.alert>
                    @endif
                </div>
            </div>
        @endif

        {{-- 1. Test Filter & Quick Stats --}}
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            {{-- Test Selector Card --}}
            <div class="lg:col-span-2 bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-4 space-y-3">
                <x-ui.select label="Chọn Kỳ Thi Big Test:" id="big-test-selector" onchange="window.location.href='{{ route('syllabus.big-tests.results') }}/' + this.value" class="font-semibold">
                    @foreach ($allTests as $t)
                        <option value="{{ $t->id }}" {{ $test?->id === $t->id ? 'selected' : '' }}>
                            [{{ $t->code }}] {{ $t->title }} · {{ $t->classModel?->name }}
                        </option>
                    @endforeach
                </x-ui.select>
                @if ($test)
                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-on-surface-variant font-mono pt-1">
                        <span class="px-2 py-0.5 rounded-md bg-secondary/10 text-secondary font-bold">Lớp: {{ $test->classModel?->name }}</span>
                        <span class="px-2 py-0.5 rounded-md bg-surface-container text-on-surface-variant">Mã: {{ $test->code }}</span>
                        <span class="px-2 py-0.5 rounded-md bg-surface-container text-on-surface-variant">Phòng: {{ $test->room }}</span>
                        @if ($test->resultsDueAt())
                            <span class="px-2 py-0.5 rounded-md {{ $test->resultsDaysLeft() < 0 && ! $test->results_completed_at ? 'bg-error/10 text-error font-bold' : 'bg-warning/10 text-on-warning-container' }}" title="Hạn trả kết quả = ngày thi + {{ \App\Models\BigTest::RESULT_DEADLINE_DAYS }} ngày">Hạn trả KQ: {{ $test->resultsDueAt()->format('d/m/Y') }}</span>
                        @endif
                        @if ($test->stage)
                            <span class="px-2 py-0.5 rounded-md bg-secondary/10 text-secondary font-bold" title="Duyệt và gửi đủ kết quả cho phụ huynh sẽ đóng chặng này và tự mở chặng kế tiếp">Big Test cuối {{ $test->stage->label }}{{ $test->results_completed_at ? ' · đã hoàn tất' : '' }}</span>
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
            <x-ui.stat-card label="Điểm Trung Bình Cả Lớp" tone="secondary" icon="award_star" hint="Dựa trên {{ $takenCount }} học viên dự thi ({{ $absentCount }} vắng)">
                {{ $avgOverall }} <span class="text-xs font-normal text-on-surface-variant/70">/ 10</span>
            </x-ui.stat-card>

            {{-- Stats Summary 2 --}}
            <x-ui.stat-card label="Điểm Cao Nhất (Top Score)" tone="success" icon="military_tech" hint="Tổng số thí sinh: {{ $totalCount }} học viên">
                {{ $highestScore }} <span class="text-xs font-normal text-on-surface-variant/70">/ 10</span>
            </x-ui.stat-card>
        </div>

        {{-- 2. Results Table --}}
        <x-ui.data-table>
            <x-slot:header>
                <div>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Danh Sách Bảng Điểm Chi Tiết ({{ $results->count() }} Học viên)</h2>
                    <p class="text-[11px] text-on-surface-variant">Kết quả khảo thí định kỳ được lưu trữ phục vụ xếp lớp và đánh giá năng lực</p>
                </div>
            </x-slot:header>

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
                <table class="text-xs min-w-[1100px]">
                    <thead>
                        <tr>
                            <th>Học viên &amp; Mã số</th>
                            <th class="text-center">Vắng thi</th>
                            <th class="text-center">Listening</th>
                            <th class="text-center">Reading</th>
                            <th class="text-center">Writing</th>
                            <th class="text-center">Speaking</th>
                            <th class="text-center bg-primary-container/10 !text-primary">Overall</th>
                            <th>Nhận xét &amp; link video bài thi</th>
                            <th>Trạng thái</th>
                            <th>Đã gửi PH</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($students as $index => $student)
                            @php($res = $resultsByStudent->get($student->id))
                            @php($locked = ! $canGrade || ($res?->isLocked() ?? false))
                            @php($absent = (bool) old("results.$index.is_absent", $res?->is_absent))
                            <tr x-data="{ absent: @js($absent) }">
                                <td>
                                    <input type="hidden" name="results[{{ $index }}][student_id]" value="{{ $student->id }}" @disabled($locked)>
                                    <div class="font-bold text-on-surface">{{ $student->name }}</div>
                                    <div class="text-[11px] text-on-surface-variant/70 font-mono mt-0.5">Mã HV: {{ $student->code ?? 'HV-' . $student->id }}</div>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" name="results[{{ $index }}][is_absent]" value="1" x-model="absent" @checked($absent) @disabled($locked)
                                           class="rounded border-outline-variant text-error focus:ring-error h-4 w-4" title="Đánh dấu học viên vắng thi">
                                </td>
                                @foreach (['listening_score', 'reading_score', 'writing_score', 'speaking_score'] as $skill)
                                    <td>
                                        <input type="number" step=".1" min="0" max="10" name="results[{{ $index }}][{{ $skill }}]" value="{{ old("results.$index.$skill", $res?->$skill) }}" placeholder="—"
                                               @disabled($locked) :disabled="absent || @js($locked)" class="w-16 rounded border-surface-container-highest text-xs disabled:bg-surface-container-low">
                                    </td>
                                @endforeach
                                <td class="text-center font-mono font-black !text-primary bg-primary-container/10 !text-base">
                                    @if ($res?->is_absent)
                                        <span class="text-xs font-bold text-error">Vắng thi</span>
                                    @else
                                        {{ $res?->overall_score ?? '—' }}
                                    @endif
                                </td>
                                <td class="space-y-1 min-w-[220px]">
                                    <textarea name="results[{{ $index }}][progress_note]" rows="2" @disabled($locked) placeholder="Nhận xét tiến độ" class="w-full rounded border-surface-container-highest text-xs disabled:bg-surface-container-low">{{ old("results.$index.progress_note", $res?->progress_note) }}</textarea>
                                    @if ($locked)
                                        @if ($res?->video_url)
                                            <a href="{{ $res->video_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[11px] text-primary font-semibold hover:underline">
                                                <span class="material-symbols-outlined text-[14px]">video_library</span>Link video bài thi
                                            </a>
                                        @endif
                                    @else
                                        <input type="url" name="results[{{ $index }}][video_url]" value="{{ old("results.$index.video_url", $res?->video_url) }}" placeholder="Link video bài thi (https://...)" class="w-full rounded border-surface-container-highest text-xs">
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <x-ui.badge :color="match ($res?->status) { 'approved' => 'success', 'sent' => 'info', 'pending_review' => 'warning', default => 'neutral' }">{{ $res?->status === 'draft' ? 'Nháp (GV chưa gửi duyệt)' : ($res?->status_label ?? 'Chưa nhập') }}</x-ui.badge>
                                    @if ($res && $res->status !== 'draft')
                                        <a href="{{ route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $res->id]) }}" class="mt-1 flex items-center gap-0.5 text-[11px] font-semibold text-primary hover:underline">
                                            <span class="material-symbols-outlined text-[14px]">rate_review</span>Xem &amp; duyệt
                                        </a>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($res?->parent_notified)
                                        <span class="inline-flex items-center gap-1 text-tertiary font-semibold text-[11px]">
                                            <span class="material-symbols-outlined text-[16px]">mark_email_read</span>{{ $res->notified_at?->format('d/m H:i') ?? 'Đã gửi' }}
                                        </span>
                                    @elseif ($canSend && $res?->status === 'approved' && ! $res->is_absent)
                                        <x-ui.button type="submit" form="send-ph-{{ $res->id }}" variant="info" size="sm" icon="send">Gửi PH</x-ui.button>
                                    @else
                                        <span class="text-[11px] text-on-surface-variant/70">{{ $res?->is_absent ? 'Vắng thi' : 'Chưa gửi' }}</span>
                                    @endif
                                    @if ($res && in_array((int) $res->student_id, $missingParentPhone, true))
                                        <span class="mt-1 block text-[11px] font-semibold text-error">{{ \App\Http\Controllers\SyllabusController::MISSING_PARENT_PHONE }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10">
                                    <x-ui.empty-state icon="sentiment_neutral" title="Chưa có kết quả thi cho kỳ thi Big Test này." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            @if($canGrade)
                @if($students->contains(fn ($s) => ! ($resultsByStudent->get($s->id)?->isLocked() ?? false)))
                    <div class="p-4 border-t border-surface-container-highest flex items-center justify-between gap-3">
                        <span class="text-[11px] text-on-surface-variant">Học viên vắng: tích "Vắng thi" (không nhập điểm). Dòng để trống sẽ bỏ qua. "Lưu nháp" chưa gửi Học thuật (sửa tiếp được); "Gửi duyệt" cần đủ 4 kỹ năng. Điểm đã duyệt/đã gửi phụ huynh không thể sửa.</span>
                        <div class="flex items-center gap-2">
                            <x-ui.button type="submit" name="action" value="draft" variant="secondary" icon="draft">Lưu nháp</x-ui.button>
                            <x-ui.button type="submit" name="action" value="submit" icon="send">Gửi duyệt</x-ui.button>
                        </div>
                    </div>
                @endif
            </form>
            @endif
        </x-ui.data-table>

    </div>
</x-app-layout>
