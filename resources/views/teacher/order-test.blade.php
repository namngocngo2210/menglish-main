<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('syllabus.teaching-stages') }}" class="p-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-500 hover:text-gray-900 transition">
                <span class="material-symbols-outlined text-[18px]">arrow_back</span>
            </a>
            <div>
                <h1 class="font-h1 text-h1 text-on-surface">Order đề test — {{ $class->name }}</h1>
                <p class="font-body-base text-on-surface-variant">{{ $class->course?->name ?? $class->program }} · Gửi yêu cầu cấp đề Mini/Big Test cho chặng đang dạy tới Ban Học thuật</p>
            </div>
        </div>
    </x-slot>

    {{-- Mockup 03_Cong_Giao_Vien/07: order đề gắn chặng đang mở của lớp (A6 Q4), không nhập tên chặng tự do. --}}
    <div class="space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Chặng đang dạy -->
            <section class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm p-lg space-y-md">
                <h2 class="font-h3 text-h3 text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">flag</span>
                    Chặng đang dạy
                </h2>
                @if ($openAssignment)
                    <div class="rounded-lg border border-primary-container/40 bg-primary-fixed/20 p-md space-y-xs">
                        <p class="font-body-medium text-body-medium font-semibold text-on-surface flex items-center gap-xs"><span class="material-symbols-outlined text-[18px] text-primary">flag</span>{{ $openAssignment->stage?->label ?? $openAssignment->stage_name }}</p>
                        <p class="font-body-small text-body-small text-on-surface-variant flex items-center gap-xs"><span class="material-symbols-outlined text-[16px]">calendar_today</span>Bắt đầu: {{ ($openAssignment->opened_at ?? $openAssignment->created_at)?->format('d/m/Y') }}</p>
                        <p class="font-caption text-caption text-on-surface-variant">{{ $openAssignment->assigned_chapters }}</p>
                    </div>
                @else
                    <div class="flex flex-col items-center text-center gap-xs py-lg">
                        <span class="material-symbols-outlined text-[36px] text-on-surface-variant">inventory_2</span>
                        <p class="font-body-medium text-body-medium text-on-surface">Chưa được giao chặng nào</p>
                        <p class="font-body-small text-body-small text-on-surface-variant">Hiện tại lớp chưa có chặng học nào đang mở. Vui lòng liên hệ Quản lý chuyên môn nếu có sai sót.</p>
                    </div>
                @endif
                @php($closed = $assignments->reject(fn ($a) => $a->isOpen()))
                @if ($closed->isNotEmpty())
                    <div>
                        <p class="font-label text-label text-on-surface-variant uppercase mb-xs">Chặng đã học</p>
                        <ul class="space-y-1 font-body-small text-body-small text-on-surface-variant">
                            @foreach ($closed as $asg)
                                <li class="flex items-center gap-xs"><span class="material-symbols-outlined text-[16px] text-tertiary">check_circle</span>{{ $asg->stage?->label ?? $asg->stage_name }} · đóng {{ $asg->closed_at?->format('d/m/Y') }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <!-- Form yêu cầu đề -->
            <form action="{{ route('teacher.order-test.submit', $class->id) }}" method="POST" class="bg-surface-container-lowest rounded-xl border border-outline-variant shadow-sm p-lg space-y-md">
                @csrf
                <h2 class="font-h3 text-h3 text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">assignment_add</span>
                    Order đề Big Test
                </h2>
                <x-ui.field label="Chặng" name="stage">
                    <div class="rounded-lg border border-outline-variant bg-surface-container-low px-md py-sm font-body-base text-body-base font-semibold text-on-surface">{{ $openAssignment ? ($openAssignment->stage?->label ?? $openAssignment->stage_name) : 'Lớp chưa mở chặng' }}</div>
                </x-ui.field>
                <div>
                    <label class="block font-label text-label text-on-surface-variant mb-1">Loại đề <span class="text-error">*</span></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2 border border-outline-variant rounded-lg px-3 py-2 cursor-pointer hover:bg-surface-container-low">
                            <input type="radio" name="test_type" value="big" @checked(old('test_type', 'big') === 'big') class="text-primary focus:ring-primary-container" />
                            <span class="font-body-small text-body-small font-semibold">Big Test (cuối chặng)</span>
                        </label>
                        <label class="flex items-center gap-2 border border-outline-variant rounded-lg px-3 py-2 cursor-pointer hover:bg-surface-container-low">
                            <input type="radio" name="test_type" value="mini" @checked(old('test_type') === 'mini') class="text-primary focus:ring-primary-container" />
                            <span class="font-body-small text-body-small font-semibold">Mini Test</span>
                        </label>
                    </div>
                </div>
                <div>
                    <x-ui.input type="date" name="exam_date" label="Ngày thi dự kiến" :value="old('exam_date', $openAssignment?->expected_big_test_date?->toDateString())" min="{{ now()->toDateString() }}"
                                hint="Hạn xử lý của Học thuật = ngày thi − {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày (để trống: trong {{ \App\Models\BigTestOrder::LEAD_DAYS }} ngày)." />
                </div>
                <x-ui.textarea name="note" label="Ghi chú cho Học thuật" rows="3" placeholder="VD: đề trọng tâm Listening Part 1-2, độ khó vừa phải..." />
                <x-ui.button type="submit" icon="send" class="w-full" :disabled="! $openAssignment">Gửi yêu cầu tới Ban Học thuật</x-ui.button>
            </form>
        </div>

        <!-- Lịch sử yêu cầu -->
        <x-ui.data-table min-width="760px">
            <x-slot:header>
                <h2 class="font-h3 text-h3 text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">history</span>
                    Yêu cầu đã gửi cho lớp này
                </h2>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Loại đề</th>
                        <th>Chặng</th>
                        <th>Ngày thi / Hạn xử lý</th>
                        <th>Ghi chú</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $req)
                        <tr>
                            <td class="font-mono text-on-surface-variant">{{ $req->created_at->format('d/m/Y H:i') }}</td>
                            <td class="font-semibold">{{ $req->type_label }}</td>
                            <td class="font-semibold text-on-surface">{{ $req->stage_label }}</td>
                            <td class="text-on-surface-variant">
                                <div>Thi: {{ $req->exam_date?->format('d/m/Y') ?? '—' }}</div>
                                <div class="font-caption text-caption">Hạn: {{ $req->due_date?->format('d/m/Y') ?? '—' }}</div>
                            </td>
                            <td class="text-on-surface-variant">{{ $req->note ?: '—' }}</td>
                            <td class="space-y-1">
                                <x-ui.badge :color="$req->status_color">{{ $req->status === 'pending' ? 'Đã order - Chờ HT duyệt' : $req->status_label }}</x-ui.badge>
                                @if ($req->status === 'approved')
                                    {{-- GV chỉ xem phần Speaking của đề sau khi phân phối; link đề đầy đủ chỉ Học thuật xem. --}}
                                    @can('big_test.approve')
                                        @if ($req->test_link)
                                            <a href="{{ $req->test_link }}" target="_blank" rel="noopener" class="block font-caption text-caption text-primary font-semibold hover:underline">Mở link đề</a>
                                        @endif
                                    @endcan
                                    @if ($req->speaking_link)
                                        <a href="{{ $req->speaking_link }}" target="_blank" rel="noopener" class="block font-caption text-caption text-primary font-semibold hover:underline">Mở phần Speaking</a>
                                    @else
                                        <p class="font-caption text-caption text-on-surface-variant">Chưa có link phần Speaking</p>
                                    @endif
                                @elseif ($req->status === 'rejected' && $req->rejection_reason)
                                    <p class="font-caption text-caption text-error">Lý do: {{ $req->rejection_reason }}</p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state icon="assignment" title="Chưa gửi yêu cầu đề test nào cho lớp này" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>

    <div class="h-20 md:hidden" aria-hidden="true"></div>
    @include('teacher.partials.bottom-nav')
</x-app-layout>
