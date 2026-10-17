# Đối chiếu: Cổng Phụ huynh & Học sinh (cổng học viên)

Nguồn Laravel: `app/Http/Controllers/StudentPortalController.php`, `app/Support/Portal/PortalNotifications.php`,
`app/Services/Tuition/PaymentReportService.php`, `app/Services/Tuition/Approvals/PaymentReportApprovalSource.php`,
`app/Support/TuitionPaymentHistory.php` (bản học viên xem), `app/Support/Navigation/AppShell.php` / `SidebarMenu.php`
(workspace `student_portal`, khung riêng cho `User::isPortalStudentOnly`), `DashboardController` (chuyển tài khoản cổng
sang Trang chủ cổng), route `portal.*` trong `routes/web.php`, màn `resources/js/Pages/Portal/*`.
Đối chiếu tại commit `838936c9` (file Laravel giống `c7f48b4e`).

v2: migration `00215_portal.sql` (`finance.payment_reports`, `academic.pronunciation_records`, `academic.stage_feedbacks`),
Go `v2/api/internal/portal` (+ `students.Service.Visible`, `tuition.Service.PortalTuition`), web `v2/web/src/features/portal`.
Route `/api/v1/portal/*` bọc `iam.RequireAny("portal.student", "student.view")`; xác nhận báo đóng học phí
`/api/v1/payment-reports/*` bọc `tuition.view` + `tuition.approve`. Màn "Chấm bài nộp của lớp" là của phân hệ Cổng giáo viên
(`/api/v1/teacher/submissions`, xem `cong-giao-vien.md`); hai phân hệ nối nhau ở `cmd/menglish/portal_teacher.go`.

Người xem như `getActiveStudent` / `authorizeStudent`: tài khoản học viên (`academic.students.user_id`) hoặc phụ huynh
(`academic.student_guardians`) thấy học viên gắn với mình (hồ sơ Chờ khai giảng / Đang học / Nghỉ hè); nhân sự có
`student.view` xem hộ học viên trong phạm vi `student.scope_*`, sửa hộ cần thêm `student.update`. Ngoài phạm vi → 403
"Bạn không được phép truy cập hồ sơ học viên này.".

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

## Màn và route

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `portal.student.home` GET `/portal/home/{studentId?}` (+ `/portal/student/home`) | `GET /portal/home?student_id=` · màn `/portal/home` | Làm một phần | Lời chào, lịch học 14 ngày tới (buổi chính khóa / học bù của các lớp đang học, phòng "Phòng …", buổi nghỉ gạch ngang), lối vào Nộp bài / Phát âm, tiến độ (chuyên cần đã duyệt, bài tập, Big Test mới nhất), điểm danh gần đây, Big Test, học phí (đã đóng / còn nợ / dự kiến khóa tới), thông tin học sinh. Số bài GV giao / lượt nộp đọc từ Cổng giáo viên. Big Test đã duyệt / đã gửi đọc từ Giáo trình (`portal.Syllabus`). **Thiếu**: buổi phụ đạo riêng (`support_sessions`) — buổi phụ đạo chưa có ở v2. Học viên chọn bằng `?student_id=` (uuid) thay cho đoạn `/{studentId}` số tự tăng; URL `/portal/student/*` cũ không giữ. |
| Hộp "Lịch sử thu học phí" (`TuitionPaymentHistory::forStudent`) | `GET /portal/tuition?student_id=` · hộp `StudentPaymentsDialog` (prop `load`) | Đã làm | Qua `tuition.Service.PortalTuition()`: tên khoản "Học phí <khóa / lớp>", không có số hóa đơn, chi tiết phụ thu, người lập / duyệt, ghi chú nội bộ, phiếu chưa duyệt (như bản `forStaff = false`). |
| `portal.student.profile.update` POST | `PATCH /portal/students/{id}/contact` · hộp "Cập nhật thông tin học viên" | Đã làm | SĐT bắt buộc ≤ 20, địa chỉ ≤ 255, ghi chú ≤ 500; ghi `student_events` + nhật ký `portal.profile_update` (có diff). |
| `portal.student.tuition.request` POST (`submitTuitionRequest`) | `POST /portal/payment-reports` · hộp "Báo đóng học phí" | Khác | Số tiền ≥ 1.000, nội dung bắt buộc ≤ 500, mã `YCHOCPHI-XXXXXX`. Lưu bảng riêng `finance.payment_reports` thay cho `academic_records`; trang chủ thêm danh sách "Yêu cầu báo đóng đã gửi" kèm trạng thái / lý do từ chối (Laravel chỉ báo qua hộp thư). Nhật ký `portal.payment_report` (module Học phí). |
| Cần duyệt › "Học viên báo đã đóng học phí" (`PaymentReportApprovalSource`) | `portal.reportSource` trong hộp `/approvals` (nhóm Học phí) | Đã làm | Quyền `tuition.view` + `tuition.approve`, phạm vi chi nhánh Học phí (học viên chưa gán chi nhánh thì theo chi nhánh lớp), khóa dòng khi xử lý; từ chối bắt buộc lý do. Học viên nhận thông báo `tuition_payment_report` / `tuition_payment_report_rejected` đúng tiêu đề / nội dung của `PaymentReportService`. Link của mục mở "Lập phiếu thu" (`/tuition/receipts/new?tuition_id=`). Màn tạm `/payment-reports` và API `/payment-reports*` bỏ, URL cũ chuyển hướng sang `/approvals?group=hoc-phi`. |
| `portal.student.homework` GET (+ `/portal/student/homework`) | `GET /portal/homework?student_id=` · màn `/portal/student-homework` | Khác | Khung 6 hạng mục (nhãn, biểu tượng, nút như Laravel, tô nổi mục chưa nộp đầu tiên khi bài GV giao sắp tới hạn), nhận xét buổi học, bảng điểm (mini test + Big Test), nút "Cổng GV: Xem bài nộp của lớp" (→ `/teacher/submissions`). Bài nộp (mới nhất của từng hạng mục, điểm + nhận xét GV), bài GV giao mới nhất (hạn nộp `due_date` mới nhất), 3 nhận xét buổi học đã lưu chính thức gần nhất (Monsters "Nhóm … · Thưởng …" như `normalizeRemark`), 10 điểm mini test đọc từ phân hệ Cổng giáo viên qua `portal.TeacherPortal`. Big Test của bảng điểm đọc từ Giáo trình. Bỏ khối "Lộ trình học tập" (số liệu mẫu cố định ở Laravel). |
| `portal.student.homework.submit` / `.update` / `.destroy` | `POST /portal/homework` (multipart `student_id`, `homework_type`, `notes`, `attachment`), `POST /portal/homework/{id}`, `DELETE /portal/homework/{id}` | Đã làm | Kiểm loại bài ("Loại bài tập không hợp lệ."), file ≤ 100 MB (ảnh / tài liệu / video / âm thanh, nhận theo nội dung), ghi chú mặc định như Laravel, quyền ghi như `authorizeStudent`, bài nộp + file lưu ở phân hệ Cổng giáo viên (`academic.homework_submissions`, kho file `homework_submission`) trong một transaction, có nhật ký. Như Laravel bài đã chấm vẫn sửa / nộp lại / hủy được (giữ điểm). File bài nộp mở được bởi chủ hồ sơ, giáo viên chấm lớp, nhân sự xem được học viên; học viên khác → 403. |
| `portal.student.pronunciation` GET | `GET /portal/pronunciation?student_id=` · màn `/portal/pronunciation` | Đã làm | Ghi âm bằng micro (MediaRecorder: webm / Safari mp4→m4a), đồng hồ, nghe lại, nộp; lịch sử bài đã nộp (chờ chấm / điểm + nhận xét GV). **Bài nghe của lớp**: 20 file nghe GV đính kèm bài tập gần nhất của các lớp đang học (Cổng giáo viên), học viên / phụ huynh của lớp mở được (Laravel để công khai trong `/storage`). |
| `portal.student.pronunciation.store` / `.destroy` | `POST /portal/pronunciation` (multipart `student_id`, `unit_title`, `duration`, `audio`), `DELETE /portal/pronunciation/{id}` | Đã làm | "Chưa có bản ghi âm. Hãy chạm micro để thu âm trước khi nộp.", bài đang luyện bắt buộc ≤ 255, thời lượng ≤ 20; file ≤ 50 MB ở kho file (`pronunciation_record`: chủ hồ sơ, GV phụ trách lớp, nhân sự xem được học viên mới tải được). Bảng riêng `academic.pronunciation_records` (mã `AUDIO-XXXXXX`). Xóa: xóa bản ghi + xóa mềm file. |
| "Luyện phát âm AI" — chấm tự động | — | Khác | Laravel **không gọi dịch vụ AI nào**: bản ghi âm chờ giáo viên chấm ("AI" chỉ là nhãn menu). v2 giữ nguyên: không có cấu hình / khóa dịch vụ ngoài. |
| `portal.student.notifications` GET + `.read` / `.read-single` / `.destroy` | `GET /portal/notifications?student_id=`, `POST /portal/notifications/read-all`, `POST /portal/notifications/{id}/read`, `DELETE /portal/notifications/{id}?student_id=` · màn `/portal/notifications` | Khác | Dùng chung `core.notifications` (`notify.Send`) thay cho `academic_records` màn 05: hộp thư gồm thông báo gửi tài khoản học viên và thông báo gửi người xem (phụ huynh) có `data.student_id` trống hoặc là học viên đang xem. Biểu tượng / màu theo loại (`notify.Info`). Thông báo không thuộc hộp thư → 404. Bấm thông báo có đường dẫn thì mở luôn. |
| `PortalNotifications::unreadCountForUser` (chuông topbar của tài khoản cổng) | `GET /portal/summary` · `PortalBell` trên topbar | Đã làm | Số chưa đọc của mọi học viên gắn với tài khoản, bấm mở Hộp thư thông báo. |
| `portal.student.survey` GET + `.store` / `.destroy` | `GET /portal/surveys?student_id=`, `POST /portal/surveys`, `DELETE /portal/surveys/{id}` · màn `/portal/survey` | Đã làm | Giao diện đợt đang mở (hạn "Hôm nay" / ngày / "Chưa đặt hạn", tô đỏ khi gấp), hộp phản hồi 1–5 sao (mặc định 5) + ý kiến ≥ 5 ký tự, khảo sát đã gửi (xóa để làm lại). Đợt khảo sát và phản hồi thuộc phân hệ Khảo sát (`portal.Surveys` ← `portalSurveys`); API trùng `/surveys/portal` của phân hệ Khảo sát bỏ. Gửi theo `survey_id` thay cho tên khảo sát. |
| `portal.student.feedback` GET + `.store` / `.destroy` | `GET /portal/feedback?student_id=`, `POST /portal/feedback`, `DELETE /portal/feedback/{id}` · màn `/portal/feedback` | Khác | Mức hài lòng 1–5 sao, lĩnh vực (Học thuật / Giáo viên / Khác), nội dung ≤ 5000; R-04 "Vui lòng điền ít nhất 1 mục …" kiểm cả trình duyệt lẫn máy chủ; một bản mỗi học viên mỗi chặng (`UNIQUE (student_id, stage_name)`), gửi lại thì cập nhật. Chặng = chặng đang học của lớp qua `portal.Syllabus.CurrentStage` (chặng đang mở của lớp ở Giáo trình), chưa có thì "Góp ý chung". Bảng riêng `academic.stage_feedbacks`; thêm danh sách các chặng đã góp ý. Không làm hai giao diện mẫu "đợt đã đóng" / "chưa mở đợt" (Laravel không bao giờ hiện). |
| `portal.app-shell` GET | `GET /portal/app-shell?student_id=` · màn `/portal/app-shell` | Đã làm | Lưới 6 chức năng kèm số liệu thật (tổng học phí, số hạng mục đã nộp, số bản ghi âm, thông báo chưa đọc, khảo sát đã làm, đã gửi feedback), thông tin tài khoản, lối sang "Tài khoản & đổi mật khẩu". |
| `portal.teacher.submissions` GET (+ `/teacher/submissions/{classId?}`) | Một màn chung với Cổng giáo viên: `GET /teacher/submissions?class_id=&type=` · màn `/teacher/submissions` (Cổng Giáo viên › Chấm bài nộp của lớp); `/portal/teacher-submissions` cũ tự chuyển sang | Đã làm | Màn và API thuộc phân hệ Cổng giáo viên (bản `/portal/teacher/*` đã bỏ khi gộp). Lớp mình dạy / trợ giảng / GVNN (phạm vi Lớp học "Toàn hệ thống" → mọi lớp, bỏ lớp đã hủy), lớp ngoài danh sách → 403 "Bạn không phụ trách lớp này.", 7 tab, danh sách lớp gồm học viên liên kết. Tab **Phát âm** đọc bản ghi âm của phân hệ này (`portal.Service.ClassPronunciations`). Giao diện như `Portal/TeacherSubmissions.vue`: chấm ngay trên dòng, "Đánh dấu đã xem", bài đã chấm chỉ hiện "Đã chấm: …" / "Đã xem". |
| `portal.teacher.submissions.mark` POST | `POST /teacher/submissions/{id}/mark` (bài nộp hoặc bản ghi âm) | Khác | Bài nộp: điểm tùy chọn ≤ 20, nhận xét ≤ 1000. Phát âm: điểm bắt buộc, chấm qua `portal.Service.MarkPronunciation` (nhật ký có diff); **thêm** thông báo `pronunciation_reviewed` "Bài luyện phát âm đã được chấm" vào hộp thư học viên (Laravel không báo). Học viên ngoài lớp phụ trách → 404. |

## Khung giao diện cho tài khoản cổng (`isPortalStudentOnly`)

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `DashboardController`: tài khoản cổng vào "/" → `portal.student.home` | `routes.tsx` (`home()`): `portal.student` mà không có `portal.staff` → Trang chủ cổng | Đã làm | Trước đây v2 mở "Tài khoản của tôi". Nhân sự vẫn thấy Tổng quan. |
| Sidebar: workspace "Cổng Phụ huynh & Học sinh" (7 mục, `can: portal.student`) | `nav.ts`: 7 mục nhóm `Cổng Phụ huynh & Học sinh`, `perms` `portal.student` / `student.view`, menu chỉ `portal.student` | Đã làm | Nhân sự có `student.view` mở được để xem hộ (ô "Xem hộ học viên" tìm theo mã / tên / SĐT trong phạm vi) nhưng không có trên menu, như Laravel. |
| Sidebar ghi "Cổng học viên" thay "Hệ thống quản trị"; không có Tổng quan | `Sidebar.tsx`, mục "/" chỉ cho nhân sự | Đã làm | |
| Không có ô tìm kiếm chung | `Topbar` `search={false}`, tắt Ctrl K | Đã làm | |
| Chuông thông báo dẫn tới hộp thư cổng | `PortalBell` | Đã làm | |
| Thanh điều hướng đáy trên điện thoại (`PortalBottomNav`), thanh trên đổi học viên (`PortalTopHeader`) | `PortalFrame` | Đã làm | Đổi học viên giữ nguyên màn, chỉ đổi `?student_id=`. |

## Dữ liệu, thông báo, nhật ký

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `academic_records` (screen_key màn 02 / 04 / 05 / 06 / 07, JSON `data`) | `finance.payment_reports`, `academic.pronunciation_records`, `academic.stage_feedbacks`, `core.notifications` | Khác | Bảng có cột và CHECK riêng thay cho bản ghi JSON chung. Bài nộp / khảo sát nằm ở bảng của phân hệ Cổng giáo viên / Khảo sát (không tạo ở đây). Chưa nhập dữ liệu cũ từ `academic_records`. |
| Nhật ký hoạt động (`LogsActivity`) | `audit.Record` cho sửa liên hệ, báo đóng, xác nhận / từ chối, nộp / xóa bản ghi âm, chấm, nộp / sửa / hủy bài, góp ý, khảo sát | Đã làm | `/portal` ghi vào module Học viên; báo đóng học phí ghi module Học phí. |

## Nối với Cổng giáo viên (đã gộp)

`cmd/menglish/portal_teacher.go`: `portal.TeacherPortal` bọc `teacher.StudentPortal` (bài GV giao, bài nộp nộp / sửa / hủy,
nhận xét buổi học, mini test, file nghe bài tập, số bài tập ở trang chủ / App Shell); `teacher.Pronunciation` bọc bản ghi âm
của cổng học viên (tab Phát âm, chấm → thông báo); `teacher.FileAccess` cho học viên / phụ huynh mở file bài nộp / file nghe.
Test tích hợp: `cmd/menglish/portal_teacher_test.go`.

## Nối với phân hệ khác (interface trong `internal/portal/sources.go`, adapter ở `cmd/menglish/wiring.go`)

- **Khảo sát** (`portal.Surveys` ← `portalSurveys`): đợt khảo sát đang mở, phản hồi.
- **Giáo trình & Big Test** (`portal.Syllabus` ← `portalSyllabus`): kết quả Big Test đã duyệt / đã gửi, chặng đang học của lớp.
- **Còn thiếu — buổi phụ đạo**: buổi phụ đạo riêng ở lịch học sắp tới (`support_sessions`) chưa có ở v2.
