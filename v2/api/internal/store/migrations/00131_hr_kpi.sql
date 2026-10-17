-- KPI nhân sự (Laravel: kpi_criteria, kpi_evaluations, kpi_evaluation_items — KpiController, KpiSheetService).
-- Tiêu chí theo vai trò cố định; mỗi nhân sự một phiếu mỗi kỳ (tháng, hoặc quý với vai trò chấm theo quý — phiếu quý lưu ở
-- tháng đầu quý) cho từng mảng việc (main / teaching). Chu kỳ, quỹ, thưởng theo xếp loại nằm ở core.settings
-- (kpi_cycle_<vai trò>, kpi_grade_fund_<vai trò>, payroll_academic_kpi_fund).
-- +goose Up
CREATE TABLE hr.kpi_criteria (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    role           text NOT NULL CHECK (role IN ('academic_staff', 'academic_lead', 'manager', 'sales_consultant',
                                                 'teacher_fulltime', 'teacher_parttime', 'assistant')),
    group_name     text CHECK (length(group_name) <= 255),
    code           text CHECK (length(code) <= 20),
    name           text NOT NULL CHECK (length(name) BETWEEN 1 AND 255),
    -- Trọng số: % quỹ KPI (Học vụ) hoặc điểm tối đa (vai trò khác)
    weight         numeric(5, 2) NOT NULL DEFAULT 0 CHECK (weight BETWEEN 0 AND 100),
    -- Nhãn ngưỡng 100% / 50% của bộ Học vụ (tự sinh từ max_full / max_half)
    threshold_full text,
    threshold_half text,
    max_full       integer CHECK (max_full BETWEEN 0 AND 9999),
    max_half       integer CHECK (max_half BETWEEN 0 AND 9999),
    unit           text CHECK (unit IN ('lan', 'ho_so', 'case', 'sai_sot', 'su_co', 'lop', 'buoi', 'hoc_vien', 'bai', 'ngay', 'phan_tram')),
    measure        text NOT NULL DEFAULT 'count' CHECK (measure IN ('count', 'rate', 'rate_down')),
    -- Bậc tính điểm [[ngưỡng, % điểm], …]
    tiers          jsonb CHECK (tiers IS NULL OR jsonb_typeof(tiers) = 'array'),
    linear         boolean NOT NULL DEFAULT false,
    full_at        numeric(7, 2) CHECK (full_at > 0),
    per_month      boolean NOT NULL DEFAULT false,
    knockout       boolean NOT NULL DEFAULT false,
    allow_na       boolean NOT NULL DEFAULT false,
    -- Nguồn số liệu tự động (KpiCriterion::AUTO_SOURCES); NULL = người chấm điền tay
    auto_source    text CHECK (length(auto_source) <= 40),
    description    text CHECK (length(description) <= 2000),
    is_active      boolean NOT NULL DEFAULT true,
    sort_order     integer NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE INDEX kpi_criteria_role_idx ON hr.kpi_criteria (role, sort_order) WHERE deleted_at IS NULL;

-- Phiếu KPI: draft = Chờ duyệt (tự tạo đầu kỳ), confirmed = Đã duyệt (vào bảng lương), rejected = Không duyệt.
CREATE TABLE hr.kpi_sheets (
    id            uuid PRIMARY KEY DEFAULT uuidv7(),
    user_id       uuid NOT NULL REFERENCES iam.users (id) ON DELETE CASCADE,
    evaluator_id  uuid REFERENCES iam.users (id) ON DELETE SET NULL,
    month         smallint NOT NULL CHECK (month BETWEEN 1 AND 12),
    year          smallint NOT NULL CHECK (year BETWEEN 2020 AND 2100),
    track         text NOT NULL DEFAULT 'main' CHECK (track IN ('main', 'teaching')),
    period_months smallint NOT NULL DEFAULT 1 CHECK (period_months IN (1, 3)),
    total_score   numeric(6, 2) NOT NULL DEFAULT 0,
    comment       text,
    strengths     text CHECK (length(strengths) <= 2000),
    improvements  text CHECK (length(improvements) <= 2000),
    next_actions  text CHECK (length(next_actions) <= 2000),
    status        text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'confirmed', 'rejected')),
    reject_reason text CHECK (length(reject_reason) <= 1000),
    decided_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, year, month, track)
);
CREATE INDEX kpi_sheets_period_idx ON hr.kpi_sheets (year, month);

-- Dòng phiếu: số liệu (actual), mức đạt (score, % điểm của tiêu chí), bằng chứng hệ thống đã đếm lúc duyệt.
CREATE TABLE hr.kpi_items (
    id             uuid PRIMARY KEY DEFAULT uuidv7(),
    sheet_id       uuid NOT NULL REFERENCES hr.kpi_sheets (id) ON DELETE CASCADE,
    criterion_id   uuid NOT NULL REFERENCES hr.kpi_criteria (id) ON DELETE CASCADE,
    score          numeric(6, 2) NOT NULL DEFAULT 0,
    actual         text CHECK (length(actual) <= 255),
    critical_error boolean NOT NULL DEFAULT false,
    not_applicable boolean NOT NULL DEFAULT false,
    note           text,
    evidence       jsonb,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (sheet_id, criterion_id)
);

-- Bộ tiêu chí mặc định: Học vụ (file KPI Học vụ, 15 tiêu chí), GV part-time (16 dòng, gồm điều kiện loại trừ), Học thuật (12).
INSERT INTO hr.kpi_criteria (role, group_name, code, name, weight, measure, unit, tiers, linear, full_at, per_month, knockout, allow_na, max_full, max_half, threshold_full, threshold_half, auto_source, description, sort_order) VALUES
    ('academic_staff', 'Học phí & dữ liệu', '1.1', 'Quy trình nhắc & follow học phí đúng hạn', 12.5, 'count', 'ho_so', NULL, false, NULL, false, false, false, 0, 2, '0 hồ sơ', '≤ 2 hồ sơ', 'tuition_no_reminder', 'Số hồ sơ KHÔNG thực hiện đúng quy trình nhắc học phí (không nhắc, nhắc 1 lần rồi bỏ, không follow, không báo cáo founder khi phụ huynh không hợp tác). Không đo việc phụ huynh có đóng tiền hay không.', 1),
    ('academic_staff', 'Học phí & dữ liệu', '1.2', 'Thu đúng học phí', 7.5, 'count', 'lan', NULL, false, NULL, false, false, false, 0, 1, '0 lần', '≤ 1 lần', 'receipt_rejected', 'Số lần thu sai/thiếu học phí trong tháng (sai số tiền, bỏ sót giảm trừ, thu nhầm gói học).', 2),
    ('academic_staff', 'Học phí & dữ liệu', '1.3', 'Dữ liệu học sinh/học phí trên hệ thống', 5, 'count', 'sai_sot', NULL, false, NULL, false, false, false, 0, 2, '0 sai sót', '≤ 2 sai sót', NULL, 'Số sai sót dữ liệu trên webapp/hệ thống trong tháng (nhập sai, thiếu, cập nhật trễ).', 3),
    ('academic_staff', 'Học viên & phụ huynh', '2.1', 'SLA phản hồi phụ huynh', 7.5, 'count', 'case', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 case', '≤ 10 case', NULL, 'Số case phản hồi trễ SLA trong tháng qua Zalo Saleswork (SLA: trong ngày làm việc).', 4),
    ('academic_staff', 'Học viên & phụ huynh', '2.2', 'Case học sinh bỏ sót', 2.5, 'count', 'case', NULL, false, NULL, false, false, false, 0, 1, '0 case', '≤ 1 case', 'care_overdue', 'Số case học sinh đang học bị bỏ sót (nghỉ không được follow/nhắc học bù, vấn đề không được ghi nhận). Chỉ tính nếu chưa bị tính ở 2.3.', 5),
    ('academic_staff', 'Học viên & phụ huynh', '2.3', 'Founder phải can thiệp trực tiếp', 5, 'count', 'lan', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 lần', '≤ 10 lần', NULL, 'Số lần founder phải trực tiếp xử lý thay/can thiệp trong tháng do học vụ xử lý chưa tới nơi tới chốn.', 6),
    ('academic_staff', 'Học viên & phụ huynh', '2.4', 'Feedback phụ huynh theo từng lớp (mốc Big Test)', 5, 'count', 'lop', NULL, false, NULL, false, false, false, 0, 1, '0 lớp', '≤ 1 lớp', NULL, 'Số lớp đến mốc Big Test trong tháng (~16–18 buổi/lần) nhưng CHƯA đạt tối thiểu 40% học sinh của lớp phản hồi feedback, CỘNG số feedback tiêu cực chưa được xử lý/báo cáo.', 7),
    ('academic_staff', 'Tuyển sinh & Truyền thông', '3.1', 'Xử lý data/test tuyển sinh', 10, 'count', 'case', NULL, false, NULL, false, false, false, 0, 2, '0 case', '≤ 2 case', 'crm_sla_late', 'Số data/case tuyển sinh bị bỏ sót hoặc xử lý trễ trong tháng (không liên hệ, không sắp lịch test, không follow sau test).', 8),
    ('academic_staff', 'Tuyển sinh & Truyền thông', '3.2', 'Truyền thông — đăng bài đúng hạn', 10, 'count', 'lan', NULL, false, NULL, false, false, false, 0, 2, '0 lần', '≤ 2 lần', NULL, 'Số lần không đăng bài truyền thông đúng hạn/kế hoạch được phân công trong tháng. Chỉ chấm đúng hạn, không chấm chất lượng nội dung.', 9),
    ('academic_staff', 'Báo cáo & tuân thủ quy trình', '4.1', 'Nộp báo cáo đúng hạn', 7.5, 'count', 'lan', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 lần', '≤ 10 lần', 'daily_report_late', 'Số lần nộp báo cáo vận hành ngày trễ hạn (trước 9h sáng hôm sau) trong tháng.', 10),
    ('academic_staff', 'Báo cáo & tuân thủ quy trình', '4.2', 'Nội dung báo cáo đầy đủ, đúng form', 3.75, 'count', 'lan', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 lần', '≤ 10 lần', NULL, 'Số lần báo cáo thiếu mục / sai nội dung / ghi chung chung trong tháng.', 11),
    ('academic_staff', 'Báo cáo & tuân thủ quy trình', '4.3', 'Tuân thủ quy trình chuyên môn đã đào tạo', 3.75, 'count', 'lan', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 lần', '≤ 10 lần', NULL, 'Số lần làm sai quy trình đã được đào tạo trong tháng (bàn giao ca, xử lý học phí, test/tuyển sinh…).', 12),
    ('academic_staff', 'Vận hành lớp học', '5.1', 'Sự cố vận hành lớp do lỗi học vụ', 10, 'count', 'su_co', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 sự cố', '≤ 10 sự cố', NULL, 'Số sự cố vận hành lớp trong tháng do lỗi học vụ (thiếu GV/TG không có phương án thay thế, lớp bị bỏ trống, sai lịch).', 13),
    ('academic_staff', 'Vận hành lớp học', '5.2', 'Cơ sở vật chất & xuất nhập sách', 5, 'count', 'sai_sot', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 sai sót', '≤ 10 sai sót', 'material_stock', 'Số sai sót trong tháng về cơ sở vật chất, xuất nhập sách, kiểm kê, chuẩn bị phòng học.', 14),
    ('academic_staff', 'Giáo viên & phối hợp', '6.1', 'Phối hợp thông tin với giáo viên', 5, 'count', 'lan', NULL, false, NULL, false, false, false, 4, 10, '≤ 4 lần', '≤ 10 lần', NULL, 'Số lần phối hợp sai/chậm với giáo viên gây ảnh hưởng buổi dạy trong tháng.', 15),
    ('teacher_parttime', 'Tuân thủ & Chuyên cần', NULL, 'Nghỉ dạy không phép', 0, 'count', 'buoi', '[[0, 100]]', false, NULL, false, true, false, NULL, NULL, NULL, NULL, 'teacher_absent_unexcused', 'Buổi dạy trong lịch mà GV không có giờ dạy (check-in) và không có đơn nghỉ được duyệt. Có từ 1 buổi trong tháng là mất toàn bộ KPI tháng; ngoài ra xử lý theo bảng phạt GV.', 1),
    ('teacher_parttime', 'Tuân thủ & Chuyên cần', NULL, 'Chuyên cần (nghỉ có phép)', 7, 'count', 'lan', '[[1, 100], [2, 50]]', false, NULL, true, false, false, NULL, NULL, NULL, NULL, 'teacher_leave', 'Số ngày nghỉ dạy có đơn được duyệt trong tháng: 1 lần đủ 7 điểm, lần 2 còn 3,5 điểm, từ lần 3 là 0.', 2),
    ('teacher_parttime', 'Tuân thủ & Chuyên cần', NULL, 'Đi muộn giờ dạy', 8, 'count', 'lan', '[[1, 100], [2, 62.5], [3, 25]]', false, NULL, true, false, false, NULL, NULL, NULL, NULL, 'teacher_late', 'Số buổi check-in muộn (mọi số phút) trong tháng: 1 lần đủ 8 điểm, lần 2 còn 5, lần 3 còn 2, từ lần 4 là 0. Phạt tiền đi muộn tính riêng ngoài KPI.', 3),
    ('teacher_parttime', 'Chất lượng học tập', NULL, 'Speaking completion (dự án speaking đầu ra)', 7, 'rate', 'phan_tram', NULL, true, 100, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số speaking đã hoàn thành ÷ số speaking phải có (%). Điểm theo tỉ lệ.', 4),
    ('teacher_parttime', 'Chất lượng học tập', NULL, 'Kết quả Test (điểm + tỷ lệ đạt chuẩn)', 8, 'rate', 'phan_tram', NULL, true, 100, false, false, false, NULL, NULL, NULL, NULL, 'test_result', 'Điểm TB quy về thang 10 × 50% + tỷ lệ học sinh đạt chuẩn (≥ 7 điểm) × 50%, từ Big Test và mini test của các lớp trong tháng.', 5),
    ('teacher_parttime', 'Chất lượng học tập', NULL, 'Tăng trưởng / tiến bộ học tập của học sinh', 5, 'rate', 'phan_tram', NULL, true, 10, false, false, false, NULL, NULL, NULL, NULL, 'test_progress', '% điểm test tăng = (điểm TB lần test cuối − lần đầu trong tháng) ÷ lần đầu. Đạt mốc (mặc định 10%) là đủ điểm, ≤ 0% là 0, ở giữa theo tỉ lệ.', 6),
    ('teacher_parttime', 'Chất lượng học tập', NULL, 'Tỷ lệ chuyên cần học sinh của lớp', 5, 'rate', 'phan_tram', '[[90, 100], [80, 50]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, 'class_attendance_rate', 'Lượt có mặt + đi muộn ÷ tổng lượt điểm danh của các lớp: ≥ 90% đủ điểm, 80–89% một nửa, dưới 80% là 0.', 7),
    ('teacher_parttime', 'Chất lượng học tập', NULL, 'Tỷ lệ hoàn thành bài tập về nhà (BTVN)', 5, 'rate', 'phan_tram', '[[95, 100], [90, 80], [85, 60]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, 'homework_rate', 'Lượt học sinh nộp bài trong hạn ÷ (số bài giao × sĩ số): ≥ 95% đủ 5 điểm, ≥ 90% được 4, ≥ 85% được 3, dưới 85% là 0.', 8),
    ('teacher_parttime', 'Chất lượng học tập', NULL, 'Theo dõi & cảnh báo HS nghỉ / bất thường', 5, 'rate', 'phan_tram', NULL, true, 100, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số case đã báo đúng hạn ÷ số case cần báo (%), gồm cả trường hợp bất thường của học sinh. Điểm theo tỉ lệ.', 9),
    ('teacher_parttime', 'Chất lượng giảng dạy', NULL, 'Hoàn thành mục tiêu đầu ra Lesson/Unit (Big Test)', 5, 'rate', 'phan_tram', NULL, true, 100, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số học sinh đạt mục tiêu ÷ số học sinh phải đạt (%), theo lần đo gần nhất; mục tiêu đặt riêng theo từng lớp / chương trình.', 10),
    ('teacher_parttime', 'Chất lượng giảng dạy', NULL, 'Dự giờ / quan sát lớp (≥ 2 lần/quý)', 10, 'rate', 'phan_tram', NULL, true, 100, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Điểm trung bình các lần dự giờ trong tháng (thang 100); tháng không có dự giờ lấy điểm lần gần nhất. File yêu cầu ≥ 2 lần/quý. Thang chấm dự giờ chính thức chờ chủ dự án gửi.', 11),
    ('teacher_parttime', 'Báo cáo & Phối hợp', NULL, 'Báo cáo tháng đúng hạn (trước 12h ngày mùng 2)', 5, 'rate', 'phan_tram', NULL, true, 100, false, false, false, NULL, NULL, NULL, NULL, 'monthly_report_on_time', 'Số tháng nộp báo cáo tháng trước 12h ngày mùng 2 tháng sau (tháng có nộp đúng hạn = 100%).', 12),
    ('teacher_parttime', 'Báo cáo & Phối hợp', NULL, 'Thông báo kịp thời vấn đề lớp (nghỉ / đổi ca / case HS)', 5, 'count', 'lan', '[[0, 100], [1, 50]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số lần không thông báo kịp thời trong tháng: mỗi lần trừ 2,5 điểm (0 lần đủ 5 điểm, 1 lần còn 2,5, từ 2 lần là 0).', 13),
    ('teacher_parttime', 'Trách nhiệm với HS / Phụ huynh', NULL, 'Tái tục: học sinh không tái tục do lỗi GV', 10, 'count', 'hoc_vien', '[[1, 100], [2, 50]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số học sinh không tái tục đã xác minh do lỗi GV (Học vụ + Admin xác minh): ≤ 1 đủ 10 điểm, 2 học sinh được 5, từ 3 là 0. Học sinh nghỉ vì lý do cá nhân / gia đình / học phí không tính.', 14),
    ('teacher_parttime', 'Trách nhiệm với HS / Phụ huynh', NULL, 'Feedback phụ huynh đã xác minh là lỗi GV', 10, 'count', 'lan', '[[0, 100], [1, 80], [2, 60], [3, 40], [4, 20]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số feedback gắn nhãn "Lỗi GV" trong tháng (khảo sát, gọi điện, Zalo, thiếu bài tập): 0 đủ 10 điểm, mỗi feedback trừ 2 điểm, thấp nhất 0.', 15),
    ('teacher_parttime', 'Đào tạo / Họp / Sự kiện', NULL, 'Tham gia đầy đủ đào tạo / họp / sự kiện bắt buộc', 5, 'count', 'buoi', '[[0, 100]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số buổi bắt buộc được tổ chức trong tháng mà GV không tham gia: 0 buổi đủ 5 điểm, vắng là 0.', 16),
    ('academic_lead', 'Chương trình & học liệu', NULL, 'Deliverable đúng hạn theo master plan (Program / Book / Slide / Test / Speaking)', 13, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, 'academic_deliverable_on_time', 'Mốc dự án học thuật đến hạn trong tháng (người phụ trách hoặc chủ dự án): đúng hạn = 1; trễ 1–3 ngày = 0,5; 4–7 ngày = 0,25; từ 8 ngày = 0. % = trung bình hệ số, quy đổi theo Bảng A. Tháng ít hơn 3 mốc thì gộp thêm tháng trước; không có mốc nào → Không phát sinh.', 1),
    ('academic_lead', 'Chương trình & học liệu', NULL, 'Chất lượng deliverable (đạt ngay vòng duyệt đầu)', 10, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, NULL, '% deliverable đạt ngay vòng duyệt đầu (chỉ sửa nhỏ), người duyệt độc lập với tác giả. Có lỗi Major phát hiện sau phát hành trong tháng → người chấm điền tối đa mức cho 50% điểm.', 2),
    ('academic_lead', 'Chương trình & học liệu', NULL, 'Kiểm soát tiến độ thực hiện chương trình', 9, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, NULL, '% lớp đang học bám đúng tiến độ (lệch ≤ 1 buổi so với kế hoạch), sau loại trừ có mã lý do (tài liệu trễ, GV nghỉ/đổi GV, lớp tạm dừng, sự kiện trung tâm).', 3),
    ('academic_lead', 'Đào tạo & rà soát giảng dạy GV', NULL, 'Đào tạo & triển khai thay đổi học thuật cho GV đúng kế hoạch', 9, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, NULL, 'Buổi/module đào tạo trong kế hoạch tháng (GV mới, định kỳ, quy trình học thuật, chương trình mới/đổi mục tiêu): đúng kế hoạch = 1; bù trong ≤ 7 ngày = 0,5; không thực hiện = 0. Thay đổi phát sinh: xong trước ngày áp dụng ≥ 3 ngày = 1, sát ngày = 0,5, sau ngày áp dụng = 0. GV có đi dự hay không tính ở KPI GV.', 4),
    ('academic_lead', 'Đào tạo & rà soát giảng dạy GV', NULL, 'Xử lý phản ánh về GV đúng hạn', 10, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, NULL, '% phản ánh về GV trong tháng (báo cáo dự giờ Học vụ, phản ánh chất lượng, HS/PH, rà soát hồ sơ lớp) được xử lý và phản hồi đúng hạn: nghiêm trọng ≤ 1 ngày làm việc, nhẹ ≤ 3 ngày, báo cáo dự giờ ≤ 5 ngày. Chỉ đo tốc độ xử lý, không chấm số lỗi của GV.', 5),
    ('academic_lead', 'Chất lượng đầu ra & kiểm soát lỗi GV', NULL, 'Chất lượng đầu ra học sinh (cuốn chiếu 3 tháng)', 15, 'rate', 'phan_tram', '[[90, 100], [85, 80], [80, 60], [70, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, 'student_pass_rate', '% bài Big Test / mini test đạt chuẩn (≥ 7 điểm thang 10) của các lớp trong 3 tháng gần nhất: ≥ 90% đủ điểm, 85–89% 80%, 80–84% 60%, 70–79% 30%, dưới 70% là 0. Điều kiện chặn "0% cuối quý 2 quý liên tiếp → tối đa loại C" người duyệt tự áp.', 6),
    ('academic_lead', 'Chất lượng đầu ra & kiểm soát lỗi GV', NULL, 'Lỗi GV lặp lại sau khi đã xử lý', 4, 'rate_down', 'phan_tram', '[[0, 100], [10, 75], [25, 50]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, NULL, '% lỗi lặp lại (cùng GV, cùng loại, trong 60 ngày sau xử lý) trên số lỗi đã xử lý, cuốn chiếu 3 tháng: 0% đủ điểm, ≤ 10% 75%, ≤ 25% 50%, trên 25% là 0. Không có lỗi nào để xét → Không phát sinh.', 7),
    ('academic_lead', 'Vận hành học thuật', NULL, 'Task đúng hạn (task thường)', 7, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, 'task_on_time', 'Việc được giao (Giao việc) có hạn trong tháng: đúng hạn = 1; trễ ≤ 4 giờ = 0,5; 4–24 giờ = 0,25; quá 24 giờ = 0. % → Bảng A. Tháng ít hơn 3 việc thì gộp thêm tháng trước.', 8),
    ('academic_lead', 'Vận hành học thuật', NULL, 'Order học thuật & phân bổ chương trình/học liệu đúng SLA', 13, 'rate', 'phan_tram', '[[95, 100], [90, 90], [80, 75], [70, 60], [60, 30]]', false, NULL, false, false, true, NULL, NULL, NULL, NULL, 'academic_order_on_time', 'Order "Học liệu học thuật" có ngày sử dụng trong tháng, theo thời gian còn lại trước giờ dùng (giờ bắt đầu buổi học của lớp): ≥ 24 giờ = 1; 12–24 giờ = 0,5; 6–12 giờ = 0,25; dưới 6 giờ = 0. Order tạo sát hơn 24 giờ trước giờ dùng là lỗi bên yêu cầu, không tính. Có order xong sau giờ dùng → mục này tối đa 50% và tháng không xếp loại A.', 9),
    ('academic_lead', 'Kỷ luật & phối hợp', NULL, 'Đi muộn (quá 10 phút)', 4, 'count', 'lan', '[[0, 100], [1, 70], [2, 40], [3, 15]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, 'staff_late', 'Số ngày chấm công đi muộn quá 10 phút (không có đơn được duyệt): 0 lần đủ điểm, 1 lần 70%, 2 lần 40%, 3 lần 15%, từ 4 lần là 0. Phạt tiền 30.000đ/lần tính riêng qua biên bản.', 10),
    ('academic_lead', 'Kỷ luật & phối hợp', NULL, 'Nghỉ không phép', 2, 'count', 'ngay', '[[0, 100]]', false, NULL, false, true, false, NULL, NULL, NULL, NULL, NULL, 'Số ngày nghỉ không có đơn được duyệt. Có từ 1 ngày: mất toàn bộ KPI tháng (như GV) và phạt theo quy chế riêng.', 11),
    ('academic_lead', 'Kỷ luật & phối hợp', NULL, 'Họp bắt buộc & phối hợp', 4, 'count', 'buoi', '[[0, 100], [1, 60], [2, 20]]', false, NULL, false, false, false, NULL, NULL, NULL, NULL, NULL, 'Số buổi họp bắt buộc vắng không phép trong tháng: 0 đủ điểm, 1 buổi 60%, 2 buổi 20%, từ 3 buổi là 0. Đi muộn họp tính ở mục đi muộn.', 12);

-- +goose Down
DROP TABLE hr.kpi_items;
DROP TABLE hr.kpi_sheets;
DROP TABLE hr.kpi_criteria;
