<x-app-layout>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
</style>
    <div class="p-6">
        <!-- Embedded Panel Container (No outer navbar, sidebar or header as strictly specified) -->
<main class="w-full max-w-5xl flex flex-col gap-lg">
<!-- Header (First pixel starts here) -->
<header class="flex flex-col gap-1">
<h1 class="text-h1 font-h1 text-on-surface">Đánh giá dự giờ</h1>
<p class="text-body-base text-on-surface-variant">Ghi nhận đánh giá định kỳ và chuyên môn giảng dạy theo tháng cho từng lớp học.</p>
</header>
<!-- Form Area -->
<form class="flex flex-col gap-lg" onsubmit="event.preventDefault();">
<!-- Khối 1: Thông tin cơ bản -->
<section class="bg-surface-container-lowest border border-outline-variant/60 rounded-xl p-lg flex flex-col gap-md shadow-sm">
<div class="flex items-center gap-sm mb-sm border-b border-surface-variant pb-sm">
<span aria-hidden="true" class="material-symbols-outlined text-primary">info</span>
<h3 class="text-h3 font-h3 text-on-surface">Thông tin cơ bản</h3>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter">
<!-- Dropdown Lớp -->
<div class="flex flex-col gap-xs">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider" for="class-select">Lớp</label>
<div class="relative">
<select class="w-full appearance-none bg-surface-bright border border-outline-variant/70 rounded-lg px-md py-2.5 pr-10 text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors" id="class-select">
<option value="">Chọn lớp học</option>
<option selected="" value="class_1">Luyện thi Chuyên sâu - Khóa 24 (Giáo viên: Nguyễn Văn A)</option>
<option value="class_2">Giao tiếp Phản xạ Quốc tế - Khóa 12</option>
<option value="class_3">Nền tảng Toàn diện - Khóa 08 (Giáo viên: Trần Thị B)</option>
<option value="class_4">Ngữ pháp và Từ vựng Chuyên đề - Khóa 15</option>
</select>
<span class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">expand_more</span>
</div>
</div>
<!-- Dropdown Tháng -->
<div class="flex flex-col gap-xs">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider" for="month-select">Tháng</label>
<div class="relative">
<select class="w-full appearance-none bg-surface-bright border border-outline-variant/70 rounded-lg px-md py-2.5 pr-10 text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors" id="month-select">
<option value="">Chọn tháng đánh giá</option>
<option selected="" value="10_2026">Tháng 10/2026</option>
<option value="09_2026">Tháng 09/2026</option>
<option value="08_2026">Tháng 08/2026</option>
<option value="07_2026">Tháng 07/2026</option>
</select>
<span class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">expand_more</span>
</div>
</div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter mt-xs">
<!-- Input Tỉ lệ % chuyên cần HS -->
<div class="flex flex-col gap-xs">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider" for="attendance-rate">Tỉ lệ % chuyên cần học sinh</label>
<div class="relative">
<input class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg px-md py-2.5 text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors pr-xl" id="attendance-rate" max="100" min="0" placeholder="0" type="number" value="92"/>
<span class="absolute right-md top-1/2 -translate-y-1/2 text-on-surface-variant font-medium select-none">%</span>
</div>
</div>
<!-- Input Tỉ lệ % HS đạt yêu cầu -->
<div class="flex flex-col gap-xs">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider" for="pass-rate">Tỉ lệ % học sinh đạt yêu cầu</label>
<div class="relative">
<input class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg px-md py-2.5 text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors pr-xl" id="pass-rate" max="100" min="0" placeholder="0" type="number" value="88"/>
<span class="absolute right-md top-1/2 -translate-y-1/2 text-on-surface-variant font-medium select-none">%</span>
</div>
</div>
</div>
</section>
<!-- Khối 2: Chi tiết đánh giá -->
<section class="bg-surface-container-lowest border border-outline-variant/60 rounded-xl p-lg flex flex-col gap-md shadow-sm">
<!-- Tiêu đề mục & Công tắc Switch -->
<div class="flex items-center justify-between gap-md border-b border-surface-variant pb-sm mb-xs">
<div class="flex items-center gap-sm">
<span aria-hidden="true" class="material-symbols-outlined text-primary">fact_check</span>
<h3 class="text-h3 font-h3 text-on-surface">Chi tiết đánh giá</h3>
</div>
<!-- Switch: Đã dự giờ (Mặc định BẬT) -->
<label class="flex items-center gap-sm cursor-pointer select-none">
<span class="text-body-medium font-body-medium text-on-surface">Đã dự giờ</span>
<div class="relative inline-flex items-center">
<input checked="" class="sr-only peer" id="toggle-observed" type="checkbox"/>
<div class="w-11 h-6 bg-surface-variant peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-container shadow-sm"></div>
</div>
</label>
</div>
<!-- Vùng nội dung chi tiết: Tự động ẩn/hiện theo công tắc -->
<div class="flex flex-col gap-md transition-all duration-300" id="observed-details-container">
<!-- Hàng thông tin dự giờ: Ngày & Người dự giờ -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter bg-surface-container-low p-md rounded-lg border border-surface-variant">
<div class="flex flex-col gap-xs">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider" for="obs-date">Ngày dự giờ</label>
<input class="w-full bg-surface-container-lowest border border-outline-variant/70 rounded-lg px-md py-2 text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors" id="obs-date" type="date" value="2026-10-25"/>
</div>
<div class="flex flex-col gap-xs">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider" for="observer">Người dự giờ</label>
<div class="relative">
<select class="w-full appearance-none bg-surface-container-lowest border border-outline-variant/70 rounded-lg px-md py-2 pr-10 text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors" id="observer">
<option selected="" value="admin_huyen">Nguyễn Thị Huyền (Bộ phận Học thuật)</option>
<option value="admin_phong">Trần Đức Phong (Trưởng ban Đào tạo)</option>
<option value="admin_hoa">Lê Thanh Hoa (Cố vấn Chuyên môn)</option>
</select>
<span class="material-symbols-outlined pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">expand_more</span>
</div>
</div>
</div>
<!-- 6 Tiêu chí đánh giá xếp 2 cột -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter mt-xs">
<!-- Tiêu chí 1 -->
<div class="flex flex-col gap-xs">
<label class="text-body-medium font-body-medium text-on-surface flex items-center gap-xs" for="crit-1">
<span class="w-6 h-6 rounded-full bg-surface-container text-primary font-bold flex items-center justify-center text-caption">1</span>
Chất lượng giảng dạy
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-none placeholder:text-on-surface-variant/50" id="crit-1" placeholder="Nhận xét về chuyên môn, phương pháp truyền đạt và làm chủ bài học..." rows="3"></textarea>
</div>
<!-- Tiêu chí 2 -->
<div class="flex flex-col gap-xs">
<label class="text-body-medium font-body-medium text-on-surface flex items-center gap-xs" for="crit-2">
<span class="w-6 h-6 rounded-full bg-surface-container text-primary font-bold flex items-center justify-center text-caption">2</span>
Nội dung bài học
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-none placeholder:text-on-surface-variant/50" id="crit-2" placeholder="Nhận xét về độ bám sát giáo trình, phân bổ thời lượng và khối lượng kiến thức..." rows="3"></textarea>
</div>
<!-- Tiêu chí 3 -->
<div class="flex flex-col gap-xs">
<label class="text-body-medium font-body-medium text-on-surface flex items-center gap-xs" for="crit-3">
<span class="w-6 h-6 rounded-full bg-surface-container text-primary font-bold flex items-center justify-center text-caption">3</span>
Kỹ năng tương tác
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-none placeholder:text-on-surface-variant/50" id="crit-3" placeholder="Nhận xét về tương tác hai chiều với học sinh, bao quát lớp và xử lý tình huống sư phạm..." rows="3"></textarea>
</div>
<!-- Tiêu chí 4 -->
<div class="flex flex-col gap-xs">
<label class="text-body-medium font-body-medium text-on-surface flex items-center gap-xs" for="crit-4">
<span class="w-6 h-6 rounded-full bg-surface-container text-primary font-bold flex items-center justify-center text-caption">4</span>
Thái độ
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-none placeholder:text-on-surface-variant/50" id="crit-4" placeholder="Nhận xét về tác phong, tính chuẩn mực, năng lượng và sự tận tụy trong giờ giảng..." rows="3"></textarea>
</div>
<!-- Tiêu chí 5 -->
<div class="flex flex-col gap-xs">
<label class="text-body-medium font-body-medium text-on-surface flex items-center gap-xs" for="crit-5">
<span class="w-6 h-6 rounded-full bg-surface-container text-primary font-bold flex items-center justify-center text-caption">5</span>
Hiệu quả lớp học
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-none placeholder:text-on-surface-variant/50" id="crit-5" placeholder="Nhận xét về mức độ tiếp thu, sự tham gia hào hứng của học sinh và hoàn thành mục tiêu..." rows="3"></textarea>
</div>
<!-- Tiêu chí 6 -->
<div class="flex flex-col gap-xs">
<label class="text-body-medium font-body-medium text-on-surface flex items-center gap-xs" for="crit-6">
<span class="w-6 h-6 rounded-full bg-surface-container text-primary font-bold flex items-center justify-center text-caption">6</span>
Kết quả tổng kết
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-none placeholder:text-on-surface-variant/50" id="crit-6" placeholder="Đánh giá tổng quan buổi dạy, xếp loại chung và mức độ hoàn thành nhiệm vụ..." rows="3"></textarea>
</div>
</div>
<!-- Ghi chú / Hành động -->
<div class="flex flex-col gap-xs pt-md border-t border-surface-variant">
<label class="text-label font-label text-on-surface-variant uppercase tracking-wider flex items-center gap-xs" for="action-notes">
<span class="material-symbols-outlined text-[18px]">edit_note</span>
Ghi chú / Hành động
</label>
<textarea class="w-full bg-surface-bright border border-outline-variant/70 rounded-lg p-md text-body-base font-body-base text-on-surface focus:border-primary-container focus:ring-1 focus:ring-primary-container outline-none transition-colors resize-y placeholder:text-on-surface-variant/50" id="action-notes" placeholder="Ghi rõ kế hoạch theo dõi tiếp theo, đề xuất khắc phục hoặc giải pháp cải thiện chuyên môn..." rows="4"></textarea>
</div>
</div>
<!-- Thông báo khi tắt dự giờ (mặc định ẩn) -->
<div class="hidden py-8 text-center text-on-surface-variant bg-surface-container-low/50 rounded-lg border border-dashed border-outline-variant/50" id="unobserved-placeholder">
<span class="material-symbols-outlined text-4xl text-outline mb-2">event_busy</span>
<p class="text-body-medium">Lớp học này chưa thực hiện dự giờ trong tháng đã chọn.</p>
<p class="text-caption text-on-surface-variant/80 mt-1">Bật công tắc "Đã dự giờ" ở trên để ghi nhận thông tin và đánh giá chi tiết.</p>
</div>
</section>
<!-- Khối hành động cuối trang -->
<div class="flex justify-end items-center gap-md pt-xs">
<button class="px-lg py-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant/80 text-on-surface font-body-medium hover:bg-surface-container-low transition-colors" type="button">
Hủy bỏ
</button>
<button class="px-xl py-2.5 rounded-lg bg-primary-container text-on-primary font-body-medium flex items-center gap-xs hover:bg-primary active:scale-[0.99] transition-all shadow-sm" type="submit">
<span class="material-symbols-outlined text-[20px]">save</span>
Lưu
</button>
</div>
</form>
</main>
<!-- Kịch bản chuyển đổi Đã dự giờ -->
<script>
  const toggleObserved = document.getElementById('toggle-observed');
  const detailsContainer = document.getElementById('observed-details-container');
  const unobservedPlaceholder = document.getElementById('unobserved-placeholder');

  function updateObservedState() {
    if (toggleObserved.checked) {
      detailsContainer.classList.remove('hidden');
      unobservedPlaceholder.classList.add('hidden');
    } else {
      detailsContainer.classList.add('hidden');
      unobservedPlaceholder.classList.remove('hidden');
    }
  }

  toggleObserved.addEventListener('change', updateObservedState);
</script>
    </div>
</x-app-layout>