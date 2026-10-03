<script setup>
/**
 * Chấm công trên điện thoại: chụp ảnh khuôn mặt (camera trước) + lấy GPS, giờ chấm = giờ máy chủ.
 * Chỉ bấm "Xác nhận" được khi đã có ảnh và GPS nằm trong bán kính cơ sở (máy chủ kiểm tra lại lần cuối).
 * Không mở được camera trực tiếp → dự phòng bằng ô chụp ảnh của điện thoại (capture="user").
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import MobileLayout from '@/Layouts/MobileLayout.vue';
import { currentPosition, distanceMeters, openFrontCamera, shrinkImageFile, snapshot } from '@/lib/geo';
import { formatDate, formatNumber } from '@/lib/format';
import { route } from '@/lib/route';

defineOptions({ layout: MobileLayout });

const props = defineProps({
    me: { type: Object, required: true },
    branch: { type: Object, default: null },
    expected: { type: Object, required: true },
    today: { type: Object, default: null },
    serverTime: { type: String, required: true },
    locked: { type: String, default: null },
});

const page = usePage();

// Đồng hồ theo giờ máy chủ (lệch giữa máy chủ và điện thoại tính 1 lần khi mở trang).
const offset = ref(0);
const nowMs = ref(Date.parse(props.serverTime));
let timer = null;
onMounted(() => {
    offset.value = Date.parse(props.serverTime) - Date.now();
    nowMs.value = Date.now() + offset.value;
    timer = window.setInterval(() => (nowMs.value = Date.now() + offset.value), 1000);
});
const clock = computed(() => formatDate(new Date(nowMs.value), 'H:i:s'));
const dateLabel = computed(() => formatDate(new Date(nowMs.value), 'l, d/m/Y'));

const blocker = computed(() => {
    if (!props.branch) return 'Tài khoản của bạn chưa được gán cơ sở làm việc. Hãy báo Admin / Quản lý cơ sở.';
    if (!props.branch.has_location) return `Cơ sở ${props.branch.name} chưa cài toạ độ chấm công. Hãy báo Admin vào Cài đặt → Cơ sở & chi nhánh → Chấm công.`;
    return props.locked;
});
const nextKind = computed(() => (props.today?.check_in ? 'out' : 'in'));
const actionLabel = computed(() => (nextKind.value === 'in' ? 'Chấm công vào' : props.today?.check_out ? 'Chấm công ra lại' : 'Chấm công ra'));

// ── Hộp chấm công ──
const open = ref(false);
const kind = ref('in');
const geo = ref({ status: 'idle', pos: null, error: null });
const camera = ref({ status: 'idle', error: null });
const photo = ref(null);
const photoUrl = ref(null);
const submitting = ref(false);
const video = ref(null);
const fileInput = ref(null);
let stream = null;

const distance = computed(() => {
    const p = geo.value.pos;
    return p && props.branch?.has_location ? Math.round(distanceMeters(p.latitude, p.longitude, props.branch.latitude, props.branch.longitude)) : null;
});
const inRange = computed(() => distance.value !== null && distance.value <= props.branch.radius);
const serverErrors = computed(() => ['location', 'kind', 'photo', 'latitude', 'longitude', 'accuracy'].map((k) => page.props.errors?.[k]).filter(Boolean));
const canSubmit = computed(() => !!photo.value && inRange.value && !submitting.value);

async function locate() {
    geo.value = { status: 'loading', pos: geo.value.pos, error: null };
    try {
        geo.value = { status: 'ok', pos: await currentPosition(), error: null };
    } catch (error) {
        geo.value = { status: 'error', pos: null, error: error.message };
    }
}

async function startCamera() {
    stopCamera();
    camera.value = { status: 'loading', error: null };
    try {
        stream = await openFrontCamera();
        camera.value = { status: 'live', error: null };
        await nextTick();
        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play().catch(() => {});
        }
    } catch (error) {
        camera.value = { status: 'error', error: error.message };
    }
}

function stopCamera() {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

function setPhoto(file) {
    if (photoUrl.value) URL.revokeObjectURL(photoUrl.value);
    photo.value = file;
    photoUrl.value = file ? URL.createObjectURL(file) : null;
}

async function capture() {
    if (!video.value?.videoWidth) return;
    try {
        setPhoto(await snapshot(video.value));
        stopCamera();
        camera.value = { status: 'captured', error: null };
    } catch (error) {
        camera.value = { status: 'error', error: error.message };
    }
}

async function onFilePicked(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    try {
        setPhoto(await shrinkImageFile(file));
        camera.value = { status: 'captured', error: null };
    } catch (error) {
        camera.value = { status: 'error', error: error.message };
    }
}

function retake() {
    setPhoto(null);
    startCamera();
}

function start() {
    kind.value = nextKind.value;
    setPhoto(null);
    open.value = true;
    locate();
    startCamera();
}

function close() {
    open.value = false;
    stopCamera();
    setPhoto(null);
    camera.value = { status: 'idle', error: null };
}

function submit() {
    if (!canSubmit.value) return;
    submitting.value = true;
    const pos = geo.value.pos;
    router.post(
        route('mobile.punch'),
        { kind: kind.value, latitude: pos.latitude, longitude: pos.longitude, accuracy: pos.accuracy, photo: photo.value },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
            onFinish: () => (submitting.value = false),
        },
    );
}

onBeforeUnmount(() => {
    window.clearInterval(timer);
    stopCamera();
    if (photoUrl.value) URL.revokeObjectURL(photoUrl.value);
});

const radiusText = computed(() => (props.branch ? `${formatNumber(props.branch.radius)} m` : ''));
</script>

<template>
    <Head title="Chấm công" />

    <div class="space-y-md">
        <!-- Giờ máy chủ + cơ sở -->
        <section class="rounded-2xl bg-primary-container p-lg text-white shadow-sm" data-server-clock>
            <p class="font-body-small text-body-small text-white/85">{{ dateLabel }}</p>
            <p class="font-mono text-[44px] font-semibold leading-tight tracking-tight tabular-nums" aria-live="off">{{ clock }}</p>
            <p class="font-caption text-caption text-white/85">Giờ chấm công lấy theo máy chủ, không theo giờ điện thoại.</p>
            <div class="mt-md flex items-start gap-sm border-t border-white/20 pt-sm">
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">location_on</span>
                <div class="min-w-0">
                    <p class="font-body-semibold text-body-semibold">{{ branch?.name ?? 'Chưa gán cơ sở' }}</p>
                    <p v-if="branch?.address" class="truncate font-caption text-caption text-white/85">{{ branch.address }}</p>
                    <p class="font-caption text-caption text-white/85">
                        <template v-if="expected.start">Giờ vào {{ expected.start }}<template v-if="expected.end"> · giờ ra {{ expected.end }}</template> ({{ expected.basis.toLocaleLowerCase('vi') }})</template>
                        <template v-else>{{ expected.basis }} hôm nay, không tính đi muộn</template>
                    </p>
                </div>
            </div>
        </section>

        <UiAlert v-if="blocker" type="warning">{{ blocker }}</UiAlert>

        <!-- Hôm nay -->
        <section class="rounded-2xl border border-outline-variant bg-surface-container-lowest p-md shadow-sm" data-today>
            <div class="mb-sm flex items-center justify-between gap-sm">
                <h2 class="font-body-semibold text-body-semibold text-on-surface">Hôm nay của {{ me.name }}</h2>
                <UiBadge v-if="today" :color="today.status_tone">{{ today.status_label }}</UiBadge>
                <UiBadge v-else color="neutral">Chưa chấm công</UiBadge>
            </div>
            <div class="grid grid-cols-2 gap-sm">
                <div v-for="slot in [{ k: 'in', label: 'Giờ vào', time: today?.check_in, img: today?.check_in_photo }, { k: 'out', label: 'Giờ ra', time: today?.check_out, img: today?.check_out_photo }]" :key="slot.k" class="flex items-center gap-sm rounded-xl bg-surface-container-low p-sm">
                    <img v-if="slot.img" :src="slot.img" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" loading="lazy" />
                    <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-surface-container-high text-on-surface-subtle"><span class="material-symbols-outlined" aria-hidden="true">{{ slot.k === 'in' ? 'login' : 'logout' }}</span></span>
                    <div>
                        <p class="font-caption text-caption text-on-surface-variant">{{ slot.label }}</p>
                        <p class="font-mono text-h3 font-semibold text-on-surface">{{ slot.time ?? '--:--' }}</p>
                    </div>
                </div>
            </div>
            <p v-if="today?.late_minutes && !today.late_excused" class="mt-sm font-body-small text-body-small text-error">
                Đi muộn {{ today.late_minutes }} phút: hệ thống đã lập biên bản chờ giải trình. Có lý do thì gửi đơn "Xin đi muộn / về sớm".
            </p>
        </section>

        <UiButton class="h-14 w-full !rounded-2xl text-body-semibold" :icon="nextKind === 'in' ? 'login' : 'logout'" :disabled="!!blocker" data-punch-button @click="start">{{ actionLabel }}</UiButton>
        <p class="text-center font-caption text-caption text-on-surface-variant">
            Cần bật GPS, cho phép camera và đứng trong bán kính {{ radiusText }} quanh cơ sở.
        </p>

        <div class="grid grid-cols-2 gap-sm">
            <Link :href="route('mobile.requests', { type: 'correction' })" class="flex min-h-11 items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-small text-body-small text-on-surface hover:bg-surface-container-low">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">edit_calendar</span>Quên chấm công?
            </Link>
            <Link :href="route('mobile.requests', { type: 'leave' })" class="flex min-h-11 items-center gap-sm rounded-xl border border-outline-variant bg-surface-container-lowest px-md py-sm font-body-small text-body-small text-on-surface hover:bg-surface-container-low">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">beach_access</span>Xin nghỉ
            </Link>
        </div>
    </div>

    <!-- Hộp chấm công: GPS + ảnh khuôn mặt -->
    <UiModal :show="open" :title="kind === 'in' ? 'Chấm công vào' : 'Chấm công ra'" max-width="2xl" @close="close">
        <div class="space-y-md">
            <!-- Vị trí -->
            <div class="flex items-start gap-sm rounded-xl p-sm" :class="geo.status === 'ok' ? (inRange ? 'bg-tertiary/10' : 'bg-error/10') : 'bg-surface-container-low'" data-geo-status>
                <span class="material-symbols-outlined mt-0.5" :class="geo.status === 'ok' ? (inRange ? 'text-tertiary' : 'text-error') : 'text-on-surface-variant'" aria-hidden="true">
                    {{ geo.status === 'loading' ? 'progress_activity' : geo.status === 'ok' ? (inRange ? 'where_to_vote' : 'wrong_location') : 'location_disabled' }}
                </span>
                <div class="min-w-0 flex-1 font-body-small text-body-small" aria-live="polite">
                    <p v-if="geo.status === 'loading'">Đang lấy vị trí GPS…</p>
                    <template v-else-if="geo.status === 'ok'">
                        <p class="font-body-semibold text-body-semibold" :class="inRange ? 'text-tertiary' : 'text-error'">
                            {{ inRange ? 'Trong bán kính cơ sở' : 'Ngoài bán kính cơ sở' }}: cách {{ branch.name }} {{ formatNumber(distance) }} m
                        </p>
                        <p class="text-on-surface-variant">Cho phép {{ radiusText }} · sai số GPS khoảng {{ formatNumber(geo.pos.accuracy) }} m</p>
                    </template>
                    <p v-else-if="geo.status === 'error'" class="text-error">{{ geo.error }}</p>
                </div>
                <UiButton v-if="geo.status !== 'loading'" variant="ghost" size="sm" icon="my_location" aria-label="Lấy lại vị trí" @click="locate" />
            </div>

            <!-- Ảnh khuôn mặt -->
            <div class="relative mx-auto aspect-[3/4] w-full max-w-[13rem] overflow-hidden rounded-2xl bg-inverse-surface">
                <video v-show="camera.status === 'live'" ref="video" class="h-full w-full -scale-x-100 object-cover" autoplay playsinline muted></video>
                <img v-if="photoUrl" :src="photoUrl" alt="Ảnh khuôn mặt vừa chụp" class="h-full w-full object-cover" />
                <div v-if="camera.status === 'live'" class="pointer-events-none absolute inset-[12%] rounded-[50%] border-2 border-dashed border-white/70" aria-hidden="true"></div>
                <div v-if="camera.status === 'loading'" class="absolute inset-0 flex items-center justify-center text-inverse-on-surface">Đang mở camera…</div>
                <div v-if="camera.status === 'error'" class="absolute inset-0 flex flex-col items-center justify-center gap-sm p-md text-center text-inverse-on-surface">
                    <span class="material-symbols-outlined text-[36px]" aria-hidden="true">no_photography</span>
                    <p class="font-body-small text-body-small">{{ camera.error }}</p>
                </div>
            </div>
            <div class="flex justify-center gap-sm">
                <UiButton v-if="camera.status === 'live'" icon="photo_camera" class="h-12 px-xl" data-capture @click="capture">Chụp ảnh</UiButton>
                <UiButton v-if="camera.status === 'captured'" variant="secondary" icon="restart_alt" @click="retake">Chụp lại</UiButton>
                <template v-if="camera.status === 'error'">
                    <UiButton variant="secondary" icon="refresh" @click="startCamera">Thử lại</UiButton>
                    <UiButton icon="photo_camera" @click="fileInput?.click()">Chụp bằng camera máy</UiButton>
                </template>
                <input ref="fileInput" type="file" accept="image/*" capture="user" class="hidden" @change="onFilePicked" />
            </div>
            <p class="text-center font-caption text-caption text-on-surface-variant">Nhìn thẳng vào camera, đủ sáng. Ảnh lưu kèm lượt chấm công để quản lý xem lại.</p>

            <UiAlert v-if="serverErrors.length" type="error">
                <p v-for="(message, i) in serverErrors" :key="i">{{ message }}</p>
            </UiAlert>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="close">Hủy</UiButton>
            <UiButton icon="check" :disabled="!canSubmit" data-confirm-punch @click="submit">{{ submitting ? 'Đang gửi…' : 'Xác nhận chấm công' }}</UiButton>
        </template>
    </UiModal>
</template>
