<x-app-layout>
    <x-ui.page-header title="Nhắc lịch Big Test" description="Danh sách các chặng học sắp đến hạn thi Big Test (trong vòng 7 ngày) chưa được duyệt đề thi." :back="route('syllabus.documents')">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="event_note" :href="route('syllabus.teaching-stages')">Lịch dự kiến theo lớp</x-ui.button>
            <x-ui.button icon="add_circle" :href="route('syllabus.big-tests.distribution')">Tạo đợt Big Test</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @php($urgent = $upcoming->filter(fn ($row) => $row['days_left'] <= 2)->count())

    {{-- Mockup 01_Web_Admin/08: thẻ tổng quan + bảng chặng (mã chặng, ngày thi dự kiến, trạng thái đề, số ngày còn lại). --}}
    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-md max-w-3xl">
            <x-ui.stat-card label="Tổng số chặng" :value="$upcoming->count()" icon="assignment" tone="primary" />
            <x-ui.stat-card label="Khẩn cấp (1-2 ngày)" :value="$urgent" icon="warning" tone="error" />
        </div>

        <x-ui.data-table min-width="720px">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">notification_important</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Chặng sắp thi, chưa duyệt đề</h2>
                </div>
                <p class="font-caption text-caption text-on-surface-variant">Giáo viên lớp được tự động nhắc trước 7 ngày (đợt thi đã tạo); ngày thi lấy theo đợt Big Test của chặng, hoặc ngày dự kiến GV đặt / ngày thi trong order.</p>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Mã chặng</th>
                        <th>Lớp / Chặng</th>
                        <th>Ngày thi dự kiến</th>
                        <th>Trạng thái đề</th>
                        <th class="text-right">Số ngày còn lại</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($upcoming as $row)
                        @php($as = $row['assignment'])
                        <tr>
                            <td>
                                <span class="inline-flex items-center gap-xs font-mono font-semibold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">local_library</span>{{ $as->code }}</span>
                            </td>
                            <td>
                                <p class="font-body-medium text-body-small text-on-surface">{{ $as->classModel?->name }}</p>
                                <p class="font-caption text-caption text-on-surface-variant">{{ $as->stage?->label ?? $as->stage_name }}</p>
                            </td>
                            <td class="font-mono">{{ $row['date']->format('d/m/Y') }}</td>
                            <td>
                                <x-ui.badge color="error">{{ $row['exam'] === 'pending' ? 'Chưa duyệt đề' : 'Chưa order đề' }}</x-ui.badge>
                            </td>
                            <td class="text-right">
                                <span class="font-h3 text-h3 {{ $row['days_left'] <= 2 ? 'text-error' : ($row['days_left'] <= 3 ? 'text-amber-600' : 'text-on-surface') }}">{{ $row['days_left'] }}</span>
                                <span class="font-caption text-caption text-on-surface-variant">ngày</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="flex flex-col items-center gap-sm py-xl text-center">
                                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-tertiary/10 text-tertiary"><span class="material-symbols-outlined text-[28px]">task_alt</span></span>
                                    <h3 class="font-h3 text-h3 text-on-surface">Tất cả đều ổn!</h3>
                                    <p class="font-body-small text-body-small text-on-surface-variant">Không có chặng học nào sắp tới hạn chưa duyệt đề.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>

        {{-- Đợt thi đã tạo: nhắc lịch học viên, xem điểm --}}
        <x-ui.data-table min-width="980px">
            <x-slot:header>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">event_note</span>
                    <h2 class="font-h3 text-h3 text-on-surface">Đợt thi Big Test đã tạo</h2>
                </div>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Mã đợt thi</th>
                        <th>Lớp thi</th>
                        <th>Tên bài thi</th>
                        <th>Ngày &amp; Giờ thi</th>
                        <th>Phòng thi &amp; Cơ sở</th>
                        <th>Giám thị coi thi</th>
                        <th>Mật mã thi</th>
                        <th class="text-right">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bigTests as $bt)
                        <tr>
                            <td class="font-mono font-semibold">{{ $bt->code }}</td>
                            <td class="font-semibold text-on-surface">{{ $bt->classModel?->name }}</td>
                            <td class="text-primary">{{ $bt->title }}</td>
                            <td class="font-mono text-on-surface-variant">{{ $bt->scheduled_at ? $bt->scheduled_at->format('d/m/Y H:i') : '—' }}</td>
                            <td>{{ $bt->room }} · {{ $bt->classModel?->branch?->name ?? '—' }}</td>
                            <td>{{ $bt->proctor?->name ?? '—' }}</td>
                            <td class="font-mono font-bold text-tertiary">{{ $bt->passcodeVisibleTo(auth()->user()) ? $bt->passcode : '••••••' }}</td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @can('syllabus.manage')
                                        <form action="{{ route('syllabus.big-tests.remind', $bt->id) }}" method="POST" class="inline" data-confirm="Gửi nhắc lịch {{ $bt->title }} tới toàn bộ học viên của lớp {{ $bt->classModel?->name }}?">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary" icon="notifications_active" title="Gửi thông báo nhắc lịch vào Cổng PH/HS">Nhắc lịch</x-ui.button>
                                        </form>
                                    @endcan
                                    <x-ui.button variant="ghost" size="sm" :href="route('syllabus.big-tests.results', $bt->id)">Xem điểm</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-ui.empty-state icon="event_busy" title="Chưa có lịch thi Big Test nào" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-slot:footer><x-ui.pagination :paginator="$bigTests" unit="đợt thi" /></x-slot:footer>
        </x-ui.data-table>
    </div>
</x-app-layout>
