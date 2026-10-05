/**
 * Vị trí GPS & camera trên trình duyệt (chấm công điện thoại, cài toạ độ cơ sở).
 *   const pos = await currentPosition();            // { latitude, longitude, accuracy }
 *   distanceMeters(lat1, lng1, lat2, lng2)           // mét, cùng công thức với App\Support\Geo (máy chủ quyết định cuối)
 * Trình duyệt chỉ cho dùng GPS / camera trên https (hoặc localhost).
 */

const EARTH_RADIUS_METERS = 6371000;

export function distanceMeters(lat1, lng1, lat2, lng2) {
    const rad = (deg) => (deg * Math.PI) / 180;
    const dLat = rad(lat2 - lat1);
    const dLng = rad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;
    return EARTH_RADIUS_METERS * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function secureContextMessage() {
    if (typeof window === 'undefined' || window.isSecureContext) return null;
    return 'Trình duyệt chỉ cho dùng định vị và camera khi mở trang bằng https://. Hãy mở hệ thống bằng địa chỉ https.';
}

/** Vị trí hiện tại (độ chính xác cao, không dùng vị trí cũ). Lỗi → Error với thông báo tiếng Việt. */
export function currentPosition({ timeout = 20000 } = {}) {
    return new Promise((resolve, reject) => {
        const insecure = secureContextMessage();
        if (insecure) return reject(new Error(insecure));
        if (!navigator.geolocation) return reject(new Error('Trình duyệt này không hỗ trợ định vị GPS.'));
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
            (error) => {
                const messages = {
                    1: 'Bạn chưa cho phép truy cập vị trí. Vào cài đặt trình duyệt → Quyền của trang → Vị trí → Cho phép, rồi thử lại.',
                    2: 'Không xác định được vị trí. Hãy bật GPS / định vị của điện thoại và ra chỗ thoáng.',
                    3: 'Lấy vị trí quá lâu. Hãy bật GPS và thử lại.',
                };
                reject(new Error(messages[error.code] ?? 'Không lấy được vị trí GPS.'));
            },
            { enableHighAccuracy: true, timeout, maximumAge: 0 },
        );
    });
}

/** Mở camera trước (selfie). Không có camera / bị chặn → Error với thông báo tiếng Việt. */
export async function openFrontCamera() {
    const insecure = secureContextMessage();
    if (insecure) throw new Error(insecure);
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('Trình duyệt này không mở được camera trực tiếp.');
    try {
        return await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 960 } }, audio: false });
    } catch (error) {
        if (error?.name === 'NotAllowedError') {
            throw new Error('Bạn chưa cho phép dùng camera. Vào cài đặt trình duyệt → Quyền của trang → Camera → Cho phép, rồi thử lại.');
        }
        throw new Error('Không mở được camera trước của điện thoại.');
    }
}

/** Chụp 1 khung hình từ <video> (hoặc ảnh) → File JPEG cạnh dài tối đa `max` px (ảnh nhỏ, gửi nhanh qua 4G). */
export function snapshot(source, { max = 720, quality = 0.82 } = {}) {
    const width = source.videoWidth || source.naturalWidth || source.width;
    const height = source.videoHeight || source.naturalHeight || source.height;
    const scale = Math.min(1, max / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(new File([blob], `cham-cong-${Date.now()}.jpg`, { type: 'image/jpeg' })) : reject(new Error('Không chụp được ảnh, hãy thử lại.'))),
            'image/jpeg',
            quality,
        );
    });
}

/** Thu nhỏ ảnh chọn từ ô chụp ảnh của điện thoại (dự phòng khi không mở được camera trực tiếp). */
export function shrinkImageFile(file, options) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => snapshot(img, options).then(resolve, reject).finally(() => URL.revokeObjectURL(url));
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Ảnh không đọc được, hãy chụp lại.'));
        };
        img.src = url;
    });
}
