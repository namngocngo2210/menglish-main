<x-app-layout>
    <x-ui.page-header title="Quản lý Tuyển dụng & Hồ sơ Ứng viên">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="open_in_new" :href="route('portal.recruitment')" target="_blank">Cổng nộp CV Online</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $cvStatusColor = ['pending' => 'warning', 'reviewing' => 'secondary', 'interviewed' => 'info', 'accepted' => 'success', 'rejected' => 'error'];
        $cvStatusOptions = ['pending' => 'Chờ xử lý', 'reviewing' => 'Đang đánh giá', 'interviewed' => 'Đã phỏng vấn', 'accepted' => 'Đã tuyển dụng', 'rejected' => 'Từ chối'];
    @endphp

    <div class="space-y-6">
        {{-- 4 Metric Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Tin tuyển dụng đang mở" :value="$totalJobs.' vị trí'" tone="secondary" icon="work" />
            <x-ui.stat-card label="Tổng CV tiếp nhận" :value="$totalCvs.' hồ sơ'" tone="primary" icon="description" />
            <x-ui.stat-card label="Đã phỏng vấn" :value="$interviewedCount.' ứng viên'" tone="secondary" icon="contact_phone" />
            <x-ui.stat-card label="Trúng tuyển / Nhận việc" :value="$acceptedCount.' nhân sự'" tone="success" icon="how_to_reg" />
        </div>

        {{-- Tabs & Filters --}}
        <div class="overflow-hidden rounded-2xl border border-surface-container-highest bg-surface-container-lowest shadow-xs">
            <div class="flex flex-col justify-between gap-4 px-4 pt-2 sm:flex-row sm:items-center sm:px-5">
                <x-ui.tabs class="flex-1">
                    <x-ui.tab :href="route('recruitment.index', ['tab' => 'candidates'])" :active="$tab === 'candidates'">1. Danh sách Hồ sơ CV ({{ $totalCvs }})</x-ui.tab>
                    <x-ui.tab :href="route('recruitment.index', ['tab' => 'jobs'])" :active="$tab === 'jobs'">2. Tin Tuyển dụng ({{ $jobs->count() }})</x-ui.tab>
                </x-ui.tabs>

                @if($tab === 'jobs')
                    <x-ui.button icon="add_circle" class="mb-2 self-start sm:self-auto" x-on:click="$dispatch('open-modal', 'new-job')">Đăng tin tuyển dụng</x-ui.button>
                @endif
            </div>

            @if($tab === 'candidates')
                {{-- Filter Status & Branch --}}
                <div class="flex flex-wrap items-center gap-3 border-b border-surface-container-highest bg-surface-container-low p-4">
                    <form method="GET" action="{{ route('recruitment.index') }}" class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                        <input type="hidden" name="tab" value="candidates">
                        <x-ui.select name="status" onchange="this.form.submit()" aria-label="Trạng thái" :value="$status"
                                     :options="['all' => 'Tất cả trạng thái'] + $cvStatusOptions" />

                        <x-ui.select name="branch_id" onchange="this.form.submit()" aria-label="Cơ sở" placeholder="Tất cả cơ sở" :value="$branchId"
                                     :options="$branches->pluck('name', 'id')" />
                    </form>
                </div>

                {{-- Table CVs --}}
                <x-ui.data-table class="rounded-none border-0">
                    <table>
                        <thead>
                            <tr>
                                <th>Ứng viên</th>
                                <th>Vị trí & Cơ sở</th>
                                <th>Hồ sơ CV / Portfolio</th>
                                <th>Trạng thái</th>
                                <th>Ghi chú tuyển dụng</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($candidates as $can)
                                @php $badge = $can->statusBadge(); @endphp
                                <tr x-data="{ openEdit: false }">
                                    <td>
                                        <span class="block text-sm font-bold text-on-surface">{{ $can->full_name }}</span>
                                        <div class="mt-0.5 flex items-center gap-2 text-[11px] text-on-surface-variant">
                                            <span>{{ $can->phone }}</span>
                                            <span>•</span>
                                            <span>{{ $can->email }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="block font-semibold text-primary">{{ $can->applying_position }}</span>
                                        <span class="text-[11px] text-on-surface-variant">{{ $can->branch?->name ?? 'Mọi chi nhánh' }}</span>
                                    </td>
                                    <td>
                                        @if($can->cv_file_path)
                                            <a href="{{ asset('storage/' . $can->cv_file_path) }}" target="_blank" class="inline-flex items-center gap-1 font-bold text-secondary hover:underline">
                                                <span class="material-symbols-outlined text-[16px]">attach_file</span>
                                                Xem file CV
                                            </a>
                                        @else
                                            <span class="text-on-surface-variant/70">Không đính kèm file</span>
                                        @endif
                                        @if($can->portfolio_url)
                                            <a href="{{ $can->portfolio_url }}" target="_blank" class="mt-0.5 block text-[11px] font-medium text-secondary hover:underline">
                                                Link Video / Portfolio &rarr;
                                            </a>
                                        @endif
                                    </td>
                                    <td>
                                        <x-ui.badge :color="$cvStatusColor[$can->status] ?? 'neutral'" pill>{{ $badge['label'] }}</x-ui.badge>
                                    </td>
                                    <td class="max-w-xs">
                                        <p class="line-clamp-2 whitespace-pre-line text-[11px] text-on-surface-variant">{{ $can->notes ?: 'Chưa có ghi chú' }}</p>
                                    </td>
                                    <td class="text-right">
                                        <x-ui.button variant="secondary" size="sm" x-on:click="openEdit = !openEdit">Cập nhật</x-ui.button>

                                        {{-- Dropdown Update Status Modal/Popover --}}
                                        <div x-show="openEdit" x-cloak class="absolute right-4 z-20 mt-2 w-64 space-y-2 rounded-xl border border-surface-container-highest bg-surface-container-lowest p-3 text-left shadow-lg">
                                            <form action="{{ route('recruitment.cv.update-status', $can->id) }}" method="POST" class="space-y-2">
                                                @csrf
                                                <x-ui.select name="status" id="cv_status_{{ $can->id }}" label="Trạng thái mới" :value="$can->status" :options="$cvStatusOptions" />
                                                <x-ui.textarea name="notes" id="cv_notes_{{ $can->id }}" label="Ghi chú" rows="2" placeholder="Ghi chú đánh giá, lịch hẹn PV..." />
                                                <div class="flex items-center justify-end gap-1.5 pt-1">
                                                    <x-ui.button variant="ghost" size="sm" x-on:click="openEdit = false">Đóng</x-ui.button>
                                                    <x-ui.button type="submit" size="sm">Lưu</x-ui.button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6"><x-ui.empty-state title="Chưa có hồ sơ ứng viên nào trong mục này." /></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    <x-slot:footer>
                        <x-ui.pagination :paginator="$candidates" unit="hồ sơ" />
                    </x-slot:footer>
                </x-ui.data-table>
            @endif

            @if($tab === 'jobs')
                {{-- Table Jobs --}}
                <x-ui.data-table class="rounded-none border-0">
                    <table>
                        <thead>
                            <tr>
                                <th>Vị trí tuyển dụng</th>
                                <th>Bộ phận / Hình thức</th>
                                <th>Cơ sở / Địa điểm</th>
                                <th>Mức lương</th>
                                <th>Số CV nộp</th>
                                <th>Trạng thái</th>
                                <th class="text-right">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($jobs as $job)
                                <tr>
                                    <td class="text-sm font-bold">
                                        {{ $job->title }}
                                        <span class="block text-[11px] font-normal text-on-surface-variant">Hạn nộp: {{ $job->deadline ? $job->deadline->format('d/m/Y') : 'Không thời hạn' }}</span>
                                    </td>
                                    <td>
                                        <span class="font-semibold">{{ $job->department }}</span>
                                        <span class="block text-[10px] text-on-surface-variant">{{ $job->employment_type }}</span>
                                    </td>
                                    <td class="text-on-surface-variant">
                                        {{ $job->branch?->name ?? 'Toàn hệ thống' }}
                                    </td>
                                    <td class="font-semibold text-tertiary">
                                        {{ $job->salary_range ?: 'Thỏa thuận' }}
                                    </td>
                                    <td>
                                        <x-ui.badge color="secondary" pill :dot="false">{{ $job->candidate_cvs_count }} CV</x-ui.badge>
                                    </td>
                                    <td>
                                        @if($job->is_active)
                                            <x-ui.badge color="success" pill>Đang mở nhận CV</x-ui.badge>
                                        @else
                                            <x-ui.badge color="neutral" pill>Đã đóng</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <form action="{{ route('recruitment.jobs.toggle', $job->id) }}" method="POST" class="inline">
                                            @csrf
                                            <x-ui.button type="submit" variant="secondary" size="sm">{{ $job->is_active ? 'Đóng tin' : 'Mở lại' }}</x-ui.button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7"><x-ui.empty-state title="Chưa có tin tuyển dụng nào." /></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-ui.data-table>
            @endif
        </div>

        {{-- Modal Đăng tin tuyển dụng --}}
        <x-ui.modal name="new-job" title="Đăng tin tuyển dụng mới" max-width="xl">
            <form id="new-job-form" action="{{ route('recruitment.jobs.store') }}" method="POST" class="space-y-3">
                @csrf
                <x-ui.input name="title" label="Tiêu đề vị trí tuyển dụng" required placeholder="Ví dụ: Giáo viên Tiếng Anh Giao Tiếp Full-time" />

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select name="department" label="Bộ phận / Khối" required
                                 :options="['Học thuật & Đào tạo' => 'Học thuật & Đào tạo', 'Học vụ & Vận hành' => 'Học vụ & Vận hành', 'Tuyển sinh & CRM' => 'Tuyển sinh & CRM', 'Marketing & Sự kiện' => 'Marketing & Sự kiện']" />
                    <x-ui.select name="employment_type" label="Hình thức làm việc" required
                                 :options="['Full-time' => 'Full-time', 'Part-time' => 'Part-time', 'Thực tập' => 'Thực tập']" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.select name="branch_id" id="job_branch_id" label="Cơ sở làm việc" placeholder="Toàn hệ thống" :value="''" :options="$branches->pluck('name', 'id')" />
                    <x-ui.input name="salary_range" label="Mức lương dự kiến" placeholder="Ví dụ: 12 - 18 triệu hoặc 300k/giờ" />
                </div>

                <x-ui.textarea name="description" label="Mô tả công việc" rows="3" required placeholder="Nêu chi tiết nhiệm vụ chính..." />

                <x-ui.textarea name="requirements" label="Yêu cầu ứng viên" rows="2" placeholder="IELTS 7.0+, phát âm chuẩn, nhiệt huyết..." />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'new-job')">Hủy</x-ui.button>
                <x-ui.button type="submit" form="new-job-form">Đăng tin ngay</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-app-layout>
