<x-app-layout title="Quản lý đề test đầu vào">
    @php
        $gradeGroups = \App\Services\PlacementRubricService::gradeGroups();
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    @endphp

    {{-- Mockup quan-ly-de-dau-vao-crm/qu_n_l_test_u_v_o_danh_s_ch: tiêu đề + Tạo đề mới, lọc Cấp độ / Trạng thái / Tìm kiếm + Làm mới,
         bảng Tên đề (ID), Loại đề, Cấp độ, Thời gian, Trạng thái, Hành động (Sửa, Ẩn / Kích hoạt), phân trang.
         A6 Q2: cấp độ = khối lớp theo "Thang điểm + hướng dẫn nhận xét", không dùng CEFR. --}}
    <x-ui.page-header title="Quản lý đề test đầu vào" description="Quản lý và cập nhật các bộ đề đánh giá năng lực học sinh.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="rule" :href="route('placement-tests.rubric-guide')">Thang điểm &amp; hướng dẫn nhận xét</x-ui.button>
            @can('placement_test.create')
                <x-ui.button icon="add" :href="route('placement-tests.create')">Tạo đề mới</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-col gap-lg">
        <div class="grid grid-cols-2 gap-md lg:grid-cols-4">
            <x-ui.stat-card label="Tổng số đề" :value="$stats['total_tests']" icon="quiz" tone="primary" />
            <x-ui.stat-card label="Đang hoạt động" :value="$stats['active_tests']" icon="visibility" tone="success" :hint="$stats['hidden_tests'].' đề đang ẩn'" />
            <x-ui.stat-card label="Lượt làm bài" :value="$stats['total_submissions']" icon="assignment_turned_in" tone="secondary" />
            <x-ui.stat-card label="Bài chờ chấm" :value="$stats['pending_submissions']" icon="pending_actions" tone="warning" hint="Viết / Nói do Học vụ chấm" />
        </div>

        {{-- Bộ lọc (server) --}}
        <form method="GET" action="{{ route('placement-tests.index') }}" role="search" class="rounded-xl border border-surface-container-highest bg-surface-container-lowest p-md shadow-sm">
            <div class="grid grid-cols-1 items-end gap-md md:grid-cols-2 lg:grid-cols-12">
                <div class="lg:col-span-3">
                    <x-ui.select name="grade_group" label="Cấp độ" placeholder="Tất cả cấp độ" :options="$gradeGroups" />
                </div>
                <div class="lg:col-span-2">
                    <x-ui.select name="status" label="Trạng thái" placeholder="Tất cả trạng thái" :options="['active' => 'Hoạt động', 'hidden' => 'Ẩn']" />
                </div>
                <div class="lg:col-span-5">
                    <x-ui.input id="f_test_search" type="search" name="search" label="Tìm kiếm tên đề" icon="search" :value="request('search')" placeholder="Nhập tên đề cần tìm..." />
                </div>
                <div class="flex gap-sm lg:col-span-2">
                    <x-ui.button type="submit" variant="secondary" icon="filter_list" class="flex-1">Lọc</x-ui.button>
                    <x-ui.button variant="ghost" icon="refresh" :href="route('placement-tests.index')" title="Làm mới">Làm mới</x-ui.button>
                </div>
            </div>
        </form>

        <x-ui.data-table min-width="980px">
            <table>
                <thead>
                    <tr>
                        <th>Tên đề test</th>
                        <th>Loại đề</th>
                        <th>Cấp độ</th>
                        <th>Thời gian</th>
                        <th class="text-center">Lượt làm</th>
                        <th>Trạng thái</th>
                        <th class="text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tests as $t)
                        <tr>
                            <td>
                                <a href="{{ route('placement-tests.show', $t->id) }}" class="block font-body-medium text-body-medium text-on-surface hover:text-primary">{{ $t->title }}</a>
                                <span class="font-code text-caption text-on-surface-variant">ID: {{ $t->code }}</span>
                                @if ($t->is_preset)
                                    <span class="ml-xs inline-flex items-center gap-0.5 rounded bg-surface-container-high px-1.5 font-caption text-caption text-on-surface-variant"><span class="material-symbols-outlined text-[12px]">lock</span>Đề chuẩn</span>
                                @endif
                            </td>
                            <td><code class="rounded bg-surface-container-low px-sm py-0.5 font-code text-caption text-on-surface-variant">{{ str_contains(strtoupper($t->code), 'SPEAKING') ? 'speaking_test' : 'placement_test' }}</code></td>
                            <td class="whitespace-nowrap">
                                <x-ui.badge color="secondary" pill :dot="false">{{ $gradeGroups[$t->grade_group] ?? 'Chưa rõ khối' }}</x-ui.badge>
                                @if ($t->target_level)<div class="mt-xs font-caption text-caption text-on-surface-variant">{{ $t->target_level }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="inline-flex items-center gap-xs text-on-surface-variant"><span class="material-symbols-outlined text-[18px]">schedule</span>{{ $t->duration_minutes }} phút</span>
                            </td>
                            <td class="text-center">
                                <a href="{{ route('placement-tests.index', ['test_id' => $t->id] + request()->only(['search', 'grade_group', 'status'])) }}#submissions"
                                   class="font-code text-code {{ $t->submissions_count > 0 ? 'text-tertiary hover:underline' : 'text-on-surface-variant' }}">{{ $t->submissions_count }}</a>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($t->is_active)
                                    <x-ui.badge color="success" pill>Hoạt động</x-ui.badge>
                                @else
                                    <x-ui.badge color="neutral" pill>Ẩn</x-ui.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    @if ($t->is_active)
                                        <x-ui.button variant="ghost" size="sm" icon="play_circle" :href="route('portal.test.take', $t->code)" target="_blank" title="Mở link làm bài" aria-label="Mở link làm bài" />
                                    @endif
                                    <x-ui.button variant="ghost" size="sm" icon="visibility" :href="route('placement-tests.show', $t->id)" title="Xem đề &amp; câu hỏi" aria-label="Xem đề" />
                                    @can('placement_test.update')
                                        @unless ($t->is_preset)
                                            <x-ui.button variant="ghost" size="sm" icon="edit" :href="route('placement-tests.edit', $t->id)" title="Chỉnh sửa" aria-label="Chỉnh sửa" />
                                        @endunless
                                        <form action="{{ route('placement-tests.toggle-active', $t->id) }}" method="POST" class="inline">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm" :icon="$t->is_active ? 'visibility_off' : 'visibility'" :title="$t->is_active ? 'Ẩn đề' : 'Kích hoạt'" :aria-label="$t->is_active ? 'Ẩn đề' : 'Kích hoạt'" />
                                        </form>
                                    @endcan
                                    @can('placement_test.create')
                                        <form action="{{ route('placement-tests.duplicate', $t->id) }}" method="POST" class="inline">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="content_copy" title="Nhân bản đề" aria-label="Nhân bản đề" />
                                        </form>
                                    @endcan
                                    @can('placement_test.delete')
                                        @if (! $t->is_preset && $t->submissions_count === 0)
                                            <form action="{{ route('placement-tests.destroy', $t->id) }}" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc muốn xóa đề thi này?');">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger-text" size="sm" icon="delete" title="Xóa đề (chưa có bài làm)" aria-label="Xóa đề" />
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-ui.empty-state icon="search_off" title="Không tìm thấy đề test" description="Thử đổi từ khóa hoặc bấm Làm mới để bỏ lọc." /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$tests" unit="đề test" /></x-slot:footer>
        </x-ui.data-table>

        {{-- Bài làm gần đây (theo phạm vi khách CRM) — chấm theo thang điểm khối lớp --}}
        <x-ui.data-table min-width="1080px" id="submissions" class="scroll-mt-6">
            <x-slot:header>
                <div class="flex flex-wrap items-center gap-sm">
                    <h2 class="font-h3 text-h3 text-on-surface">Bài làm &amp; kết quả chấm</h2>
                    @if ($selectedTest)
                        <x-ui.badge color="info">Đề: {{ $selectedTest->title }} ({{ $selectedTest->code }}) · {{ $recentSubmissions->count() }} thí sinh</x-ui.badge>
                    @else
                        <span class="font-body-small text-body-small text-on-surface-variant">{{ $recentSubmissions->count() }} bài nộp gần nhất</span>
                    @endif
                </div>
                @if ($selectedTest)
                    <x-ui.button variant="ghost" size="sm" icon="close" :href="route('placement-tests.index').'#submissions'">Bỏ lọc đề này</x-ui.button>
                @endif
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Thí sinh</th>
                        <th>Số điện thoại</th>
                        <th>Đề kiểm tra</th>
                        <th class="text-center">Nghe</th>
                        <th class="text-center">Đọc &amp; Viết</th>
                        <th class="text-center">Nói</th>
                        <th class="text-center">Tổng điểm</th>
                        <th>Lớp xếp</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentSubmissions as $sub)
                        <tr>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-xs font-body-medium text-body-medium text-on-surface">
                                    {{ $sub->candidate_name }}
                                    @if ($sub->customer)
                                        <a href="{{ route('crm.customers.show', $sub->customer->id) }}" class="rounded bg-secondary/10 px-1.5 font-caption text-caption text-secondary hover:underline" title="Mở hồ sơ khách">Khách CRM</a>
                                    @endif
                                </div>
                                <div class="font-code text-caption text-on-surface-variant">{{ $sub->created_at?->format('H:i d/m/Y') }}</div>
                            </td>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $sub->candidate_phone }}</td>
                            <td class="whitespace-nowrap">
                                <div class="text-on-surface">{{ $sub->test?->title }}</div>
                                <div class="font-code text-caption text-on-surface-variant">ID: {{ $sub->test?->code }}</div>
                            </td>
                            <td class="text-center font-code text-code">{{ $fmt($sub->listening_score) }}</td>
                            <td class="text-center font-code text-code">{{ $fmt($sub->reading_writing_score ?? $sub->reading_score) }}</td>
                            <td class="text-center font-code text-code">{{ $fmt($sub->speaking_score) }}</td>
                            <td class="text-center whitespace-nowrap">
                                @if ($sub->isPending())
                                    <x-ui.badge color="warning" pill>Chờ chấm</x-ui.badge>
                                @else
                                    <span class="font-code text-code font-bold text-primary">{{ $sub->total_score !== null ? $fmt($sub->total_score).(\App\Services\PlacementRubricService::hasRubric($sub->grade_group) ? ' / '.\App\Services\PlacementRubricService::maxTotal($sub->grade_group) : ' điểm') : ($sub->scoreSummary() ?? '—') }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap font-body-medium text-body-medium text-primary">{{ $sub->finalClass() ?? $sub->recommended_course ?? '—' }}</td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-xs">
                                    <x-ui.button variant="secondary" size="sm" icon="description" :href="\Illuminate\Support\Facades\URL::signedRoute('portal.test.scorecard', ['id' => $sub->id])" target="_blank">Phiếu điểm</x-ui.button>
                                    @can('placement_test.grade')
                                        <x-ui.button variant="ghost" size="sm" icon="edit_note" :href="route('placement-tests.results.show', $sub->id)">{{ $sub->isPending() ? 'Chấm bài' : 'Chấm lại' }}</x-ui.button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-ui.empty-state icon="assignment_late" :title="$selectedTest ? 'Chưa có thí sinh nào nộp bài cho đề này' : 'Chưa có bài thi nào được nộp'" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>
</x-app-layout>
