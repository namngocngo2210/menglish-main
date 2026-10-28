/**
 * Mức khẩn của một dòng trên Tổng quan (Overview.php): quá hạn → hôm nay → sắp tới → thông tin; "done" = đã xong / tốt.
 * Màu theo token tailwind (không mã màu cứng), chấm và chip dùng cùng một bảng.
 */
export const tones = {
    overdue: { dot: 'bg-error', chip: 'bg-error-container text-error' },
    today: { dot: 'bg-primary-container', chip: 'bg-primary-fixed text-primary' },
    soon: { dot: 'bg-secondary-container', chip: 'bg-secondary-fixed text-secondary' },
    info: { dot: 'bg-outline', chip: 'bg-surface-container-high text-on-surface-variant' },
    done: { dot: 'bg-tertiary', chip: 'bg-tertiary-fixed/50 text-tertiary' },
};

export const tone = (key) => tones[key] ?? tones.info;
