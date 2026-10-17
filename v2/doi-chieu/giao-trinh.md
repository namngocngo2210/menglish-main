# Đối chiếu: Giáo trình & Big Test

Phần này gồm workspace **Giáo trình** (Tài liệu, Soạn syllabus, Giao chặng, Chặng đang dạy & Order Test, Xem bài giảng),
workspace **Big Test** (Nhắc lịch, Phân phối đề, Bảng điểm & Kết quả), nhóm **Cần duyệt** / **Xin duyệt** (đề xuất sửa giáo
trình, điều chỉnh tiến độ) và màn order đề của Cổng GV (`teacher.order-test*`). Dự án học thuật nằm ngoài phạm vi.

- Laravel: `SyllabusController`, `TeacherPortalController::orderTest / submitOrderTest`, `SyllabusProgressionService`,
  `ScheduleExtensionService`, `AdjustmentSlaService`, `BigTestSlaService`, `SessionLessonService`, lệnh
  `bigtests:remind-upcoming`, `bigtests:enforce-sla`, `syllabus:notify-adjustment-sla`, các trang Vue `Syllabus/*`,
  `Teacher/OrderTest.vue`.
- v2: package `v2/api/internal/syllabus` (+ `classes/extension.go` cho giãn tiến độ), migration
  `00200_syllabus_curricula.sql`, `00201_syllabus_assignments_bigtests.sql` (schema `syllabus`), màn
  `v2/web/src/features/syllabus`.
- Quyền: giữ nguyên tên quyền của Laravel (`syllabus.view / upload / manage / update / propose_adjustment /
  approve_adjustment`, `big_test.approve`). Nút duyệt / từ chối (Laravel thêm `AdminOnlyApprovals::ABILITY`) chỉ Admin
  (`SuperAdmin`), service kiểm và trả 403.
- File (tài liệu, đính kèm đề xuất) lưu qua `internal/files` (`files.Save`, đọc bằng `/files/{id}` với `RegisterReader`
  kiểm quyền xem), không có route tải riêng của phân hệ.

## Tài liệu & Soạn syllabus

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `syllabus.documents` GET `/syllabus/documents` (`documents`) + `syllabus.index` GET `/syllabus` | `GET /api/v1/syllabus/documents` · màn `/syllabus/documents` (và `/syllabus` mở cùng màn) | Đã làm | Lọc cấp độ / loại / tìm kiếm, phân trang, đánh dấu đã xem theo người dùng, thẻ thống kê. |
| `syllabus.documents.store` POST (`storeDocument`) | `POST /syllabus/documents` (multipart) · hộp thoại "Tải tài liệu" | Đã làm | Giới hạn 100 MB; chọn giáo trình / chặng, đối tượng xem (GV, trợ giảng), "Cho phép tải về". Lưu qua kho file. |
| `syllabus.documents.file` GET `/documents/{id}/file` (`documentFile`) | `GET /api/v1/files/{id}` (kho file, reader `syllabus_document`) | Khác | Không có route riêng; quyền xem do reader của phân hệ kiểm (`syllabus.view` + đúng đối tượng xem; Word / PowerPoint / Excel không xem trực tuyến được thì chỉ tải khi tài liệu cho tải). |
| `syllabus.documents.viewed` POST (`markDocumentViewed`) | `POST /syllabus/documents/{id}/viewed` | Đã làm | |
| `syllabus.documents.destroy` DELETE (`destroyDocument`) | `DELETE /syllabus/documents/{id}` | Đã làm | Xóa file ở kho file trong cùng giao dịch. |
| `syllabus.builder` GET (`builder`) | `GET /syllabus/builder?curriculum=` · màn `/syllabus/builder` | Đã làm | Cây giáo trình → chặng → unit → bài theo buổi, tổng số buổi / bài, mở sửa bài bằng `?edit_lesson=`. |
| `syllabus.curriculums.store / update / destroy` | `POST / PUT / DELETE /syllabus/curricula[/{id}]` | Đã làm | Mã không trùng, gắn khóa học / trình độ; giáo trình đang được lớp học thì không xóa được. |
| `syllabus.stages.store / update / destroy` | `POST / PUT / DELETE /syllabus/stages[/{id}]` | Đã làm | Link tổng quan, Big Test cuối chặng; giáo trình phải còn ít nhất 1 chặng, chặng còn unit hoặc đã giao lớp / gắn Big Test thì không xóa được. |
| `syllabus.stages.move` POST | `POST /syllabus/stages/{id}/move` | Đã làm | Lên / xuống, đánh lại thứ tự. |
| `syllabus.units.store / update / destroy` | `POST / PUT / DELETE /syllabus/units[/{id}]` | Đã làm | |
| `syllabus.lessons.store / update / destroy` | `POST / PUT / DELETE /syllabus/lessons[/{id}]` | Đã làm | Số buổi đánh liên tục trong cả giáo trình, không trùng; unit phải thuộc giáo trình. |

## Giao chặng, chặng đang dạy, xem bài giảng

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `syllabus.assignments` GET (`assignments`) | `GET /syllabus/assignments` · màn `/syllabus/assignments` | Đã làm | Lớp trong phạm vi xem (`Classes.VisibleClassIDs` do phân hệ Lớp học cài), chặng đang mở / đã đóng, tiến độ buổi. |
| `syllabus.assignments.store` POST (`storeAssignment`) | `POST /syllabus/assignments` | Đã làm | Mở chặng cho lớp (giáo trình mặc định theo trình độ của lớp); lớp đang có chặng mở thì chuyển chặng, bắt buộc lý do; ngày bắt đầu không quá 60 ngày tới. |
| `syllabus.assignments.update` PUT | `PUT /syllabus/assignments/{id}` | Đã làm | |
| `syllabus.assignments.close` POST (`closeAssignment`) | `POST /syllabus/assignments/{id}/close` | Đã làm | Đóng tay, bắt buộc lý do, quyền `syllabus.approve_adjustment`. Đóng tự động khi kết quả Big Test của chặng được duyệt và gửi PH (`progression.go` = `SyllabusProgressionService`, mở chặng kế tiếp). |
| `syllabus.assignments.expected-date` POST (`updateExpectedBigTestDate`) | `POST /syllabus/assignments/{id}/expected-big-test-date` | Đã làm | GV lớp / người quản lý đặt ngày dự kiến Big Test; dùng cho nhắc lịch. |
| `syllabus.teaching-stages` GET (`teachingStages`) | `GET /syllabus/teaching-stages` · màn `/syllabus/teaching-stages` | Đã làm | Chặng đang dạy theo lớp, buổi đã dạy / còn lại, ngày dự kiến Big Test, trạng thái order đề, nút "Order Test". |
| `teacher.order-test` GET `/teacher/order-test/{classId}` | `GET /api/v1/teacher/order-test/{classId}` · màn `/teacher/order-test/<id>` | Đã làm | Quyền như cổng GV: `attendance_student.record` hoặc `record_any`; lớp cụ thể service kiểm (GV / GVNN / trợ giảng của lớp hoặc người làm thay). |
| `teacher.order-test.submit` POST | `POST /teacher/order-test/{classId}` | Đã làm | Chọn loại đề, ngày thi, ghi chú; lớp phải có chặng mở; mỗi chặng / loại đề chỉ một order chờ duyệt; báo người duyệt. |
| `syllabus.teacher-view` GET (`teacherView`) | `GET /syllabus/teacher-view?class=&document=&tab=` · màn `/syllabus/teacher-view` | Đã làm | Bài giảng theo buổi của lớp, tài liệu đi kèm, xem tài liệu trong hộp thoại có watermark tên người xem; tài liệu không cho tải thì không hiện nút tải. |
| `SessionLessonService` (tên bài theo buổi ở cổng GV / giao việc) | `Service.ClassUnits`, `ClassLessons` (`portal.go`) ← `teacher.Syllabus` (adapter `teacherSyllabus`) | Làm một phần | Giáo trình của lớp = giáo trình của chặng đang mở, không có thì giáo trình của cấp độ; bài buổi N = `session_no` N. Cổng GV dùng cho Unit của mini test và tên bài theo buổi khi giao bài tập. Tên bài trong giao việc trợ giảng (`tasks`) vẫn "Buổi N". |

## Đề xuất sửa giáo trình (Cần duyệt / Xin duyệt)

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `syllabus.versions` GET (`versions`) | `GET /syllabus/versions?proposal=` · màn `/syllabus/versions` (nhóm Cần duyệt) | Đã làm | Danh sách đề xuất, lọc trạng thái, chi tiết bằng `?proposal=`, lịch sử phiên bản. Menu như Laravel: chỉ Admin có `syllabus.approve_adjustment`. |
| `syllabus.teacher-propose` GET (`teacherPropose`) | `GET /syllabus/teacher-propose` · màn `/syllabus/teacher-propose` (nhóm Xin duyệt) | Đã làm | Đề xuất của mình, chọn lớp / bài. |
| `syllabus.proposals.store` POST (`storeProposal`) | `POST /syllabus/proposals` (multipart) | Đã làm | Đính kèm tối đa 20 MB qua kho file; báo người duyệt. |
| `syllabus.proposals.approve / reject` POST | `POST /syllabus/proposals/{id}/approve / reject` | Đã làm | Chỉ Admin; ghi chú duyệt (`review_note`); áp nội dung vào bài khi duyệt; báo người đề xuất. |
| `syllabus.proposals.attachment` GET | `GET /api/v1/files/{id}` (reader `syllabus_proposal`) | Khác | Như tài liệu: đi qua kho file. |
| `ProposalApprovalSource` (hộp duyệt chung) | `proposalSource` (`approvals.go`) | Đã làm | Nhóm Đào tạo; duyệt / từ chối bằng nghiệp vụ của màn Đề xuất sửa giáo trình; link `/syllabus/versions?status=pending`. Xem [can-duyet.md](can-duyet.md). |

## Điều chỉnh (giãn) tiến độ

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `syllabus.teacher-adjust` GET (`teacherAdjust`) | `GET /syllabus/teacher-adjust` · màn `/syllabus/teacher-adjust` (Xin duyệt) | Đã làm | Chỉ chọn được lớp có chặng đang mở; danh sách yêu cầu của mình, hạn SLA. |
| `syllabus.adjustment-requests` GET (`adjustmentRequests`) | `GET /syllabus/adjustment-requests?request=` · màn `/syllabus/adjustment-requests` (Cần duyệt) | Đã làm | Người duyệt xem mọi yêu cầu (mặc định chờ duyệt), người khác chỉ của mình; cờ quá hạn SLA. |
| `syllabus.adjustment-requests.store` POST | `POST /syllabus/adjustment-requests` | Đã làm | Số buổi xin thêm (0 đến mức tối đa), lý do; lớp phải có chặng đang mở; báo người duyệt. |
| `syllabus.adjustment-requests.approve` POST | `POST /syllabus/adjustment-requests/{id}/approve` | Đã làm | Chỉ Admin; số buổi duyệt (`extra_sessions`, tối đa như Laravel). Trong cùng giao dịch: thêm N buổi chính khóa theo ca lặp tuần của lớp (bỏ ngày lễ, chặn trùng phòng / nhân sự), lùi ngày kết thúc lớp, cộng buổi giãn vào chặng; báo GV. Chặng đã đóng / lớp đã xóa thì chỉ duyệt 0 buổi (ghi nhận) hoặc từ chối. |
| `syllabus.adjustment-requests.reject` POST | `POST /syllabus/adjustment-requests/{id}/reject` | Đã làm | Bắt buộc lý do (`rejection_reason`). |
| `ScheduleExtensionService::extend / slotsFor` | `classes.SyllabusClasses.ExtendSessions` (`classes/extension.go`) | Khác | Ca lặp tuần lấy từ bảng ca TKB của lớp ở v2 (Laravel đọc `slot1/slot2` của `class_schedule_configs`, thiếu giờ thì mặc định 18:00–19:30); không có TKB thì suy ra từ buổi chính khóa như Laravel. |
| `AdjustmentApprovalSource` (hộp duyệt chung) | `pacingSource` (`approvals.go`) | Đã làm | Nhóm Đào tạo; duyệt / từ chối như màn Điều chỉnh tiến độ; link `/syllabus/adjustment-requests?status=pending`. |
| `syllabus:notify-adjustment-sla` (mỗi giờ, phút 20) | `Service.NotifyPacingBreaches` trong `RunLoop` | Đã làm | Yêu cầu quá hạn duyệt (SLA `syllabus.adjustment_approval`, mặc định 3 ngày) → báo người duyệt một lần. |

## Big Test

| Laravel (route / method) | v2 (API + màn) | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `syllabus.big-tests.schedules` GET (`bigTestSchedules`) | `GET /syllabus/big-tests/schedules` · màn `/syllabus/big-tests/schedules` | Đã làm | Chặng sắp thi (7 ngày) chưa duyệt / chưa order đề, số ngày còn lại, mức khẩn cấp; đợt thi đã tạo (phòng, giám thị, mật mã), cảnh báo đề. |
| `syllabus.big-tests.remind` POST (`sendBigTestReminder`) | `POST /syllabus/big-tests/{id}/remind` | Khác | Học viên đang học / bảo lưu của lớp nhận thông báo `big_test_reminder` (1 lần / học viên / đợt thi, `notify.SendOnce`) vào tài khoản học viên; Laravel ghi bản ghi danh sách thông báo của Cổng PH/HS (`AcademicRecord`) nên cả học viên chưa có tài khoản cũng có dòng. Người gửi nhận thông báo đã gửi. |
| `syllabus.big-tests.distribution` GET (`bigTestDistribution`) | `GET /syllabus/big-tests/distribution?order_status=&order=` · màn `/syllabus/big-tests/distribution` | Đã làm | Order đề chờ duyệt / tất cả, đợt thi, cảnh báo đề chưa phân phối. |
| `syllabus.big-tests.store` POST (`storeBigTest`) | `POST /syllabus/big-tests` | Đã làm | Tạo đợt thi (lớp, chặng, ngày giờ, phòng, giám thị, mật mã). |
| `syllabus.big-tests.approve` POST (`approveAndDistributeBigTest`) | `POST /syllabus/big-tests/{id}/approve` | Đã làm | Chỉ Admin; phân phối đề, báo GV. |
| `syllabus.big-tests.stage` POST (`assignBigTestStage`) | `POST /syllabus/big-tests/{id}/stage` | Đã làm | Gắn đợt thi với chặng (`syllabus_stage_id`). |
| `syllabus.big-tests.orders.approve / reject` POST | `POST /syllabus/big-test-orders/{id}/approve / reject` | Khác | Đường dẫn đổi `big-tests/orders/{id}` → `big-test-orders/{id}`; nghiệp vụ như Laravel: chỉ Admin, bắt buộc link đề; order Big Test thì tạo đợt thi mới (ngày giờ, phòng) hoặc gắn đợt có sẵn của lớp; từ chối bắt buộc lý do. |
| `syllabus.big-tests.results` GET `/big-tests/results/{id?}` | `GET /syllabus/big-tests/results?test_id=&result=` · màn `/syllabus/big-tests/results/<id>` | Đã làm | Bảng điểm theo đợt thi, nháp / chờ duyệt / đã duyệt / đã gửi, khóa dòng đã duyệt. |
| `syllabus.big-tests.results.store` POST | `POST /syllabus/big-tests/{id}/results` (`{action, results}`) | Đã làm | Lưu nháp / gửi duyệt; vắng thi để trống điểm; overall làm tròn 1 chữ số như Laravel. |
| `syllabus.big-tests.results.approve` POST | `POST /syllabus/big-tests/{id}/results/approve` | Đã làm | Chỉ Admin. |
| `syllabus.big-tests.send-zalo` POST | `POST /syllabus/big-tests/{id}/send-zalo` | Làm một phần | Đánh dấu đã gửi, chạy đóng chặng / mở chặng kế; Zalo ZNS thật chưa có ở v2 (chế độ sandbox ghi log, `TODO(gộp)`). |
| `syllabus.big-tests.send-single-zalo` POST | `POST /syllabus/big-test-results/{resultId}/send-zalo` | Làm một phần | Như trên, một học viên. |
| `syllabus.big-tests.results.approve-send` POST | `POST /syllabus/big-test-results/{resultId}/approve-send` | Làm một phần | Duyệt và gửi một kết quả; Zalo như trên. |
| `BigTestOrderApprovalSource` (hộp duyệt chung) | `orderSource` (`approvals.go`) | Đã làm | Nhóm Đào tạo; chỉ từ chối ở hộp (duyệt cần link đề + lịch / phòng thi ở màn Phân phối đề, như Laravel); link `/syllabus/big-tests/distribution?order_status=pending`. |
| `bigtests:remind-upcoming` (07:45) | `Service.RemindUpcoming` trong `RunLoop` | Đã làm | Nhắc GV đợt thi trong N ngày tới (1 lần / đợt), đề chưa phân phối thì báo người duyệt, nhắc chặng có ngày dự kiến mà chưa có đợt thi, nhắc Admin duyệt đề mỗi ngày. |
| `bigtests:enforce-sla` (07:55) và `--paper-only` (mỗi giờ, phút 55) | `Service.EnforceSLA(paperOnly)` trong `RunLoop` | Đã làm | GV chưa nhận đề sát giờ thi, trả kết quả trễ (quét 90 ngày), ngưỡng / mức phạt đọc Cấu hình SLA. Biên bản trả kết quả trễ lập qua `Penalties` = `syllabusPenalties` (`payroll.Service.SyncBigTestLate`); việc "Duyệt & phân phối đề" qua `ApprovalTasks` = `syllabusTasks` (`tasks.EnsureBigTestTask`, đóng việc khi đề đã phân phối). |
| `SupportListService::syncBigTest` (danh sách bổ trợ sau Big Test) | interface `Support` | Chưa làm | Danh sách bổ trợ / buổi phụ đạo (`SupportListService`, `support_sessions`) chưa có ở v2 (không phân hệ nào đã chuyển có nó); chưa gắn thì bỏ qua. |
| `teacher.big-test-report` GET (`bigTestReport`) | `GET /teacher/big-test-report?page=` (`teacher_report.go`) · màn `/teacher/big-test-report` | Đã làm | Kết quả Big Test các lớp là GV chính / GVNN (`attendance_student.record` / `record_any`), mới nhất trước, 20 dòng / trang; Nghe / Đọc / Viết / Nói / Tổng ("—" khi trống), "Đã duyệt" / "Chờ duyệt". Không có mục menu như Laravel. |

## Nối với phân hệ khác

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| Cổng PH/HS: kết quả Big Test (`StudentPortalController`, đã duyệt / đã gửi, mới nhất, 5 dòng) | `Service.StudentBigTestResults` ← `portal.Syllabus` (adapter `portalSyllabus`) | Đã làm | Trang chủ, tiến độ và bảng điểm của cổng (xem [cong-hoc-vien.md](cong-hoc-vien.md)). |
| Cổng PH/HS: chặng đang học | `Service.CurrentStage` ← `portal.Syllabus` | Đã làm | "Chặng N: tên chặng" của chặng đang mở; dùng cho góp ý theo chặng. |
| Dashboard / Tổng quan học thuật: đề xuất, giãn tiến độ chờ duyệt, Big Test 14 ngày tới | `Service.Dashboard()` → `dashboard.Deps.Syllabus` (`dashboard.go`) | Đã làm | Bảng Học thuật: thẻ đề xuất / giãn tiến độ chờ duyệt, Big Test 14 ngày tới, hàng chờ (kết quả chờ duyệt, đề xuất, order đề), 5 yêu cầu giãn tiến độ + 8 Big Test sắp tới; Báo cáo đào tạo: số liệu tuần (giãn tiến độ chờ / trong tuần, Big Test 7 ngày tới, đề đã phân phối); lịch "Việc của bạn": đợt Big Test (link Nhắc lịch). Xem [tong-quan.md](tong-quan.md). |
| Báo cáo ngày / tuần / tháng: order đề, đề xuất, giãn tiến độ, lớp sắp thi; link "Đề xuất điều chỉnh giáo trình" | `Service.ReportMetrics` (`reports.go`) bọc `reports.MetricsProvider`; `reports/monthly.go` | Đã làm | Báo cáo ngày Học thuật: order đề chờ duyệt, đề xuất sửa giáo trình, yêu cầu điều chỉnh tiến độ (chi tiết từng dòng); báo cáo tuần Học vụ: lớp có lịch Big Test tuần này / tuần sau; báo cáo tuần Học thuật: dòng gợi ý "Quản lý yêu cầu" (order đề, đề xuất, điều chỉnh). Báo cáo tháng GV có link "Gửi đề xuất sửa giáo trình" (`/syllabus/teacher-propose`) khi có quyền `syllabus.propose_adjustment`. Xem [bao-cao.md](bao-cao.md). |
| KPI: tiêu chí đếm Big Test | `classes.Service.KpiTestSources` (`test_result`, `test_progress`, `student_pass_rate`) | Đã làm | Đọc `syllabus.big_test_results` đã duyệt / đã gửi cùng mini test (xem [kpi.md](kpi.md)). |
| Danh mục: cấp độ gắn giáo trình (`syllabus_curriculum_id`) | Giáo trình chọn cấp độ ở màn Soạn syllabus | Khác | Liên kết lưu ở `syllabus.curricula.course_level_id`; form cấp độ ở Danh mục chưa có ô chọn giáo trình. |

## Tổng

Đã làm 48 · Khác 6 · Làm một phần 4 · Chưa làm 1.

Còn thiếu chính: Zalo ZNS thật, danh sách bổ trợ / buổi phụ đạo (chưa có ở v2), tên bài theo buổi trong giao việc trợ
giảng.
