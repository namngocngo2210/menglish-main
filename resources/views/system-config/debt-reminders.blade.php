{{-- Cấu hình nhắc nợ (mockup: epic-5/cau-hinh-nhac-no). --}}
<x-app-layout title="Cấu hình nhắc nợ">
    <div class="space-y-lg">
        {{-- Tab cấu hình hệ thống --}}
        <nav class="flex items-center gap-sm overflow-x-auto border-b border-surface-container-highest pb-sm" aria-label="Cấu hình hệ thống">
            <x-ui.button variant="secondary" size="sm" icon="account_balance_wallet" :href="route('system-config.bank-accounts')">Tài khoản Ngân hàng</x-ui.button>
            <x-ui.button size="sm" icon="notifications_active" :href="route('system-config.debt-reminders')">Cấu hình nhắc nợ</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="mail" :href="route('system-config.ticket-emails')">Email nhận Ticket</x-ui.button>
            <x-ui.button variant="secondary" size="sm" icon="dns" :href="route('system-config.hosting')">Hosting &amp; Máy chủ</x-ui.button>
        </nav>

        <x-ui.page-header title="Cấu hình Nhắc nợ"
                          description="Tối ưu hóa thời gian và tần suất gửi thông báo nhắc học phí cho phụ huynh, giúp cải thiện tỷ lệ thanh toán đúng hạn và duy trì sự chuyên nghiệp trong khâu vận hành." />

        {{-- Thiết lập nhanh theo mockup: 3 mốc + kênh thông báo --}}
        <form method="POST" action="{{ route('system-config.debt-reminders.settings') }}" class="rounded-xl border border-outline-variant bg-surface-container-lowest p-lg shadow-sm"
              x-data="{ repeat: @js((int) old('repeat_days', $repeatDays)) }">
            @csrf
            <div class="grid grid-cols-1 gap-lg md:grid-cols-3">
                <div class="space-y-xs">
                    <label for="first_days" class="block font-body-medium text-body-medium text-on-surface">Mốc nhắc nợ trước hạn</label>
                    <p class="font-caption text-caption text-on-surface-variant">Số ngày trước ngày đáo hạn để hệ thống gửi thông báo nhắc nhở đầu tiên.</p>
                    <div class="flex items-center gap-sm">
                        <input id="first_days" type="number" name="first_days" min="1" max="60" value="{{ old('first_days', $firstDays) }}" required class="w-24 rounded-lg border-outline-variant font-code text-code" />
                        <span class="font-body-small text-body-small text-on-surface-variant">Ngày</span>
                    </div>
                    @error('first_days')<p class="font-caption text-caption text-error">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-xs">
                    <label for="repeat_days" class="block font-body-medium text-body-medium text-on-surface">Mốc nhắc lại</label>
                    <p class="font-caption text-caption text-on-surface-variant">Gửi thông báo lần 2 sát ngày đáo hạn để tăng độ nhận diện.</p>
                    <div class="flex items-center gap-sm">
                        <input id="repeat_days" type="number" name="repeat_days" min="1" max="3" x-model.number="repeat" value="{{ old('repeat_days', $repeatDays) }}" required
                               class="w-24 rounded-lg font-code text-code" :class="repeat < 1 || repeat > 3 ? 'border-error ring-2 ring-error/20' : 'border-outline-variant'" />
                        <span class="font-body-small text-body-small text-on-surface-variant">Ngày</span>
                    </div>
                    <p x-show="repeat < 1 || repeat > 3" class="flex items-center gap-xs font-caption text-caption text-error"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">error</span>Giá trị không hợp lệ. Vui lòng nhập trong khoảng từ 1-3 ngày.</p>
                    @error('repeat_days')<p class="font-caption text-caption text-error">{{ $message }}</p>@enderror
                    <p class="flex items-center gap-xs font-caption text-caption text-on-surface-variant"><span class="material-symbols-outlined text-[14px]" aria-hidden="true">info</span>Mốc nhắc lại bắt buộc phải nằm trong khoảng từ 1 đến 3 ngày trước hạn.</p>
                </div>
                <div class="space-y-xs">
                    <label for="must_contact_days" class="block font-body-medium text-body-medium text-on-surface">Mốc quá hạn bắt buộc liên hệ</label>
                    <p class="font-caption text-caption text-on-surface-variant">Tạo yêu cầu liên hệ trực tiếp (gọi điện) nếu quá hạn thanh toán.</p>
                    <div class="flex items-center gap-sm">
                        <input id="must_contact_days" type="number" name="must_contact_days" min="1" max="60" value="{{ old('must_contact_days', $mustContactDays) }}" required class="w-24 rounded-lg border-outline-variant font-code text-code" />
                        <span class="font-body-small text-body-small text-on-surface-variant">Ngày</span>
                    </div>
                    @error('must_contact_days')<p class="font-caption text-caption text-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="mt-lg flex flex-col gap-md border-t border-surface-container pt-md md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-sm">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">notifications</span>
                    <div>
                        <p class="font-body-medium text-body-medium">Kênh thông báo: Chuông thông báo in-app</p>
                        <p class="font-caption text-caption text-on-surface-variant">Thông báo đẩy trực tiếp tới Cổng phụ huynh / học sinh MENGLISH (kênh từng mốc chỉnh ở danh sách mốc bên dưới).</p>
                    </div>
                    <x-ui.badge color="success" pill>Hoạt động</x-ui.badge>
                </div>
                <div class="flex gap-sm">
                    <x-ui.button variant="secondary" :href="route('system-config.debt-reminders')">Hủy</x-ui.button>
                    <x-ui.button type="submit" icon="save">Lưu cấu hình</x-ui.button>
                </div>
            </div>
        </form>

        <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
            <div class="space-y-lg xl:col-span-2">
                <h2 class="font-h3 text-h3 text-on-surface">Các mốc nhắc chi tiết</h2>
                {{-- Các mốc nhắc --}}
                @forelse ($rules as $rule)
                    @php
                        $offset = $rule->effectiveOffset();
                        $timing = $offset === null ? '' : ($offset < 0 ? 'before' : ($offset === 0 ? 'due' : 'after'));
                        $isOld = old('milestone_key') === $rule->milestone_key;
                        $control = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-base text-body-base text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';
                    @endphp
                    <form method="POST" action="{{ route('system-config.debt-reminders.store') }}"
                          class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-md"
                          x-data="{ timing: @js($isOld ? old('timing', $timing) : $timing) }">
                        @csrf
                        <input type="hidden" name="milestone_key" value="{{ $rule->milestone_key }}">
                        <input type="hidden" name="channels_submitted" value="1">

                        <div class="flex flex-wrap items-center justify-between gap-sm border-b border-surface-container pb-sm">
                            <div class="flex items-center gap-sm">
                                <span class="rounded-lg bg-primary-fixed px-sm py-xs font-code text-code text-primary">{{ $rule->milestone_key }}</span>
                                <span class="font-body-medium text-body-medium text-on-surface">{{ $rule->offset_label }}</span>
                            </div>
                            <label class="flex items-center gap-xs font-body-small text-body-small">
                                <input type="hidden" name="is_enabled" value="0">
                                <input type="checkbox" name="is_enabled" value="1" @checked($rule->is_enabled) class="rounded text-primary focus:ring-primary-container">
                                Đang bật
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                            <x-ui.field label="Tên mốc" :name="$isOld ? 'title' : null" required>
                                <input type="text" name="title" value="{{ $isOld ? old('title') : $rule->title }}" required class="{{ $control }}">
                            </x-ui.field>
                            <x-ui.field label="Thời điểm gửi" name="timing">
                                <select name="timing" x-model="timing" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base">
                                    <option value="before">Trước hạn đóng</option>
                                    <option value="due">Đúng ngày đến hạn</option>
                                    <option value="after">Sau hạn (quá hạn)</option>
                                </select>
                            </x-ui.field>
                            <div x-show="timing !== 'due'">
                                <x-ui.field label="Số ngày" :name="$isOld ? 'days' : null">
                                    <input type="number" name="days" min="1" max="60" value="{{ $isOld ? old('days') : ($offset !== null && $offset !== 0 ? abs($offset) : '') }}" class="{{ $control }}">
                                </x-ui.field>
                            </div>
                        </div>

                        <x-ui.field label="Kênh thông báo" name="channels">
                            <div class="flex flex-wrap gap-md">
                                @foreach (\App\Models\DebtReminderRule::CHANNELS as $channel => $label)
                                    <label class="flex items-center gap-xs font-body-small text-body-small">
                                        <input type="checkbox" name="channels[]" value="{{ $channel }}" @checked(in_array($channel, $rule->activeChannels(), true)) class="rounded text-primary focus:ring-primary-container">
                                        {{ $label }}
                                    </label>
                                @endforeach
                                <span class="flex items-center gap-xs font-body-small text-body-small text-on-surface-variant" title="Chưa tích hợp Zalo ZNS / SMS cho nhắc nợ">
                                    <input type="checkbox" disabled class="rounded"> Zalo ZNS / SMS (chưa tích hợp)
                                </span>
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Mẫu tin nhắn" :name="$isOld ? 'template_content' : null" required>
                            <textarea name="template_content" rows="3" required class="{{ $control }}">{{ $isOld ? old('template_content') : $rule->template_content }}</textarea>
                        </x-ui.field>

                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="save">Lưu mốc {{ $rule->milestone_key }}</x-ui.button>
                        </div>
                    </form>
                @empty
                    <x-ui.empty-state icon="notifications_off" title="Chưa cấu hình mốc nhắc nợ"
                                      description="Khi chưa có mốc nào, hệ thống dùng mặc định: trước hạn 3 ngày, đúng hạn, quá hạn 3 ngày." />
                @endforelse

                {{-- Thêm mốc mới --}}
                <form method="POST" action="{{ route('system-config.debt-reminders.store') }}"
                      class="space-y-md rounded-xl border border-dashed border-outline-variant bg-surface-container-lowest p-md"
                      x-data="{ timing: @js(old('milestone_key') ? 'before' : old('timing', 'before')) }">
                    @csrf
                    <input type="hidden" name="channels_submitted" value="1">
                    <h2 class="font-h3 text-h3 text-on-surface">Thêm mốc nhắc</h2>
                    <div class="grid grid-cols-1 gap-md md:grid-cols-3">
                        <x-ui.input name="title" label="Tên mốc" placeholder="Ví dụ: Nhắc trước hạn 7 ngày" required />
                        <x-ui.field label="Thời điểm gửi" name="timing">
                            <select name="timing" x-model="timing" class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest py-sm pl-md pr-xl font-body-base text-body-base">
                                <option value="before">Trước hạn đóng</option>
                                <option value="due">Đúng ngày đến hạn</option>
                                <option value="after">Sau hạn (quá hạn)</option>
                            </select>
                        </x-ui.field>
                        <div x-show="timing !== 'due'">
                            <x-ui.input name="days" type="number" min="1" max="60" label="Số ngày" placeholder="7" />
                        </div>
                    </div>
                    <x-ui.field label="Kênh thông báo" name="channels">
                        <div class="flex flex-wrap gap-md">
                            @foreach (\App\Models\DebtReminderRule::CHANNELS as $channel => $label)
                                <label class="flex items-center gap-xs font-body-small text-body-small">
                                    <input type="checkbox" name="channels[]" value="{{ $channel }}" checked class="rounded text-primary focus:ring-primary-container">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </x-ui.field>
                    <x-ui.textarea name="template_content" label="Mẫu tin nhắn" rows="3" required
                                   placeholder="Chào {ten_hoc_vien}, học phí lớp {lop_hoc} ({so_tien}) sẽ đến hạn ngày {han_dong}..." />
                    <div class="flex justify-end">
                        <x-ui.button type="submit" icon="add">Thêm mốc nhắc</x-ui.button>
                    </div>
                </form>
            </div>

            <div class="space-y-lg">
                <x-ui.alert type="info" title="Biến dùng trong mẫu tin">
                    <ul class="space-y-xs">
                        @foreach (\App\Models\DebtReminderRule::VARIABLES as $variable => $label)
                            <li><code class="font-code">{{ $variable }}</code> — {{ $label }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-xs">Viết thường hoặc IN HOA đều được (vd. <code class="font-code">{TEN_HOC_VIEN}</code>). Mẫu có biến khác sẽ không lưu được, để tin gửi đi không còn nguyên dấu ngoặc.</p>
                </x-ui.alert>

                <x-ui.alert type="success" title="Thông tin vận hành">
                    Các cấu hình mới có hiệu lực cho các đợt quét nhắc nợ từ lần chạy kế tiếp. Hệ thống tự động gửi thông báo vào lúc 08:30 sáng hằng ngày theo giờ Việt Nam (GMT+7),
                    đúng ngày chạm mốc, mỗi mốc một lần/ngày. Khoản đang khất nợ hoặc bảo lưu được tạm dừng nhắc tới hạn mới / ngày học lại.
                    Quá hạn từ "Mốc quá hạn bắt buộc liên hệ", học viên vào nhóm "Quá hạn nghiêm trọng" để gọi điện trực tiếp.
                </x-ui.alert>
            </div>
        </div>
    </div>
</x-app-layout>
