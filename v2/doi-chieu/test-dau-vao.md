# Đối chiếu: Test đầu vào & học thử (placement)

Module v2: `v2/api/internal/placement` (schema `placement` + bảng `crm.trial_bookings`, migration `00090_placement.sql`),
giao diện `v2/web/src/features/placement`.

Laravel đã đối chiếu: `PlacementTestController`, `TrialGuestController`, `ClassManagementController::trialBooking`,
phần học thử / test của `CrmController`, model `PlacementTest`, `PlacementTestSubmission`, `CrmTrialBooking`,
service `PlacementRubricService`, `PlacementPortalLinkService`, `PlacementSubmissionLinker`, `PlacementPdfAnswerSheet`,
trang Vue `PlacementTests/*`, `PlacementTests/Portal/*`, `TrialGuests/Index`, `Classes/TrialBooking`,
`Components/PlacementTests/RubricScoreFields`, view `placement-tests/portal-scorecard.blade.php`.

## Thay đổi so với Laravel

- **Link làm bài dùng token, không dùng mã đề nữa.** Laravel cho làm bài qua `/portal/placement-test/{mã đề}`: ai biết mã đề
  là làm được. Link riêng của khách thì kèm `lead_token` ký HMAC. v2 thay bằng **link mời**:
  - Token ngẫu nhiên 32 byte; DB chỉ lưu SHA-256 của token.
  - Link có hạn dùng: link riêng của khách 7 ngày (như `LINK_TTL_DAYS`), link chung mặc định 30 ngày, tối đa 90 ngày.
  - Giới hạn số lần nộp tùy chọn. Thu hồi được.
  - Ẩn đề thì mọi link của đề ngừng hoạt động.
  - Đang làm dở vẫn nộp được trong (thời gian làm bài + 60 phút) sau khi link hết hạn.
  - Đường dẫn: `/lam-bai/<token>`.
  - API công khai: `GET /api/v1/public/placement/{token}` và `POST /api/v1/public/placement/{token}/submit`. Giới hạn
    30 và 10 request/phút/IP (như `throttle:30,1` / `throttle:10,1`). Route này đã được thêm vào `publicRoutes`.
  - **Link Laravel cũ theo mã đề sẽ không mở được nữa.**
- **Bài làm chỉ gắn với khách CRM** khi link là link riêng của khách, hoặc khi SĐT khớp khách chưa chốt. v2 không tin
  `lead_id` do trình duyệt gửi lên, giống Laravel.
- **Thang điểm** giữ nguyên số liệu của `PlacementRubricService::RUBRICS`. Dữ liệu được sinh ra
  `internal/placement/rubric.json` và nhúng vào binary, không lưu DB, vì Laravel cũng để thang điểm trong code.
- **Phiếu điểm (scorecard)** là màn nội bộ cần đăng nhập và quyền
  `placement_test.view` / `placement_test.grade` / `entrance_test.view`. Laravel dùng URL ký công khai để gửi cho phụ huynh.
- **Phạm vi xem bài làm** giống `CrmCustomer::visibleTo`:
  - Bài gắn với khách CRM chỉ hiện với người thấy khách đó theo phạm vi lead (own / branch / all).
  - Bài chưa gắn khách thì ai có quyền module đều xem được.
- **"(AI)" chỉ là nhãn trên menu.** Laravel không gọi dịch vụ AI nào trong module này (không có API key, không có
  request ra ngoài), nên không có gì để chuyển. Nhãn menu được giữ nguyên.

## Bảng đối chiếu

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
|---|---|---|---|
| `placement-tests.index` — `index` | `GET /placement-tests`, `GET /placement-submissions`; màn `/placement-tests` | Đã làm | Có lọc tên/mã, cấp độ, trạng thái; thẻ số liệu; phân trang; bảng bài làm gần đây (50 bài, theo phạm vi khách). Nút "Mở link làm bài theo mã" thay bằng "Phát hành" / "Tạo link". |
| `placement-tests.toggle-active` — `toggleActive` | `POST /placement-tests/{id}/toggle-active` | Đã làm | |
| `placement-tests.create` — `create` | `GET /placement-tests/options`; màn `/placement-tests/new` | Làm một phần | Chỉ có cách "Soạn từng câu". Chưa có cách "Tải đề PDF" vì v2 chưa có kho file. Mã đề gợi ý theo khối như Laravel. |
| `placement-tests.store` — `storeTest` | `POST /placement-tests` | Đã làm | Kiểm tra và thông báo lỗi giống Laravel, gồm cả quy tắc mã đề phải chứa khối (G3-G4…). "Lưu nháp" thì đề ở trạng thái ẩn. |
| `placement-tests.media.store` — `uploadMedia` | — | Chưa làm | Chưa có kho file. Form soạn đề nhận URL file nghe / ảnh để dán vào. |
| `placement-tests.pdf.store` — `uploadPdf` | — | Chưa làm | Chưa có kho file. Đề PDF cũ nhập từ Laravel vẫn làm bài được bằng phiếu trả lời. File đề mở qua `/storage/<pdf_path>`, nên cần phục vụ thư mục file cũ. |
| `placement-tests.pdf.answers` — `parseAnswers` (`PlacementPdfAnswerSheet`) | — | Chưa làm | Đi kèm phần tải PDF. |
| `placement-tests.show` — `showTest` | `GET /placement-tests/{id}`, `GET /placement-tests/{id}/invitations`; màn `/placement-tests/<id>` | Đã làm | Có thêm bảng link mời (hạn, số lượt nộp, thu hồi). Đề PDF chỉ hiện link mở file, chưa có trình xem PDF nhúng. |
| `placement-tests.edit` / `update` — `editTest` / `updateTest` | `PUT /placement-tests/{id}`; màn `/placement-tests/<id>/edit` | Đã làm | Đề mẫu bị khóa sửa (409). Đề PDF cũ sửa được phiếu trả lời và đáp án, nhưng chưa thay được file PDF mới. |
| `placement-tests.duplicate` — `duplicateTest` | `POST /placement-tests/{id}/duplicate` | Đã làm | Mã mới dạng `TEST-CUSTOM-XXXXX`, giống Laravel. |
| `placement-tests.destroy` — `destroyTest` | `DELETE /placement-tests/{id}` | Đã làm | Xóa mềm. Không xóa được đề mẫu hoặc đề đã có bài làm (409). |
| `placement-tests.distribute` — `distributeTest` | `POST /placement-tests/{id}/distribute` | Làm một phần | Kích hoạt đề và tạo link chung (giao diện tự sao chép link). Chưa gửi thông báo cho người phát hành (`TODO(notify)`). |
| `placement-tests.results.show` — `showResult` | `GET /placement-submissions/{id}`; màn `/placement-tests/submissions/<id>` | Đã làm | Form chấm theo thang khối (`RubricScoreFields`). Nhận xét gợi ý chạy theo điểm cho tới khi người chấm sửa tay. Có nhật ký rời bài và đối chiếu từng câu, lọc đúng / sai / kỹ năng. |
| `placement-tests.results.update` — `updateResult` | `POST /placement-submissions/{id}/grade` | Đã làm | Có "Lưu bản nháp" và "Xác nhận kết quả". Bài đã chấm không lùi về nháp. Khi xác nhận thì ghi nhật ký vào khách và chuyển khách sang "Đã test" qua interface `Leads`, nhưng **phần này chỉ chạy được sau khi gộp module CRM** (xem mục Cần khi gộp). |
| `placement-tests.rubric-guide` — `rubricGuide` | `GET /placement-tests/rubric`; màn `/placement-tests/rubric-guide` | Đã làm | Có công cụ tính thử và bảng băng điểm / xếp lớp của từng khối. |
| `portal.test.take` — `portalTakeTest` | `GET /public/placement/{token}`; màn `/lam-bai/<token>` | Đã làm | Khóa màn hình: toàn màn hình; ghi lại khi ẩn tab / mất focus / thoát toàn màn hình (gộp các sự kiện trong 1,5 giây); 3 lần thì tự nộp. Chặn chuột phải, sao chép / dán, kéo thả, bôi đen, phím tắt, nút quay lại và tải lại. Link riêng thì điền sẵn thông tin khách và chuyển khách sang "Test". Trang trả về không chứa đáp án. |
| `GET portal/placement-test/{code}/submit` | — | Đã làm | Không cần trong SPA (Laravel chỉ dùng để chuyển hướng). |
| `portal.test.submit` — `portalSubmitTest` | `POST /public/placement/{token}/submit` | Đã làm | Tự chấm Nghe và Đọc & Viết theo thang khối, kèm nhận xét gợi ý; bài vẫn ở trạng thái "Chờ chấm". Lưu nhật ký rời bài (tối đa 50 dòng) và cờ tự nộp. |
| `portal.test.done` — `portalDone` | Màn cảm ơn trong `/lam-bai/<token>` | Đã làm | |
| `portal.test.scorecard` / `portal.test.results` — `portalScorecard` (URL ký) | `GET /placement-submissions/{id}/scorecard`; màn `/placement-tests/submissions/<id>/scorecard` (in được) | Làm một phần | Màn nội bộ, cần đăng nhập. Chưa có link ký công khai để gửi phụ huynh. Chưa có hotline / danh sách cơ sở (`CenterInfo`) ở đầu phiếu và khối "Mục tiêu học tiếng Anh". |
| `crm.customers.schedule-test` — `CrmController::schedulePlacementTest` | Service `LeadLinkFor` (tạo link riêng của khách) | Chưa làm | Màn hồ sơ khách thuộc module CRM. Chưa gửi xác nhận lịch test (`AppointmentConfirmation`). |
| `crm.customers.test-no-show` — `testNoShow` | — | Chưa làm | Thuộc CRM. |
| `crm.customers.save-test-score` — `saveTestScore` | — | Chưa làm | Thuộc CRM. Có thể dùng lại component `RubricScoreFields` và cách tính điểm của v2. |
| `crm.customers.link-submission` — `linkSubmission` (`PlacementSubmissionLinker`) | — | Chưa làm | Gắn bài nộp chưa khớp vào khách. Cần màn CRM. |
| `crm.customers.trial-bookings.store` — `storeTrialBooking` | `POST /trial-bookings` | Làm một phần | API đủ quy tắc của Laravel: tối đa 2 lượt; xếp buổi sau khi buổi trước kết thúc; buổi trong 14 ngày tới; cùng chi nhánh; khách đang tư vấn; không trùng buổi. Ghi nhật ký khách. **Chưa có nút đặt lịch trên hồ sơ khách và chưa có danh sách buổi trống (TrialSlotFinder)**: cần module CRM và lớp học. |
| `crm.customers.trial-bookings.cancel` — `cancelTrialBooking` | `POST /trial-bookings/{id}/cancel`; nút "Hủy" ở Lịch học thử | Đã làm | Bắt buộc lý do; lý do được nối vào ghi chú "Hủy: …". |
| (hồ sơ khách: danh sách học thử, số lượt còn lại) | `GET /trial-bookings/lead/{leadId}` | Làm một phần | Có API; màn hồ sơ khách thuộc CRM. |
| `classes.trial-booking` — `ClassManagementController::trialBooking` | `GET /trial-bookings`; màn `/trial-bookings` "Lịch học thử" | Đã làm | Có tab "Hôm nay & sắp tới" / "Đã diễn ra", lọc trạng thái, phân trang, theo phạm vi khách. Tên khách / lớp lấy từ module CRM / lớp học sau khi gộp. |
| `teacher.trial-guests` — `TrialGuestController::index` | `GET /trial-bookings/teacher`; màn `/teacher/trial-guests` "Khách học thử" | Đã làm | Giáo viên chỉ thấy buổi mình dạy / lớp mình phụ trách (cần `Sessions.TeachingScope` sau khi gộp). Người có phạm vi lead "toàn hệ thống" thấy tất cả. |
| `teacher.trial-guests.feedback` — `TrialGuestController::feedback` | `POST /trial-bookings/{id}/feedback` | Đã làm | Có điểm danh có mặt / vắng, đánh giá 1–5, 3 đầu mục nhận xét, nhận xét chi tiết. Chỉ mở từ ngày học. Ghi nhật ký vào khách. Chưa gửi thông báo cho Học vụ (`TODO(notify)`). |
| `Components/Teacher/SessionTrialGuests.vue` (khách học thử trong màn điểm danh buổi dạy) | — | Chưa làm | Thuộc màn điểm danh của Cổng giáo viên; dữ liệu lấy được từ `GET /trial-bookings/teacher`. |
| Đề mẫu hệ thống (`is_preset`, seeder) | Cột `is_preset`, khóa sửa / xóa | Làm một phần | v2 không tự tạo đề mẫu; đề mẫu sẽ được đưa sang khi nhập dữ liệu cũ. |
| Thông báo: `notifyUser` (phát hành), `notifyHomeworkOrTestSubmission`, `notifyTrialBooked`, `notifyTrialFeedback`, `AppointmentConfirmation::forTrial` | `// TODO(notify)` trong code | Chưa làm | Chờ module thông báo. |
| Sinh đề bằng AI | — | Đã làm | Laravel không có tính năng này; "(AI)" chỉ là nhãn menu, đã giữ nguyên. |

## Cần khi gộp (liên module)

- **CRM (`crm.leads`)**: hiện thực interface `placement.Leads`, rồi truyền vào `placement.Options{Leads: …}` trong
  `cmd/menglish/main.go`. Interface gồm:
  - `VisibleIDs`: phạm vi lead own / branch.
  - `Get`, `Lookup`: thông tin khách.
  - `MatchByPhone`: khớp khách chưa chốt theo SĐT khách hoặc SĐT phụ huynh.
  - `Record`: ghi lịch sử, chuyển giai đoạn khách chỉ theo chiều tiến (testing → tested), ghi `test_score`.

  Hiện đang dùng `NoLeads`. Hệ quả là chưa thấy bài gắn khách (trừ người có `lead.scope_all`) và chưa đặt được học thử.
  Thêm FK `placement.invitations.lead_id`, `placement.submissions.lead_id`, `crm.trial_bookings.lead_id` → `crm.leads`
  (đã có comment `-- FK tới … thêm khi gộp`).
- **Lớp học (`academic.classes` / buổi học)**: đã gắn — `placement.Options{Sessions: cls.PlacementSessions()}`:
  - `Get`, `Lookup`: buổi học (lớp chưa xóa), tên lớp, khóa / chương trình, chi nhánh, giờ, giáo viên.
  - `TeachingScope`: buổi tôi dạy / dạy GVNN / trợ giảng (kể cả dạy thay) và lớp tôi phụ trách.

  FK `crm.trial_bookings.class_id` / `class_session_id`, `placement.submissions.student_id`: `00110_academic_links_fk`
  (xóa buổi xóa luôn lịch hẹn như Laravel). Nghỉ lễ hủy buổi thì phân hệ Lớp học hủy lịch hẹn học thử của buổi đó;
  xếp lại TKB không xóa buổi đã có khách hẹn. Màn đặt học thử vẫn cần danh sách buổi trống (TrialSlotFinder).
- **Kho file**: tải đề PDF, file nghe, ảnh câu hỏi; phân tích đáp án từ PDF.
- **Thông báo / audit**: các chỗ `TODO(notify)`.
- `internal/iam/iamdb/models.go` được sqlc sinh lại vì có thêm migration. Khi gộp nhiều nhánh thì chạy lại `sqlc generate`.
