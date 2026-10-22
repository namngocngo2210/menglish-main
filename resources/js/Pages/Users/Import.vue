<script setup>
/**
 * Nhập nhân sự từ Excel — 3 bước: Tải file → Xem trước → Kết quả (mã nhân viên dùng để đăng nhập vừa cấp).
 * Nhận file mẫu hoặc sheet gốc "Giáo viên Part" / form "Trợ giảng" (chọn vai trò, cơ sở mặc định cho dòng không ghi).
 */
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { route } from "@/lib/route";

defineOptions({ layout: { title: "Nhập nhân sự" } });

const props = defineProps({
    branches: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
    preview: { type: Object, default: null },
    result: { type: Object, default: null },
    headings: { type: String, default: "" },
    defaultPassword: { type: String, default: "" },
    errors: { type: Object, default: () => ({}) },
});

const step = computed(() => (props.result ? 3 : props.preview ? 2 : 1));
const steps = [
    [1, "Tải file"],
    [2, "Xem trước"],
    [3, "Kết quả"],
];
const actionLabels = {
    create: ["Tạo mới", "info"],
    update: ["Bổ sung hồ sơ", "neutral"],
};

const rows = computed(() => props.preview?.rows ?? []);
const toCreate = computed(
    () =>
        rows.value.filter((r) => !r.errors.length && r.action === "create")
            .length,
);
const toUpdate = computed(
    () =>
        rows.value.filter((r) => !r.errors.length && r.action === "update")
            .length,
);
const errorCount = computed(
    () => rows.value.filter((r) => r.errors.length).length,
);
const filter = ref("all");
const shownRows = computed(() => {
    if (filter.value === "errors")
        return rows.value.filter((r) => r.errors.length);
    if (filter.value === "warnings")
        return rows.value.filter((r) => !r.errors.length && r.warnings.length);
    return rows.value;
});

const fileName = ref("");
const processing = ref(false);
function submit(event) {
    router.post(event.target.action, new FormData(event.target), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <div>
        <UiPageHeader
            title="Nhập nhân sự"
            icon="upload_file"
            description="Tạo tài khoản giáo viên, trợ giảng… kèm hồ sơ từ file Excel. Nhân sự đã có (trùng email) chỉ được bổ sung trường đang trống."
        >
            <template #actions>
                <UiButton
                    variant="secondary"
                    icon="download"
                    :href="route('users.import.template')"
                    native
                    >Tải file mẫu (.xlsx)</UiButton
                >
            </template>
        </UiPageHeader>

        <div class="space-y-lg">
            <ol
                class="flex items-center justify-center gap-md rounded-xl bg-surface-container-low p-md"
            >
                <li
                    v-for="[n, label] in steps"
                    :key="n"
                    class="flex items-center gap-sm"
                >
                    <span
                        :class="[
                            'flex h-8 w-8 items-center justify-center rounded-full border-2 font-body-medium',
                            n === step
                                ? 'border-primary-container text-primary'
                                : n < step
                                  ? 'border-tertiary bg-tertiary text-white'
                                  : 'border-outline-variant text-on-surface-variant',
                        ]"
                        >{{ n < step ? "✓" : n }}</span
                    >
                    <span
                        :class="[
                            'font-body-medium',
                            n === step
                                ? 'text-primary'
                                : 'text-on-surface-variant',
                        ]"
                        >{{ label }}</span
                    >
                    <span
                        v-if="n < 3"
                        class="hidden h-px w-16 bg-outline-variant sm:block"
                        aria-hidden="true"
                    ></span>
                </li>
            </ol>

            <!-- Bước 1: tải file -->
            <form
                v-if="step === 1"
                id="staff-import-form"
                method="POST"
                :action="route('users.import.store')"
                enctype="multipart/form-data"
                class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg"
                @submit.prevent="submit"
            >
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiSelect
                        name="role"
                        label="Vai trò mặc định"
                        :options="roles"
                        placeholder="-- Lấy theo cột Vai trò --"
                        :error="errors.role"
                        hint="Dùng cho dòng không ghi cột Vai trò."
                    />
                    <UiSelect
                        name="branch_id"
                        label="Cơ sở mặc định"
                        :options="branches"
                        placeholder="-- Lấy theo cột Cơ sở --"
                        :error="errors.branch_id"
                        hint="Dùng cho dòng không ghi cột Cơ sở."
                    />
                    <UiInput
                        name="password"
                        type="text"
                        label="Mật khẩu ban đầu"
                        required
                        :value="defaultPassword"
                        placeholder="Tối thiểu 8 ký tự"
                        autocomplete="off"
                        :error="errors.password"
                        hint="Dùng chung cho tài khoản mới; bắt buộc đổi ở lần đăng nhập đầu."
                    />
                </div>

                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-sm rounded-xl border-2 border-dashed border-outline-variant bg-surface-container-low p-xl text-center hover:border-primary-container"
                >
                    <span
                        class="flex h-16 w-16 items-center justify-center rounded-full bg-primary-fixed text-primary"
                    >
                        <span
                            class="material-symbols-outlined text-[32px]"
                            aria-hidden="true"
                            >upload_file</span
                        >
                    </span>
                    <span class="font-h3 text-h3 text-on-surface"
                        >Nhấn để chọn file nhân sự</span
                    >
                    <span
                        class="font-body-small text-body-small text-on-surface-variant"
                        >.xlsx / .xls (tối đa 10MB)</span
                    >
                    <input
                        type="file"
                        name="excel_file"
                        accept=".xlsx,.xls,.csv"
                        class="sr-only"
                        @change="fileName = $event.target.files[0]?.name || ''"
                    />
                    <span
                        v-if="fileName"
                        class="rounded-lg bg-tertiary-fixed/40 px-sm py-xs font-body-small text-body-small text-on-tertiary-fixed-variant"
                        >{{ "Đã chọn: " + fileName }}</span
                    >
                </label>

                <UiAlert type="info" title="Cột trong file">
                    <ul class="list-disc space-y-0.5 pl-md">
                        <li>{{ headings }}.</li>
                        <li>
                            Bắt buộc: Họ tên, Email; Vai trò và Cơ sở lấy theo
                            cột hoặc giá trị mặc định ở trên (giáo viên, trợ
                            giảng không bắt buộc Cơ sở). Ngày ghi dạng
                            dd/mm/yyyy.
                        </li>
                        <li>
                            Email công việc là email tài khoản; chỉ có email cá
                            nhân thì dùng email đó. Mã nhân viên (dùng để đăng
                            nhập) hệ thống tự cấp.
                        </li>
                        <li>
                            Nhận cả sheet gốc "Giáo viên Part" và sheet form
                            "Trợ giảng" (cột "Mail doanh nghiệp", "Khi cần liên
                            hệ với ai?"…).
                        </li>
                    </ul>
                </UiAlert>

                <p
                    v-if="errors.excel_file"
                    class="font-body-small text-body-small text-error"
                    role="alert"
                >
                    {{ errors.excel_file }}
                </p>

                <div class="flex justify-end gap-sm">
                    <UiButton variant="secondary" :href="route('users.index')"
                        >Hủy</UiButton
                    >
                    <UiButton
                        type="submit"
                        icon="preview"
                        :disabled="processing"
                        >Kiểm tra &amp; xem trước</UiButton
                    >
                </div>
            </form>

            <!-- Bước 2: xem trước -->
            <template v-else-if="step === 2">
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiStatCard
                        label="Tài khoản tạo mới"
                        :value="toCreate"
                        tone="success"
                        icon="person_add"
                    />
                    <UiStatCard
                        label="Bổ sung hồ sơ"
                        :value="toUpdate"
                        tone="primary"
                        icon="edit_note"
                    />
                    <UiStatCard
                        label="Dòng lỗi (bỏ qua)"
                        :value="errorCount"
                        tone="error"
                        icon="person_off"
                    />
                </div>
                <p
                    class="font-body-small text-body-small text-on-surface-variant"
                >
                    File {{ preview.file_name }}
                </p>

                <UiDataTable min-width="980px">
                    <template #header>
                        <div
                            class="flex flex-wrap items-center justify-between gap-sm"
                        >
                            <h2 class="font-h3 text-h3">
                                Nhân sự ({{ rows.length }})
                            </h2>
                            <div class="flex gap-xs">
                                <UiButton
                                    size="sm"
                                    :variant="
                                        filter === 'all'
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    @click="filter = 'all'"
                                    >Tất cả</UiButton
                                >
                                <UiButton
                                    size="sm"
                                    :variant="
                                        filter === 'errors'
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    @click="filter = 'errors'"
                                    >Lỗi</UiButton
                                >
                                <UiButton
                                    size="sm"
                                    :variant="
                                        filter === 'warnings'
                                            ? 'primary'
                                            : 'secondary'
                                    "
                                    @click="filter = 'warnings'"
                                    >Cảnh báo</UiButton
                                >
                            </div>
                        </div>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Dòng</th>
                                <th>Nhân sự</th>
                                <th>Vai trò · Cơ sở</th>
                                <th>SĐT</th>
                                <th>Ngày sinh</th>
                                <th>Vào làm</th>
                                <th>Kiểm tra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in shownRows"
                                :key="r.line"
                                :class="{
                                    '!bg-error-container': r.errors.length,
                                }"
                            >
                                <td class="font-code">{{ r.line }}</td>
                                <td>
                                    <div class="font-body-medium">
                                        {{ r.name }}
                                    </div>
                                    <div
                                        class="font-caption text-caption text-on-surface-variant"
                                    >
                                        {{ r.email ?? "—" }}
                                    </div>
                                    <UiBadge
                                        v-if="!r.errors.length"
                                        :color="actionLabels[r.action][1]"
                                        >{{ actionLabels[r.action][0]
                                        }}{{
                                            r.employee_code
                                                ? " · " + r.employee_code
                                                : ""
                                        }}</UiBadge
                                    >
                                </td>
                                <td>
                                    <div>{{ r.role ?? "—" }}</div>
                                    <div
                                        class="font-caption text-caption text-on-surface-variant"
                                    >
                                        {{ r.branch ?? "—" }}
                                    </div>
                                </td>
                                <td class="font-code">{{ r.phone ?? "—" }}</td>
                                <td class="font-code">
                                    {{ r.date_of_birth ?? "—" }}
                                </td>
                                <td class="font-code">
                                    {{ r.joined_on ?? "—" }}
                                </td>
                                <td>
                                    <UiBadge
                                        v-if="
                                            !r.errors.length &&
                                            !r.warnings.length
                                        "
                                        color="success"
                                        >Hợp lệ</UiBadge
                                    >
                                    <ul
                                        class="list-disc space-y-0.5 pl-md font-caption text-caption"
                                    >
                                        <li
                                            v-for="(e, i) in r.errors"
                                            :key="'e' + i"
                                            class="text-error"
                                        >
                                            {{ e }}
                                        </li>
                                        <li
                                            v-for="(w, i) in r.warnings"
                                            :key="'w' + i"
                                            class="text-warning"
                                        >
                                            {{ w }}
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>

                <form
                    id="staff-import-confirm"
                    method="POST"
                    :action="route('users.import.confirm')"
                    class="flex flex-wrap items-center justify-between gap-sm"
                    @submit.prevent="submit"
                >
                    <input type="hidden" name="token" :value="preview.token" />
                    <p
                        class="font-body-small text-body-small text-on-surface-variant"
                    >
                        Dòng lỗi bị bỏ qua — sửa file rồi tải lên lại, nhân sự
                        đã nhập sẽ không bị tạo trùng.
                    </p>
                    <div class="flex gap-sm">
                        <UiButton
                            variant="secondary"
                            :href="route('users.import')"
                            >Chọn file khác</UiButton
                        >
                        <UiButton
                            type="submit"
                            icon="cloud_done"
                            :disabled="
                                (toCreate === 0 && toUpdate === 0) || processing
                            "
                            >Tạo {{ toCreate }} tài khoản<template
                                v-if="toUpdate"
                                >, bổ sung {{ toUpdate }} hồ sơ</template
                            ></UiButton
                        >
                    </div>
                </form>
            </template>

            <!-- Bước 3: kết quả -->
            <div
                v-else
                class="space-y-md rounded-xl border border-outline-variant bg-surface-container-lowest p-lg"
            >
                <h2 class="font-h3 text-h3">
                    Kết quả nhập file {{ result.file_name }}
                </h2>
                <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
                    <UiStatCard
                        label="Tài khoản tạo mới"
                        :value="result.created"
                        tone="success"
                    />
                    <UiStatCard
                        label="Bổ sung hồ sơ"
                        :value="result.updated"
                        tone="primary"
                    />
                    <UiStatCard
                        label="Dòng bỏ qua"
                        :value="result.skipped"
                        tone="warning"
                    />
                </div>
                <UiAlert
                    v-if="result.failed.length"
                    type="error"
                    title="Không ghi được"
                >
                    <ul class="list-disc pl-md">
                        <li v-for="(failed, i) in result.failed" :key="i">
                            Dòng {{ failed.line }} ({{ failed.name }}):
                            {{ failed.error }}
                        </li>
                    </ul>
                </UiAlert>
                <UiDataTable v-if="result.accounts.length" min-width="720px">
                    <template #header>
                        <h2 class="font-h3 text-h3">Mã đăng nhập vừa cấp</h2>
                        <p
                            class="font-caption text-caption text-on-surface-variant"
                        >
                            Gửi mã nhân viên + mật khẩu ban đầu cho từng người;
                            hệ thống bắt đổi mật khẩu ở lần đăng nhập đầu.
                        </p>
                    </template>
                    <table>
                        <thead>
                            <tr>
                                <th>Mã nhân viên</th>
                                <th>Họ tên</th>
                                <th>Email</th>
                                <th>Vai trò</th>
                                <th>Cơ sở</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="a in result.accounts"
                                :key="a.employee_code"
                            >
                                <td class="font-code font-semibold">
                                    {{ a.employee_code }}
                                </td>
                                <td>{{ a.name }}</td>
                                <td>{{ a.email }}</td>
                                <td>{{ a.role }}</td>
                                <td>{{ a.branch }}</td>
                            </tr>
                        </tbody>
                    </table>
                </UiDataTable>
                <div class="flex flex-wrap justify-end gap-sm">
                    <UiButton
                        variant="secondary"
                        icon="upload_file"
                        :href="route('users.import')"
                        >Nhập file khác</UiButton
                    >
                    <UiButton icon="group" :href="route('users.index')"
                        >Danh sách nhân sự</UiButton
                    >
                </div>
            </div>
        </div>
    </div>
</template>
