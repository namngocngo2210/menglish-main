<script setup>
/**
 * Bản đồ chọn toạ độ chấm công của cơ sở (OpenStreetMap + Leaflet, không cần API key).
 *   <BranchLocationMap :latitude="lat" :longitude="lng" :radius="radius" :address="branch.address" @pick="({ latitude, longitude }) => …" />
 * Tìm theo địa chỉ (qua máy chủ, xem search()), bấm vào bản đồ hoặc kéo ghim để chỉnh; vòng tròn = bán kính được chấm công.
 * Sửa tay ô vĩ độ / kinh độ → ghim tự chạy theo. Leaflet chỉ tải trên trình duyệt (trang có render phía server).
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { firstError } from '@/lib/http';
import { parseCoordinates } from '@/lib/geo';
import { route } from '@/lib/route';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

const props = defineProps({
    latitude: { type: [String, Number], default: null },
    longitude: { type: [String, Number], default: null },
    radius: { type: [String, Number], default: 100 },
    address: { type: String, default: '' },
});
const emit = defineEmits(['pick']);

const DEFAULT_CENTER = [21.0285, 105.8542]; // Hà Nội, khi cơ sở chưa có toạ độ
const CONNECT_ERROR = 'Không kết nối được dịch vụ tìm địa chỉ. Thử lại sau, hoặc bấm thẳng vào bản đồ / dán toạ độ.';

const container = ref(null);
const query = ref(props.address ?? '');
const searching = ref(false);
const results = ref([]);
const searchMessage = ref(null);
const mapError = ref(null);

let L = null;
let map = null;
let marker = null;
let circle = null;
let resizeObserver = null;

function point(lat, lng) {
    const latitude = Number.parseFloat(lat);
    const longitude = Number.parseFloat(lng);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) return null;
    return [latitude, longitude];
}

function radiusMeters() {
    const value = Number.parseInt(props.radius, 10);
    return Number.isFinite(value) && value > 0 ? value : 100;
}

/** Đặt ghim + vòng bán kính tại `latlng`; `fit` → phóng bản đồ vừa vòng tròn. */
function place(latlng, { fit = false } = {}) {
    if (!map) return;
    if (!marker) {
        marker = L.marker(latlng, { draggable: true, autoPan: true, title: 'Kéo để chỉnh vị trí cơ sở' }).addTo(map);
        marker.on('drag', () => circle?.setLatLng(marker.getLatLng()));
        marker.on('dragend', () => pick(marker.getLatLng()));
        circle = L.circle(latlng, { radius: radiusMeters(), color: '#0b57d0', weight: 2, fillOpacity: 0.12 }).addTo(map);
    } else {
        marker.setLatLng(latlng);
        circle.setLatLng(latlng);
    }
    if (fit) map.fitBounds(circle.getBounds(), { padding: [24, 24], maxZoom: 18 });
}

function pick(latlng, options) {
    place([latlng.lat, latlng.lng], options);
    emit('pick', { latitude: latlng.lat.toFixed(7), longitude: latlng.lng.toFixed(7) });
}

/**
 * Tìm qua máy chủ (route branches.address-search → App\Support\AddressSearch): máy chủ gọi Nominatim / Photon, tự thử lại
 * khi địa chỉ đủ số nhà / ngõ không khớp và trả `matched` = câu đã khớp. Trình duyệt không gọi thẳng dịch vụ bản đồ
 * (Nominatim chặn / giới hạn request từ trình duyệt, mạng công ty có thể chặn tên miền ngoài).
 */
async function search() {
    const text = query.value.trim();
    if (!text || searching.value) return;
    // Dán toạ độ / link Google Maps vào ô tìm → đặt ghim luôn, không cần tìm.
    const coordinates = parseCoordinates(text);
    if (coordinates) {
        results.value = [];
        searchMessage.value = `Đã đặt ghim theo toạ độ ${coordinates.latitude}, ${coordinates.longitude}.`;
        pick({ lat: Number(coordinates.latitude), lng: Number(coordinates.longitude) }, { fit: true });
        return;
    }
    searching.value = true;
    searchMessage.value = null;
    results.value = [];
    try {
        const response = await fetch(route('branches.address-search', { q: text }), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            searchMessage.value = response.status === 429 ? 'Tìm quá nhiều lần liên tiếp, đợi 1 phút rồi thử lại.' : firstError(data, CONNECT_ERROR);
            return;
        }
        results.value = data.results ?? [];
        if (!results.value.length) {
            searchMessage.value = 'Không tìm thấy địa chỉ. Thử ghi ngắn hơn (tên đường, phường, quận, tỉnh) hoặc bấm thẳng vào bản đồ.';
        } else if (data.matched) {
            searchMessage.value = `Không thấy chính xác địa chỉ đã nhập, đang hiện kết quả gần nhất cho "${data.matched}". Kéo ghim về đúng cổng cơ sở.`;
        }
    } catch {
        searchMessage.value = CONNECT_ERROR;
    } finally {
        searching.value = false;
    }
}

function choose(result) {
    results.value = [];
    query.value = result.label;
    pick({ lat: result.lat, lng: result.lng }, { fit: true });
}

onMounted(async () => {
    try {
        const [leaflet] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);
        L = leaflet.default ?? leaflet;
    } catch {
        mapError.value = 'Không tải được bản đồ. Vẫn có thể nhập toạ độ bằng tay.';
        return;
    }
    if (!container.value) return;
    L.Icon.Default.mergeOptions({ iconUrl: markerIcon, iconRetinaUrl: markerIcon2x, shadowUrl: markerShadow });

    const start = point(props.latitude, props.longitude);
    // Cuộn chuột chỉ phóng to sau khi bấm vào bản đồ: không "nuốt" thao tác cuộn hộp thoại.
    map = L.map(container.value, { scrollWheelZoom: false }).setView(start ?? DEFAULT_CENTER, start ? 17 : 12);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);
    map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
    map.once('focus', () => map.scrollWheelZoom.enable());
    map.on('click', (event) => pick(event.latlng));
    if (start) place(start, { fit: true });

    // Hộp thoại mở có hiệu ứng / đổi cỡ → báo Leaflet tính lại khung.
    resizeObserver = new ResizeObserver(() => map?.invalidateSize());
    resizeObserver.observe(container.value);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map?.remove();
    map = marker = circle = null;
});

// Ô vĩ độ / kinh độ đổi (gõ tay, dán, "Lấy vị trí hiện tại") → ghim chạy theo.
watch(
    () => [props.latitude, props.longitude],
    ([lat, lng]) => {
        const latlng = point(lat, lng);
        if (!latlng || !map) return;
        const current = marker?.getLatLng();
        if (current && Math.abs(current.lat - latlng[0]) < 1e-7 && Math.abs(current.lng - latlng[1]) < 1e-7) return;
        place(latlng, { fit: true });
    },
);
watch(
    () => props.radius,
    () => {
        if (!circle) return;
        circle.setRadius(radiusMeters());
        map.fitBounds(circle.getBounds(), { padding: [24, 24], maxZoom: 18 });
    },
);
</script>

<template>
    <div class="space-y-sm">
        <div class="relative">
            <div class="flex gap-sm">
                <div class="min-w-0 flex-1">
                    <UiInput
                        v-model="query"
                        icon="search"
                        placeholder="Tìm địa chỉ, hoặc dán link / toạ độ Google Maps"
                        aria-label="Tìm địa chỉ trên bản đồ"
                        autocomplete="off"
                        data-map-search
                        @keydown.enter.prevent="search"
                    />
                </div>
                <UiButton variant="secondary" icon="travel_explore" :disabled="searching || !query.trim()" data-map-search-button @click="search">
                    {{ searching ? 'Đang tìm…' : 'Tìm' }}
                </UiButton>
            </div>
            <ul
                v-if="results.length"
                class="absolute inset-x-0 top-full z-[1100] mt-1 max-h-64 overflow-y-auto rounded-lg border border-outline-variant bg-surface-container-lowest py-1 shadow-lg"
                role="listbox"
                aria-label="Kết quả tìm địa chỉ"
                data-map-results
            >
                <li v-for="result in results" :key="result.id">
                    <button type="button" class="flex w-full items-start gap-2 px-3 py-2 text-left text-xs text-on-surface hover:bg-surface-container-high" @click="choose(result)">
                        <span class="material-symbols-outlined mt-px text-[16px] text-primary-container">location_on</span>
                        <span>{{ result.label }}</span>
                    </button>
                </li>
            </ul>
        </div>
        <p v-if="searchMessage" class="font-caption text-caption text-on-surface-variant" data-map-message>{{ searchMessage }}</p>
        <UiAlert v-if="mapError" type="warning">{{ mapError }}</UiAlert>
        <div v-else ref="container" class="relative z-0 h-64 w-full overflow-hidden rounded-lg border border-outline-variant bg-surface-container-low sm:h-72" data-branch-map></div>
        <p class="font-caption text-caption text-on-surface-variant">Bấm vào bản đồ hoặc kéo ghim đến đúng cổng cơ sở; vòng tròn là vùng được chấm công.</p>
    </div>
</template>
