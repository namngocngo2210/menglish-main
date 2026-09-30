<x-app-layout>
    @php
        $isApprover = auth()->user()->can('big_test.approve');
    @endphp
    <x-ui.page-header :title="$isApprover ? 'Duyệt kết quả Big Test & gửi phụ huynh' : 'Nhập điểm Big Test'"
                      :description="$isApprover ? 'Bảng điểm 4 kỹ năng, nhận xét, link video; Học thuật duyệt và gửi kết quả cho phụ huynh qua Zalo.' : 'Nhập điểm 4 kỹ năng, nhận xét, link video cho lớp mình dạy rồi gửi Học thuật duyệt.'"
                      :back="auth()->user()->can('syllabus.manage') || $isApprover ? route('syllabus.big-tests.distribution') : null">
        <x-slot:breadcrumbs>
            <span>Học thuật</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="font-semibold text-on-surface">Quản lý Big Test</span>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            @if($test)
                @can('big_test.approve')
                    {{-- Việc chính của người duyệt là duyệt; gửi phụ huynh là bước sau nên để nút phụ --}}
                    <form method="POST" action="{{ route('syllabus.big-tests.results.approve', $test->id) }}">@csrf
                        <x-ui.button type="submit" icon="task_alt">Duyệt kết quả</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('syllabus.big-tests.send-zalo', $test->id) }}">@csrf
                        <x-ui.button type="submit" variant="secondary" icon="send" title="Gửi các kết quả đã duyệt cho phụ huynh qua Zalo">Gửi phụ huynh</x-ui.button>
                    </form>
                @endcan
            @endif
            <x-ui.button variant="secondary" icon="print" onclick="window.print();">In bảng điểm</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-5">
        {{-- 1. Test Filter & Quick Stats --}}
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            {{-- Test Selector Card --}}
            <div class="lg:col-span-2 bg-surface-container-lowest rounded-2xl border border-surface-container-highest shadow-2xs p-4 space-y-3">
                <x-ui.select label="Chọn Kỳ Thi Big Test:" id="big-test-selector" onchange="window.location.href='{{ route('syllabus.big-tests.results') }}/' + this.value" class="font-semibold">
                    @if ($allTests->isEmpty())
                        <option value="">Chưa có kỳ thi Big Test nào</option>
                    @endif
                    @foreach ($allTests as $t)
                        <option value="{{ $t->id }}" {{ $test?->id === $t->id ? 'selected' : '' }}>
                            [{{ $t->code }}] {{ $t->title }} · {{ $t->classModel?->name }}
                        </option>
                    @endforeach
                </x-ui.select>
                @if ($test)
                    <div class="flex flex-wrap items-center gap-2 text-xs text-on-surface-variant font-mono pt-1">
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
                {{ $avgOverall }} <span class="text-xs font-normal text-on-surface-subtle">/ 10</span>
            </x-ui.stat-card>

            {{-- Stats Summary 2 --}}
            <x-ui.stat-card label="Điểm Cao Nhất (Top Score)" tone="success" icon="military_tech" hint="Tổng số thí sinh: {{ $totalCount }} học viên">
                {{ $highestScore }} <span class="text-xs font-normal text-on-surface-subtle">/ 10</span>
            </x-ui.stat-card>
        </div>

        {{-- 2. Results Table --}}
        <x-ui.data-table sticky="both">
            <x-slot:header>
                <div>
                    <h2 class="text-xs font-bold text-on-surface uppercase tracking-wider">Danh Sách Bảng Điểm Chi Tiết ({{ $results->count() }} Học viên)</h2>
                    <p class="text-xs text-on-surface-variant">Kết quả khảo thí định kỳ được lưu trữ phục vụ xếp lớp và đánh giá năng lực</p>
                </div>
            </x-slot:header>

            @php($resultsByStudent = $results->keyBy('student_id'))
            {{-- Đề chưa duyệt & phân phối thì server từ chối nhập điểm → không hiện form nhập.
                 Người duyệt chỉ xem & duyệt, không thấy form nhập điểm / Lưu nháp / Gửi duyệt của giáo viên. --}}
            @php($canGradeRole = auth()->user()->can('syllabus.update') && ! $isApprover)
            @php($canGrade = $test && $test->is_distributed && $canGradeRole)
            @if ($test && ! $test->is_distributed && $canGradeRole)
                <div class="border-b border-surface-container bg-warning-container/40 px-md py-sm text-xs text-on-surface">Đề thi của đợt này chưa được duyệt và phân phối, chưa nhập điểm được.</div>
            @endif
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
                            <th>Trạng thái</th>
                            <th class="text-center !px-xs">Vắng thi</th>
                            <th class="text-center !px-xs">Listening</th>
                            <th class="text-center !px-xs">Reading</th>
                            <th class="text-center !px-xs">Writing</th>
                            <th class="text-center !px-xs">Speaking</th>
                            <th class="text-center bg-primary-container/10 !text-primary">Overall</th>
                            <th>Nhận xét &amp; video</th>
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
                                    <div class="max-w-[200px] truncate text-xs text-on-surface-subtle font-mono mt-0.5">Mã HV: <x-ui.code :value="$student->code ?? 'HV-' . $student->id" /></div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <x-ui.badge :color="match ($res?->status) { 'approved' => 'success', 'sent' => 'info', 'pending_review' => 'warning', default => 'neutral' }">{{ $res?->status === 'draft' ? 'Nháp (GV chưa gửi duyệt)' : ($res?->status_label ?? 'Chưa nhập') }}</x-ui.badge>
                                    @if ($res && $res->status !== 'draft')
                                        <a href="{{ route('syllabus.big-tests.results', ['id' => $test->id, 'result' => $res->id]) }}" class="mt-1 flex items-center gap-0.5 text-xs font-semibold text-primary hover:underline">
                                            <span class="material-symbols-outlined text-[14px]">rate_review</span>Xem &amp; duyệt
                                        </a>
                                    @endif
                                </td>
                                <td class="text-center !px-xs">
                                    <input type="checkbox" name="results[{{ $index }}][is_absent]" value="1" x-model="absent" @checked($absent) @disabled($locked)
                                           class="rounded border-outline-variant text-error focus:ring-error h-4 w-4" title="Đánh dấu học viên vắng thi" aria-label="Vắng thi — {{ $student->name }}">
                                </td>
                                @foreach (['listening_score' => 'Nghe', 'reading_score' => 'Đọc', 'writing_score' => 'Viết', 'speaking_score' => 'Nói'] as $skill => $skillLabel)
                                    <td class="!px-xs text-center">
                                        <input type="number" step=".1" min="0" max="10" name="results[{{ $index }}][{{ $skill }}]" value="{{ old("results.$index.$skill", $res?->$skill) }}" placeholder="—"
                                               aria-label="Điểm {{ $skillLabel }} — {{ $student->name }}"
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
                                <td class="space-y-1 min-w-[180px]">
                                    <textarea name="results[{{ $index }}][progress_note]" rows="2" @disabled($locked) placeholder="Nhận xét tiến độ" aria-label="Nhận xét tiến độ — {{ $student->name }}" class="w-full rounded border-surface-container-highest text-xs disabled:bg-surface-container-low">{{ old("results.$index.progress_note", $res?->progress_note) }}</textarea>
                                    @if ($locked)
                                        @if ($res?->video_url)
                                            <a href="{{ $res->video_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:underline">
                                                <span class="material-symbols-outlined text-[14px]">video_library</span>Link video bài thi
                                            </a>
                                        @endif
                                    @else
                                        <input type="url" name="results[{{ $index }}][video_url]" value="{{ old("results.$index.video_url", $res?->video_url) }}" placeholder="Link video bài thi (https://...)" aria-label="Link video bài thi — {{ $student->name }}" class="w-full rounded border-surface-container-highest text-xs">
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($res?->parent_notified)
                                        <span class="inline-flex items-center gap-1 text-tertiary font-semibold text-xs">
                                            <span class="material-symbols-outlined text-[16px]">mark_email_read</span>{{ $res->notified_at?->format('d/m H:i') ?? 'Đã gửi' }}
                                        </span>
                                    @elseif ($canSend && $res?->status === 'approved' && ! $res->is_absent)
                                        <x-ui.button type="submit" form="send-ph-{{ $res->id }}" variant="info" size="sm" icon="send">Gửi PH</x-ui.button>
                                    @else
                                        <span class="text-xs text-on-surface-subtle">{{ $res?->is_absent ? 'Vắng thi' : 'Chưa gửi' }}</span>
                                    @endif
                                    @if ($res && in_array((int) $res->student_id, $missingParentPhone, true))
                                        <span class="mt-1 block text-xs font-semibold text-error">{{ \App\Http\Controllers\SyllabusController::MISSING_PARENT_PHONE }}</span>
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
                        <span class="text-xs text-on-surface-variant">Học viên vắng: tích "Vắng thi" (không nhập điểm). Dòng để trống sẽ bỏ qua. "Lưu nháp" chưa gửi Học thuật (sửa tiếp được); "Gửi duyệt" cần đủ 4 kỹ năng. Điểm đã duyệt/đã gửi phụ huynh không thể sửa.</span>
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

    {{-- Mockup 01_Web_Admin/07: xét duyệt kết quả từng học viên (thông tin, điểm chi tiết, video, nhận xét, tổng điểm, hạn trả KQ, người gửi / người duyệt).
         Mở trong modal khi URL có ?result=; đóng thì bỏ result khỏi thanh địa chỉ. --}}
    @if ($test && $selectedResult)
        <x-ui.modal name="big-test-result-detail" :title="'Kết quả Big Test · '.($selectedResult->student?->name ?? 'Học viên')" max-width="4xl" show
                    :dismiss-url="route('syllabus.big-tests.results', $test->id)">
            <div class="grid grid-cols-1 gap-lg md:grid-cols-3">
                <div class="space-y-lg md:col-span-2">
                    <section>
                        <h3 class="mb-sm font-label text-label uppercase text-on-surface-variant">Thông tin chung</h3>
                        <dl class="grid grid-cols-1 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-3">
                            <div>
                                <dt class="font-caption text-caption text-on-surface-variant">Học viên</dt>
                                <dd class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $selectedResult->student?->name }}</dd>
                                <dd class="font-caption text-caption text-on-surface-variant">Mã HV: <x-ui.code :value="$selectedResult->student?->code ?? 'HV-'.$selectedResult->student_id" /></dd>
                            </div>
                            <div>
                                <dt class="font-caption text-caption text-on-surface-variant">Lớp học</dt>
                                <dd class="font-body-medium text-body-medium text-on-surface">{{ $test->classModel?->name }}</dd>
                                <dd class="font-caption text-caption text-on-surface-variant">GV: {{ $test->classModel?->teacher?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-caption text-caption text-on-surface-variant">Chặng học</dt>
                                <dd><x-ui.badge color="secondary" :dot="false" class="uppercase">{{ $test->stage ? 'Big Test - '.$test->stage->label : 'Big Test - '.($test->test_type === 'final' ? 'Cuối khóa' : 'Giữa kỳ') }}</x-ui.badge></dd>
                            </div>
                        </dl>
                    </section>

                    <section>
                        <h3 class="mb-sm font-label text-label uppercase text-on-surface-variant">Điểm chi tiết</h3>
                        <div class="grid grid-cols-2 gap-md sm:grid-cols-4">
                            @foreach (['listening_score' => 'Listening', 'reading_score' => 'Reading', 'writing_score' => 'Writing', 'speaking_score' => 'Speaking'] as $field => $label)
                                <div class="flex items-center justify-between rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm">
                                    <span class="font-body-small text-body-small text-on-surface-variant">{{ $label }}</span>
                                    <span class="font-h3 text-h3 text-on-surface">{{ $selectedResult->is_absent ? '—' : ($selectedResult->$field ?? '—') }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mb-xs mt-md flex items-center gap-xs font-label text-label text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">link</span>Link video bài thi</p>
                        @if ($selectedResult->video_url)
                            <a href="{{ $selectedResult->video_url }}" target="_blank" rel="noopener" class="flex items-center gap-sm rounded-lg border border-outline-variant px-md py-sm font-body-small text-body-small text-primary hover:underline">
                                <span class="flex-1 truncate">{{ $selectedResult->video_url }}</span>
                                <span class="material-symbols-outlined" aria-hidden="true">video_library</span>
                            </a>
                        @else
                            <p class="font-body-small text-body-small text-on-surface-variant">Chưa có link video.</p>
                        @endif
                    </section>

                    <section>
                        <h3 class="mb-sm font-label text-label uppercase text-on-surface-variant">Nhận xét của giáo viên/HT</h3>
                        <div class="whitespace-pre-line rounded-lg bg-surface-container-low p-md font-body-base text-body-base italic text-on-surface">{{ $selectedResult->progress_note ?: 'Chưa có nhận xét.' }}</div>
                    </section>
                </div>

                <div class="space-y-lg">
                    <section class="space-y-md rounded-lg border border-outline-variant p-md">
                        <div class="flex flex-wrap items-center justify-between gap-sm">
                            <h3 class="whitespace-nowrap font-h3 text-h3 text-on-surface">Tổng điểm (Big Test)</h3>
                            @if (! is_null($test->resultsDaysLeft()))
                                <x-ui.badge :color="$test->resultsDaysLeft() < 0 ? 'error' : 'warning'" :dot="false" :pill="true" title="Hạn trả kết quả">
                                    <span class="material-symbols-outlined text-[14px]">timer</span>{{ $test->resultsDaysLeft() < 0 ? 'Quá hạn '.abs($test->resultsDaysLeft()).' ngày' : 'Còn '.$test->resultsDaysLeft().' ngày' }}
                                </x-ui.badge>
                            @endif
                        </div>
                        <div class="font-h1 text-h1 text-primary">{{ $selectedResult->is_absent ? 'Vắng thi' : ($selectedResult->overall_score ?? '—') }}</div>
                        <div>
                            <p class="font-caption text-caption text-on-surface-variant">Trạng thái dữ liệu</p>
                            <p class="font-body-medium text-body-medium font-semibold text-on-surface">{{ $selectedResult->status_label }}</p>
                        </div>
                    </section>

                    <section class="space-y-md rounded-lg border border-outline-variant p-md">
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

            @can('big_test.approve')
                @if (in_array($selectedResult->status, ['pending_review', 'approved'], true) && ! $selectedResult->parent_notified)
                    <x-slot:footer>
                        <form method="POST" action="{{ route('syllabus.big-tests.results.approve-send', $selectedResult->id) }}">
                            @csrf
                            <x-ui.button type="submit" icon="send">{{ $selectedResult->is_absent ? 'Duyệt (vắng thi)' : 'Duyệt & Gửi phụ huynh' }}</x-ui.button>
                        </form>
                    </x-slot:footer>
                @endif
            @endcan
        </x-ui.modal>
    @endif
</x-app-layout>
