{{-- Mockup: ui-full-tinh-nang-menglish/hoc-phi-va-hoa-don-ui-mockup/hoan-tien-va-khat-no + A6 (25/09/2026) "Hoàn phí" --}}
<x-app-layout title="Xử lý khất nợ / hoàn tiền" hide-errors>
    @php
        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'đ';
        $typeBadge = ['transfer' => 'success', 'refund' => 'secondary', 'deferral' => 'info', 'extension' => 'warning'];
        $targetList = $students->map(fn ($st) => [
            'id' => (string) $st->id,
            'name' => $st->name,
            'code' => $st->code,
            'class' => $st->currentClass?->name,
            'debt' => (float) ($st->tuition?->debt_amount ?? 0),
            'search' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($st->name.' '.$st->code)),
        ])->values();
        $oldType = old('type');
    @endphp

    <x-ui.page-header title="Xử lý khất nợ / hoàn tiền"
                      description="Quản lý các yêu cầu tài chính phát sinh trong quá trình học tập: khất nợ, bảo lưu, chuyển nhượng buổi dư và hoàn tiền.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow_back" :href="route('tuition.students')">Danh sách thu phí</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('tuition.partials.errors')

    <x-ui.alert type="info" class="mb-lg" title="Quy định hoàn phí (A6)">
        Xử lý <strong>trong 1 tuần</strong> kể từ ngày lập và <strong>trong cùng tháng phát sinh</strong> để khớp sổ sách.
        <strong>Ưu tiên chuyển nhượng</strong> buổi dư cho học viên khác, hoàn tiền là phương án cuối. Hoàn tiền do <strong>Admin</strong> duyệt và
        <strong>bắt buộc ảnh bằng chứng</strong> chi tiền. Hồ sơ quá hạn được gắn cờ "Quá hạn xử lý" nhưng vẫn duyệt được.
    </x-ui.alert>

    <div class="grid grid-cols-12 gap-lg" x-data="refundTransferManager(@js($studentFinance), @js((string) (old('student_id') ?? $students->first()?->id ?? '')), @js((float) $adminFeePercent), @js($targetList), @js(in_array($oldType, ['transfer', 'refund', 'deferral'], true) ? $oldType : 'transfer'), @js((string) old('target_student_id', '')))">
        {{-- Cột trái: các khối nghiệp vụ --}}
        <div class="col-span-12 flex flex-col gap-lg lg:col-span-7">
            {{-- Đánh dấu khất nợ: dời hạn đóng, vẫn giữ lịch học; duyệt xong tạm dừng nhắc nợ tới hạn mới. --}}
            <form action="{{ route('tuition.refunds.store') }}" method="POST" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                @csrf
                <input type="hidden" name="type" value="extension" />
                <div class="mb-md flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">event_busy</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Đánh dấu khất nợ</h3>
                </div>
                <div class="mb-md flex items-start gap-sm rounded-lg bg-secondary/5 p-md font-body-small text-body-small text-secondary">
                    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">info</span>
                    <p>Tiếp tục quy trình nhắc nợ chuẩn, không khóa lịch học của học viên. Khi được duyệt, hạn đóng được dời sang ngày mới và nhắc nợ tạm dừng tới ngày đó.</p>
                </div>
                <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                    <label class="block md:col-span-2">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Học viên đang nợ <span class="text-error">*</span></span>
                        <select name="student_id" required class="w-full rounded-lg border-outline-variant font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20">
                            @forelse ($students->filter(fn ($st) => (float) ($st->tuition?->debt_amount ?? 0) > 0) as $st)
                                <option value="{{ $st->id }}">{{ $st->code }} - {{ $st->name }} · Còn nợ {{ $money($st->tuition->debt_amount) }} · Hạn {{ $st->tuition->due_date?->format('d/m/Y') ?? 'chưa đặt' }}</option>
                            @empty
                                <option value="">Không có học viên còn nợ</option>
                            @endforelse
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Hạn đóng mới <span class="text-error">*</span></span>
                        <input type="date" name="extended_due_date" value="{{ old('extended_due_date') }}" min="{{ now()->addDay()->toDateString() }}" required class="w-full rounded-lg border-outline-variant font-code text-code focus:border-primary-container focus:ring-primary-container/20" />
                    </label>
                    <label class="block md:col-span-3">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lý do khất nợ (Bắt buộc)</span>
                        <textarea name="reason" rows="2" required placeholder="Nhập chi tiết lý do học viên xin gia hạn thời gian nộp học phí..." class="w-full rounded-lg border-outline-variant font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20"></textarea>
                    </label>
                </div>
                <div class="mt-md flex justify-end">
                    <x-ui.button type="submit" icon="event_available">Xác nhận khất nợ</x-ui.button>
                </div>
            </form>

            {{-- Tạo yêu cầu xử lý nghỉ giữa khóa: chuyển nhượng (ưu tiên) / hoàn tiền (phương án cuối) / bảo lưu --}}
            <form action="{{ route('tuition.refunds.store') }}" method="POST" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                @csrf
                <div class="mb-md flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">exit_to_app</span>
                    <h3 class="font-h3 text-h3 text-on-surface">Tạo yêu cầu xử lý nghỉ giữa khóa</h3>
                </div>

                <input type="hidden" name="type" :value="actionType" />
                <input type="hidden" name="total_paid" :value="basis.paid" />
                <input type="hidden" name="attended_lessons" :value="attendedLessons" />
                <input type="hidden" name="admin_fee" :value="actionType === 'refund' ? adminFee : 0" />

                <label class="mb-md block">
                    <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Học viên nguồn <span class="text-error">*</span></span>
                    <select name="student_id" x-model="selectedStudentId" @change="resetFromBasis()" required class="w-full rounded-lg border-outline-variant font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20">
                        @foreach ($students as $st)
                            <option value="{{ $st->id }}">{{ $st->code }} - {{ $st->name }} ({{ $st->currentClass?->name ?? 'Chưa gán lớp' }})</option>
                        @endforeach
                    </select>
                </label>

                {{-- Tóm tắt học viên (số liệu thật từ hợp đồng & điểm danh) --}}
                <div class="mb-lg grid grid-cols-2 gap-md rounded-lg border border-outline-variant bg-surface-container-low p-md sm:grid-cols-4">
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Học viên</p>
                        <p class="font-body-medium text-body-medium text-on-surface" x-text="studentName"></p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Mã HV</p>
                        <p class="font-code text-code text-on-surface" x-text="studentCode"></p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Buổi dư</p>
                        <p class="font-body-medium text-body-medium text-primary" x-text="basis.total_sessions ? (remainingLessons + ' buổi') : '—'"></p>
                        <p class="font-caption text-caption text-on-surface-variant" x-show="basis.total_sessions" x-text="'Đã học ' + attendedLessons + ' / ' + basis.total_sessions"></p>
                    </div>
                    <div>
                        <p class="font-label text-label uppercase text-on-surface-variant">Đã thu</p>
                        <p class="font-body-medium text-body-medium text-tertiary" x-text="money(basis.paid)"></p>
                    </div>
                    <template x-if="!basis.has_tuition">
                        <p class="col-span-full font-body-small text-body-small text-error">Học viên chưa có hồ sơ học phí — không thể hoàn / chuyển nhượng / bảo lưu.</p>
                    </template>
                </div>

                {{-- Hình thức xử lý: chuyển nhượng đứng đầu (ưu tiên theo A6) --}}
                <fieldset class="mb-lg">
                    <legend class="mb-sm font-label text-label uppercase text-on-surface-variant">Hình thức xử lý</legend>
                    <div class="grid grid-cols-1 gap-sm sm:grid-cols-3">
                        @foreach (['transfer' => ['swap_horiz', 'Chuyển nhượng', 'Ưu tiên'], 'refund' => ['payments', 'Hoàn tiền', 'Phương án cuối'], 'deferral' => ['pause_circle', 'Bảo lưu', null]] as $value => [$icon, $label, $hint])
                            <label class="flex cursor-pointer items-center gap-sm rounded-lg border p-md transition-colors"
                                   :class="actionType === '{{ $value }}' ? 'border-primary-container bg-primary-fixed/30' : 'border-outline-variant hover:bg-surface-container-low'">
                                <input type="radio" value="{{ $value }}" x-model="actionType" class="text-primary-container focus:ring-primary-container/30" />
                                <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">{{ $icon }}</span>
                                <span class="min-w-0">
                                    <span class="block font-body-medium text-body-medium text-on-surface">{{ $label }}</span>
                                    @if ($hint)<span class="block font-caption text-caption text-on-surface-variant">{{ $hint }}</span>@endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- Chuyển nhượng: tìm học viên nhận --}}
                <div x-show="actionType === 'transfer'" class="mb-lg space-y-sm">
                    <span class="block font-label text-label uppercase text-on-surface-variant">Học viên nhận chuyển nhượng <span class="text-error">*</span></span>
                    <input type="hidden" name="target_student_id" :value="actionType === 'transfer' ? targetId : ''" />
                    <div class="relative">
                        <span class="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant" aria-hidden="true">search</span>
                        <input type="search" x-model="targetQuery" placeholder="Tìm tên hoặc mã học viên..." aria-label="Tìm học viên nhận chuyển nhượng"
                               class="w-full rounded-lg border-outline-variant py-sm pl-10 font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20" />
                    </div>
                    <ul class="custom-scrollbar max-h-48 divide-y divide-surface-container overflow-y-auto rounded-lg border border-outline-variant">
                        <template x-for="t in targetMatches" :key="t.id">
                            <li class="flex items-center justify-between gap-sm p-sm" :class="targetId === t.id ? 'bg-tertiary/5' : ''">
                                <span class="min-w-0">
                                    <span class="block truncate font-body-medium text-body-medium text-on-surface" x-text="t.name"></span>
                                    <span class="block font-code text-caption text-on-surface-variant" x-text="t.code + (t.class ? ' · ' + t.class : '') + ' · Còn nợ ' + money(t.debt)"></span>
                                </span>
                                <button type="button" @click="targetId = t.id" class="rounded-lg px-sm py-xs font-body-medium text-body-small"
                                        :class="targetId === t.id ? 'bg-tertiary text-white' : 'border border-outline-variant text-on-surface hover:bg-surface-container-low'"
                                        x-text="targetId === t.id ? 'Đã chọn' : 'Chọn'"></button>
                            </li>
                        </template>
                        <li x-show="targetMatches.length === 0" class="p-sm font-body-small text-body-small text-on-surface-variant">Không tìm thấy học viên phù hợp.</li>
                    </ul>
                    <p x-show="targetId" class="flex items-center gap-xs font-body-small text-body-small text-tertiary">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">verified</span>
                        Toàn bộ số buổi dư sẽ được chuyển sang học viên đích kèm giá trị tiền tương ứng (cấn trừ công nợ người nhận).
                    </p>
                </div>

                {{-- Hoàn tiền: gợi ý chuyển nhượng trước + bắt buộc lý do không chuyển nhượng --}}
                <div x-show="actionType === 'refund'" class="mb-lg space-y-sm rounded-lg border border-amber-300 bg-amber-50 p-md">
                    <p class="flex items-start gap-sm font-body-small text-body-small text-amber-800">
                        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">lightbulb</span>
                        <span>Hoàn tiền là <strong>phương án cuối</strong>. Hãy ưu tiên <strong>chuyển nhượng</strong> <span x-text="basis.total_sessions ? remainingLessons + ' buổi dư' : 'số buổi dư'"></span> cho học viên khác (không thu hồi hoa hồng, không phát sinh chi tiền).</span>
                    </p>
                    <x-ui.button size="sm" variant="secondary" icon="swap_horiz" @click="actionType = 'transfer'">Chuyển sang chuyển nhượng</x-ui.button>
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lý do không chuyển nhượng (Bắt buộc)</span>
                        <textarea name="no_transfer_reason" rows="2" :required="actionType === 'refund'" :disabled="actionType !== 'refund'"
                                  placeholder="VD: gia đình chuyển nơi ở, không có học viên nhận, phụ huynh yêu cầu hoàn tiền..."
                                  class="w-full rounded-lg border-outline-variant font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20">{{ old('no_transfer_reason') }}</textarea>
                    </label>
                </div>

                {{-- Bảng tính hoàn phí / chuyển nhượng --}}
                <div x-show="actionType !== 'deferral'" class="mb-lg space-y-sm rounded-lg border border-outline-variant p-md">
                    <div class="grid grid-cols-2 gap-md sm:grid-cols-4">
                        <label class="block">
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant">Số buổi đã học (điểm danh)</span>
                            <input type="number" x-model.number="attendedLessons" min="0" class="w-full rounded-lg border-outline-variant p-xs font-code text-code" />
                        </label>
                        <div>
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant">Đơn giá / buổi</span>
                            <span class="font-code text-code text-on-surface" x-text="money(unitPrice)"></span>
                        </div>
                        <div>
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant">Giá trị buổi còn lại</span>
                            <span class="font-code text-code text-on-surface" x-text="money(remainingValue)"></span>
                        </div>
                        <div x-show="actionType === 'refund'">
                            <span class="mb-xs block font-caption text-caption text-on-surface-variant" x-text="'Phí quản trị (' + feePercent + '%)'"></span>
                            <span class="font-code text-code text-on-surface" x-text="money(adminFee)"></span>
                        </div>
                    </div>
                    <div class="flex flex-col gap-sm border-t border-surface-container pt-sm sm:flex-row sm:items-center sm:justify-between">
                        <label for="refundAmount" class="font-body-medium text-body-medium text-on-surface" x-text="actionType === 'transfer' ? 'Số tiền chuyển nhượng' : 'Số tiền hoàn trả'"></label>
                        <div class="flex items-center gap-sm">
                            <input id="refundAmount" type="number" name="refund_amount" x-model.number="refundAmount" min="0" :max="basis.paid" :disabled="actionType === 'deferral'" class="w-44 rounded-lg border-outline-variant text-right font-code text-code" />
                            <span class="font-body-small text-body-small text-on-surface-variant">VND</span>
                            <x-ui.button size="sm" variant="ghost" icon="calculate" @click="refundAmount = suggestedAmount" title="Tính lại theo chính sách">Theo chính sách</x-ui.button>
                        </div>
                    </div>
                    <p class="font-caption text-caption text-on-surface-variant">
                        Số dư khả dụng tối đa: <strong class="font-code" x-text="money(basis.paid)"></strong> · Đề xuất theo chính sách: <strong class="font-code" x-text="money(suggestedAmount)"></strong>. Khi duyệt hệ thống chặn số tiền vượt số đã nộp.
                    </p>
                </div>

                {{-- Bảo lưu --}}
                <div x-show="actionType === 'deferral'" class="mb-lg grid grid-cols-1 gap-md rounded-lg border border-blue-200 bg-blue-50/60 p-md sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Bảo lưu từ ngày <span class="text-error">*</span></span>
                        <input type="date" name="defer_from" value="{{ old('defer_from', now()->toDateString()) }}" :disabled="actionType !== 'deferral'" class="w-full rounded-lg border-outline-variant font-code text-code" />
                    </label>
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Đến ngày (học lại từ ngày kế tiếp) <span class="text-error">*</span></span>
                        <input type="date" name="defer_to" value="{{ old('defer_to') }}" :disabled="actionType !== 'deferral'" class="w-full rounded-lg border-outline-variant font-code text-code" />
                    </label>
                    <p class="font-body-small text-body-small text-blue-900 sm:col-span-2">Khi được duyệt: học viên chuyển trạng thái <strong>Bảo lưu</strong>, đóng băng <strong x-text="basis.total_sessions ? remainingLessons + ' buổi còn lại' : 'số buổi còn lại'"></strong> và công nợ <strong class="font-code" x-text="money(basis.debt)"></strong>; nhắc nợ tạm dừng tới hết ngày bảo lưu.</p>
                </div>

                <label class="block">
                    <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lý do nghỉ giữa khóa (Bắt buộc)</span>
                    <textarea name="reason" rows="3" required placeholder="Nhập chi tiết nguyên nhân học viên dừng học..." class="w-full rounded-lg border-outline-variant font-body-base text-body-base focus:border-primary-container focus:ring-primary-container/20">{{ old('type') !== 'extension' ? old('reason') : '' }}</textarea>
                </label>

                <div class="mt-lg flex flex-col items-end gap-xs border-t border-surface-container pt-md">
                    <p x-show="actionType !== 'deferral'" class="font-caption text-caption text-on-surface-variant">
                        Hạn xử lý: <strong>{{ \App\Models\TuitionRefundRequest::deadlineFor(now())->format('d/m/Y') }}</strong> (1 tuần, trong tháng {{ now()->format('m/Y') }})<span x-show="actionType === 'refund'"> · Admin duyệt</span>
                    </p>
                    <x-ui.button type="submit" icon="send" x-bind:disabled="!basis.has_tuition">Gửi yêu cầu phê duyệt</x-ui.button>
                </div>
            </form>
        </div>

        {{-- Cột phải: yêu cầu chờ phê duyệt --}}
        <div class="col-span-12 lg:col-span-5">
            <section class="flex h-full flex-col rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm">
                <div class="mb-lg flex items-center justify-between gap-sm">
                    <div class="flex items-center gap-sm">
                        <span class="material-symbols-outlined text-tertiary" aria-hidden="true">fact_check</span>
                        <h3 class="font-h3 text-h3 text-on-surface">Yêu cầu chờ phê duyệt</h3>
                    </div>
                    @if ($overdueCount > 0)
                        <span class="flex items-center gap-xs rounded-full bg-error/10 px-md py-xs font-label text-label text-error">
                            <span class="material-symbols-outlined text-[14px]" aria-hidden="true">warning</span>
                            {{ $overdueCount }} QUÁ HẠN
                        </span>
                    @endif
                </div>
                <div class="custom-scrollbar flex-1 overflow-x-auto">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="bg-surface-container-low">
                                <th class="p-sm font-label text-label uppercase text-on-surface-variant">Học viên nguồn</th>
                                <th class="p-sm font-label text-label uppercase text-on-surface-variant">Nội dung</th>
                                <th class="p-sm font-label text-label uppercase text-on-surface-variant">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant">
                            @forelse ($pendingRequests as $rq)
                                @php
                                    $overdue = $rq->isProcessingOverdue();
                                    $needsAdmin = $rq->type === 'refund' && ! $canApproveRefund;
                                @endphp
                                <tr class="align-top hover:bg-surface-container-high">
                                    <td class="p-sm">
                                        <p class="font-body-medium text-body-medium text-on-surface">{{ $rq->student?->name }}</p>
                                        <p class="font-code text-caption text-on-surface-variant">{{ $rq->student?->code }}</p>
                                        @if ($overdue)
                                            <span class="mt-xs inline-block rounded bg-error-container px-xs py-[2px] text-[10px] font-bold uppercase text-on-error-container">Quá hạn xử lý · {{ $rq->processingOverdueDays() }} ngày</span>
                                        @elseif ($rq->processing_deadline)
                                            <span class="mt-xs block font-caption text-caption text-on-surface-variant">Hạn xử lý {{ $rq->processing_deadline->format('d/m/Y') }}</span>
                                        @endif
                                    </td>
                                    <td class="p-sm">
                                        <x-ui.badge :color="$typeBadge[$rq->type] ?? 'neutral'" pill :dot="false">{{ $rq->type_label }}</x-ui.badge>
                                        <p class="mt-xs font-body-small text-body-small text-on-surface">
                                            @if ($rq->type === 'transfer')
                                                {{ $money($rq->refund_amount) }} → {{ $rq->targetStudent?->code ?? '—' }}
                                            @elseif ($rq->type === 'refund')
                                                <strong>{{ $money($rq->refund_amount) }}</strong>
                                            @elseif ($rq->type === 'deferral')
                                                {{ $rq->defer_from?->format('d/m/Y') }} – {{ $rq->defer_to?->format('d/m/Y') }}
                                            @else
                                                Hạn mới {{ $rq->extended_due_date?->format('d/m/Y') }}
                                            @endif
                                        </p>
                                        <p class="mt-[2px] font-caption text-caption text-on-surface-variant">{{ $rq->created_at->format('d/m/Y') }} · {{ $rq->requester?->name ?? '—' }}</p>
                                    </td>
                                    <td class="p-sm">
                                        @can('refund_transfer.approve')
                                            <div class="flex gap-sm">
                                                @if ($needsAdmin)
                                                    <span class="font-caption text-caption text-on-surface-variant" title="A6: chỉ Admin duyệt hoàn tiền">Chờ Admin duyệt</span>
                                                @else
                                                    <button type="button" @click="$dispatch('open-modal', 'approve-refund-{{ $rq->id }}')" title="Duyệt" aria-label="Duyệt"
                                                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-tertiary text-white shadow-sm hover:brightness-110 active:scale-90">
                                                        <span class="material-symbols-outlined text-[18px]">check</span>
                                                    </button>
                                                @endif
                                                <button type="button" @click="$dispatch('open-modal', 'reject-refund-{{ $rq->id }}')" title="Từ chối" aria-label="Từ chối"
                                                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-error text-error hover:bg-error/10 active:scale-90">
                                                    <span class="material-symbols-outlined text-[18px]">close</span>
                                                </button>
                                            </div>
                                        @else
                                            <span class="font-caption text-caption text-on-surface-variant">Chờ duyệt</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3"><x-ui.empty-state icon="task_alt" title="Không có yêu cầu chờ phê duyệt" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <a href="#all-requests" class="mt-md block w-full rounded-lg py-sm text-center font-body-medium text-body-medium text-primary hover:bg-primary/5">Xem tất cả yêu cầu</a>
            </section>
        </div>
    </div>

    {{-- Hộp duyệt / từ chối từng hồ sơ chờ --}}
    @can('refund_transfer.approve')
        @foreach ($pendingRequests as $rq)
            @php $overdue = $rq->isProcessingOverdue(); @endphp
            @if (! ($rq->type === 'refund' && ! $canApproveRefund))
                <x-ui.modal :name="'approve-refund-'.$rq->id" :title="'Duyệt '.mb_strtolower($rq->type_label).' — '.$rq->student?->name" max-width="md">
                    <form id="approve-refund-form-{{ $rq->id }}" action="{{ route('tuition.refunds.approve', $rq->id) }}" method="POST" enctype="multipart/form-data" class="space-y-md">
                        @csrf
                        @if ($overdue)
                            <x-ui.alert type="warning">Hồ sơ đã <strong>quá hạn xử lý</strong> (hạn {{ $rq->processing_deadline->format('d/m/Y') }}). Vẫn duyệt được; hồ sơ giữ cờ "Quá hạn xử lý".</x-ui.alert>
                        @endif
                        <dl class="grid grid-cols-2 gap-sm font-body-small text-body-small">
                            <dt class="text-on-surface-variant">Học viên</dt><dd class="text-on-surface">{{ $rq->student?->code }} - {{ $rq->student?->name }}</dd>
                            @if (in_array($rq->type, ['refund', 'transfer'], true))
                                <dt class="text-on-surface-variant">Số tiền</dt><dd class="font-code text-on-surface">{{ $money($rq->refund_amount) }}</dd>
                            @endif
                            @if ($rq->targetStudent)
                                <dt class="text-on-surface-variant">Học viên nhận</dt><dd class="text-on-surface">{{ $rq->targetStudent->code }} - {{ $rq->targetStudent->name }}</dd>
                            @endif
                            <dt class="text-on-surface-variant">Lý do</dt><dd class="text-on-surface">{{ $rq->reason }}</dd>
                            @if ($rq->no_transfer_reason)
                                <dt class="text-on-surface-variant">Lý do không chuyển nhượng</dt><dd class="text-on-surface">{{ $rq->no_transfer_reason }}</dd>
                            @endif
                        </dl>
                        @if ($rq->type === 'refund')
                            @if (isset($clawbackHints[$rq->id]))
                                @php $hint = $clawbackHints[$rq->id]; @endphp
                                {{-- A6: người duyệt chọn thu hồi hoa hồng; gợi ý "Có" nếu học viên học chưa tới 1 tháng --}}
                                <div class="space-y-xs rounded-lg border border-outline-variant p-sm" x-data="{ claw: '{{ $hint['suggest'] ? '1' : '0' }}' }">
                                    <span class="block font-label text-label uppercase text-on-surface-variant">Thu hồi hoa hồng{{ $hint['owner'] ? ' ('.$hint['owner'].')' : '' }}</span>
                                    <div class="flex flex-wrap items-center gap-sm">
                                        <select name="clawback_commission" x-model="claw" class="rounded-lg border-outline-variant py-xs font-body-small text-body-small">
                                            <option value="1" @selected($hint['suggest'])>Có thu hồi</option>
                                            <option value="0" @selected(! $hint['suggest'])>Không thu hồi</option>
                                        </select>
                                        <input type="number" name="clawback_amount" min="0" step="1000" value="{{ (int) $hint['amount'] }}" x-show="claw === '1'" aria-label="Số hoa hồng thu hồi (VNĐ)"
                                               class="w-32 rounded-lg border-outline-variant py-xs font-code text-code" />
                                    </div>
                                    <span class="block font-caption text-caption text-on-surface-variant">{{ $hint['start'] ? 'Bắt đầu học '.$hint['start']->format('d/m/Y') : 'Chưa bắt đầu học' }} · gợi ý: {{ $hint['suggest'] ? 'có' : 'không' }} thu hồi</span>
                                </div>
                            @endif
                            <label class="block">
                                <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Ảnh bằng chứng chi tiền <span class="text-error">*</span></span>
                                <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp" required
                                       class="block w-full font-body-small text-body-small file:mr-sm file:rounded-lg file:border-0 file:bg-surface-container-high file:px-sm file:py-xs" />
                                <span class="mt-xs block font-caption text-caption text-on-surface-variant">Ủy nhiệm chi / biên nhận đã ký (JPG, PNG, WEBP, tối đa 10MB).</span>
                            </label>
                        @elseif ($rq->type === 'transfer')
                            <p class="font-caption text-caption text-on-surface-variant">Chuyển nhượng phí không thu hồi hoa hồng.</p>
                        @endif
                    </form>
                    <x-slot:footer>
                        <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'approve-refund-{{ $rq->id }}')">Hủy</x-ui.button>
                        <x-ui.button type="submit" icon="check" form="approve-refund-form-{{ $rq->id }}">Duyệt</x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
            <x-ui.modal :name="'reject-refund-'.$rq->id" :title="'Từ chối hồ sơ — '.$rq->student?->name" max-width="md">
                <form id="reject-refund-form-{{ $rq->id }}" action="{{ route('tuition.refunds.reject', $rq->id) }}" method="POST">
                    @csrf
                    <label class="block">
                        <span class="mb-xs block font-label text-label uppercase text-on-surface-variant">Lý do từ chối</span>
                        <textarea name="rejection_reason" rows="3" placeholder="VD: đề nghị chuyển nhượng cho học viên khác thay vì hoàn tiền..." class="w-full rounded-lg border-outline-variant font-body-base text-body-base"></textarea>
                    </label>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" @click="$dispatch('close-modal', 'reject-refund-{{ $rq->id }}')">Hủy</x-ui.button>
                    <x-ui.button variant="danger" type="submit" icon="close" form="reject-refund-form-{{ $rq->id }}">Từ chối</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endforeach
    @endcan

    {{-- Tất cả yêu cầu --}}
    <div id="all-requests" class="mt-lg">
        <x-ui.data-table>
            <x-slot:header>
                <h3 class="flex items-center gap-sm font-h3 text-h3 text-on-surface">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">history_edu</span>
                    Tất cả yêu cầu
                    <span class="font-caption text-caption text-on-surface-variant">{{ $historyRequests->count() }} / {{ $refundRequests->count() }} hồ sơ</span>
                </h3>
                <form method="GET" action="{{ route('tuition.refunds') }}#all-requests" class="flex flex-wrap items-center gap-sm">
                    <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Tìm học viên / mã HV..." class="w-48 rounded-lg border-outline-variant py-xs font-body-small text-body-small" />
                    <select name="type" class="rounded-lg border-outline-variant py-xs font-body-small text-body-small" aria-label="Loại yêu cầu">
                        <option value="">Tất cả loại</option>
                        @foreach (\App\Models\TuitionRefundRequest::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-lg border-outline-variant py-xs font-body-small text-body-small" aria-label="Trạng thái">
                        <option value="">Tất cả trạng thái</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>Chờ duyệt</option>
                        <option value="overdue" @selected($filters['status'] === 'overdue')>Quá hạn xử lý</option>
                        <option value="approved" @selected($filters['status'] === 'approved')>Đã duyệt</option>
                        <option value="rejected" @selected($filters['status'] === 'rejected')>Đã từ chối</option>
                    </select>
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="filter_list">Lọc</x-ui.button>
                </form>
            </x-slot:header>
            <table>
                <thead>
                    <tr>
                        <th>Ngày lập</th>
                        <th>Học viên nguồn</th>
                        <th>Loại yêu cầu</th>
                        <th>Học viên thụ hưởng</th>
                        <th class="text-right">Số tiền</th>
                        <th>Lý do &amp; căn cứ</th>
                        <th>Hạn xử lý</th>
                        <th>Người duyệt</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($historyRequests as $rq)
                        <tr>
                            <td class="whitespace-nowrap font-code text-code text-on-surface-variant">{{ $rq->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <div class="font-body-medium text-body-medium">{{ $rq->student?->name }}</div>
                                <div class="font-code text-caption text-on-surface-variant">{{ $rq->student?->code }} ({{ $rq->student?->currentClass?->name ?? '—' }})</div>
                            </td>
                            <td>
                                <x-ui.badge :color="$typeBadge[$rq->type] ?? 'neutral'">{{ $rq->type_label }}</x-ui.badge>
                                @if ($rq->type === 'deferral')
                                    <div class="mt-xs font-code text-caption text-on-surface-variant">{{ $rq->defer_from?->format('d/m/Y') }} – {{ $rq->defer_to?->format('d/m/Y') }}</div>
                                @elseif ($rq->type === 'extension' && $rq->extended_due_date)
                                    <div class="mt-xs font-code text-caption text-on-surface-variant">Hạn mới {{ $rq->extended_due_date->format('d/m/Y') }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($rq->targetStudent)
                                    <div class="font-body-medium text-body-medium">{{ $rq->targetStudent->name }}</div>
                                    <div class="font-code text-caption text-on-surface-variant">{{ $rq->targetStudent->code }}</div>
                                @else
                                    <span class="text-on-surface-variant">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right font-code text-code">{{ in_array($rq->type, ['refund', 'transfer'], true) ? $money($rq->refund_amount) : '—' }}</td>
                            <td class="max-w-[260px]">
                                <div class="truncate" title="{{ $rq->reason }}">{{ $rq->reason }}</div>
                                @if ($rq->no_transfer_reason)
                                    <div class="truncate font-caption text-caption text-on-surface-variant" title="{{ $rq->no_transfer_reason }}">Không chuyển nhượng: {{ $rq->no_transfer_reason }}</div>
                                @endif
                                @if ($rq->rejection_reason)
                                    <div class="truncate font-caption text-caption text-error" title="{{ $rq->rejection_reason }}">Từ chối: {{ $rq->rejection_reason }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($rq->processing_deadline)
                                    <span class="font-code text-code">{{ $rq->processing_deadline->format('d/m/Y') }}</span>
                                    @if ($rq->isProcessingOverdue())
                                        <x-ui.badge color="error" class="mt-xs">{{ $rq->status === 'pending' ? 'Quá hạn xử lý' : 'Xử lý trễ hạn' }}</x-ui.badge>
                                    @endif
                                @else
                                    <span class="text-on-surface-variant">—</span>
                                @endif
                            </td>
                            <td class="font-body-small text-body-small">
                                {{ $rq->approver?->name ?? '—' }}
                                @if ($rq->status === 'approved' && $rq->type === 'refund' && $rq->clawback_commission !== null)
                                    <div class="font-caption text-caption {{ $rq->clawback_commission ? 'text-error' : 'text-on-surface-variant' }}">
                                        {{ $rq->clawback_commission ? 'Thu hồi HH '.$money($rq->clawback_amount).($rq->clawbackUser ? ' ('.$rq->clawbackUser->name.')' : '') : 'Không thu hồi HH' }}
                                    </div>
                                @endif
                                @if ($rq->proof_path)
                                    <a href="{{ route('tuition.refunds.proof', $rq->id) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-xs font-caption text-caption text-primary hover:underline">
                                        <span class="material-symbols-outlined text-[14px]" aria-hidden="true">image</span> Ảnh bằng chứng
                                    </a>
                                @endif
                            </td>
                            <td>
                                @if ($rq->status === 'approved')
                                    <x-ui.badge color="success">Đã duyệt</x-ui.badge>
                                @elseif ($rq->status === 'rejected')
                                    <x-ui.badge color="error">Đã từ chối</x-ui.badge>
                                @else
                                    <x-ui.badge color="warning">{{ $rq->type === 'refund' ? 'Chờ Admin duyệt' : 'Chờ duyệt' }}</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-ui.empty-state icon="inbox" title="Chưa có hồ sơ phù hợp" description="Chưa có hồ sơ khất nợ / bảo lưu / chuyển nhượng / hoàn tiền nào khớp bộ lọc." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.data-table>
    </div>

    <script>
        function refundTransferManager(finance, initialStudentId, feePercent, targets, initialType, initialTarget) {
            const empty = { has_tuition: false, paid: 0, contract: 0, debt: 0, total_sessions: null, attended_sessions: 0, remaining_sessions: null, unit_price: 0 };
            const fold = (v) => (v || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
            return {
                finance: finance || {},
                targets: targets || [],
                actionType: initialType || 'transfer',
                selectedStudentId: initialStudentId,
                targetId: initialTarget || '',
                targetQuery: '',
                feePercent: feePercent,
                attendedLessons: 0,
                refundAmount: 0,

                init() {
                    this.resetFromBasis();
                    this.$watch('actionType', () => { this.refundAmount = this.suggestedAmount; });
                },

                get basis() {
                    return this.finance[this.selectedStudentId] || empty;
                },

                get currentStudent() {
                    return this.targets.find(t => t.id === String(this.selectedStudentId)) || null;
                },

                get studentName() {
                    return this.currentStudent ? this.currentStudent.name : '—';
                },

                get studentCode() {
                    return this.currentStudent ? this.currentStudent.code : '—';
                },

                // Học viên nhận: khác học viên nguồn, lọc theo tên / mã (không dấu).
                get targetMatches() {
                    const q = fold(this.targetQuery);
                    return this.targets
                        .filter(t => t.id !== String(this.selectedStudentId))
                        .filter(t => !q || t.search.includes(q))
                        .slice(0, 30);
                },

                // Làm lại số liệu theo hợp đồng thật của học viên được chọn.
                resetFromBasis() {
                    this.attendedLessons = this.basis.attended_sessions || 0;
                    if (this.targetId === String(this.selectedStudentId)) {
                        this.targetId = '';
                    }
                    this.$nextTick(() => { this.refundAmount = this.suggestedAmount; });
                },

                get totalLessons() {
                    return this.basis.total_sessions || 0;
                },

                get remainingLessons() {
                    return Math.max(0, this.totalLessons - (this.attendedLessons || 0));
                },

                get unitPrice() {
                    return this.totalLessons > 0 ? (this.basis.contract / this.totalLessons) : 0;
                },

                // Giá trị còn lại = đã nộp − giá trị các buổi đã học (không âm).
                get remainingValue() {
                    const used = Math.min(this.basis.paid, this.unitPrice * (this.attendedLessons || 0));
                    return Math.max(0, Math.round(this.basis.paid - used));
                },

                get adminFee() {
                    return Math.round(this.remainingValue * this.feePercent / 100);
                },

                get suggestedAmount() {
                    if (this.actionType === 'transfer') {
                        return Math.min(this.remainingValue, Math.round(this.unitPrice * this.remainingLessons));
                    }
                    if (this.actionType === 'refund') {
                        return Math.max(0, this.remainingValue - this.adminFee);
                    }
                    return 0;
                },

                money(v) {
                    return new Intl.NumberFormat('vi-VN').format(Math.round(v || 0)) + 'đ';
                },
            };
        }
    </script>
</x-app-layout>
