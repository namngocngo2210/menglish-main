# Đối chiếu: Khảo sát & Hướng dẫn sử dụng

## Khảo sát chất lượng đào tạo

Nguồn Laravel: `app/Http/Controllers/SurveyController.php`, `app/Models/Survey.php`,
`app/Http/Controllers/StudentPortalController.php` (`studentSurvey`, `submitSurvey`, `deleteSurvey`, màn #6),
`resources/js/Pages/Surveys/Index.vue`, `routes/web.php` (nhóm `surveys.*`, `portal.student.survey*`). Đối chiếu tại commit
`5caf8ba9`.

v2: migration `00191_ops_surveys.sql` (bảng `ops.surveys`, `ops.survey_responses`), Go `v2/api/internal/surveys`, web
`v2/web/src/features/surveys`.

Trạng thái: **Đã làm** · **Khác** (đã làm, khác có chủ đích, xem ghi chú) · **Làm một phần** · **Chưa làm**.

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `surveys.index` GET `/surveys` (`survey.manage`) | `GET /surveys` · màn `/surveys` (Khảo sát → Đợt khảo sát) | Đã làm | Mọi đợt chưa xóa, mới trước: tiêu đề, mô tả, hạn (dd/mm/yyyy, "• Hôm nay" theo giờ Việt Nam), Đang mở / Đã đóng, người tạo. Thêm cột số bài đã nộp. |
| `surveys.store` POST | `POST /surveys` | Đã làm | Như `validate()`: "Vui lòng nhập Tiêu đề.", tiêu đề ≤ 255, mô tả ≤ 2000, hạn là ngày hợp lệ và ≥ hôm nay; đợt mới luôn đang mở; "Đã tạo đợt khảo sát mới.". Câu báo lỗi hạn dùng tên trường "Hạn hoàn thành" (Laravel in nguyên `deadline` vì chưa khai báo tên trường). |
| `surveys.update` PUT | `PUT /surveys/{id}` | Đã làm | Cùng kiểm dữ liệu, không bắt hạn ≥ hôm nay; `is_active` bỏ trống = đóng như `$request->boolean`. "Đã cập nhật khảo sát "…"." |
| `surveys.destroy` DELETE | `DELETE /surveys/{id}` | Đã làm | Xóa mềm như `SoftDeletes`; bài đã nộp giữ lại (chụp tên đợt lúc nộp). Hộp xác nhận cùng câu chữ. |
| — | `GET /surveys/{id}/responses` · màn `/surveys/<id>` | Mới ở v2 | Bài đã nộp của đợt (học viên, người gửi, số sao, góp ý, thời điểm) + điểm trung bình. Laravel chỉ lưu vào `academic_records`, không có màn xem. |
| `portal.student.survey` GET `/portal/survey/{studentId?}` | `GET /portal/surveys?student_id=` · màn `/portal/survey` (Cổng PH/HS) ← `surveys.Service.OpenSurveys` / `StudentResponses` | Khác | Màn "Khảo sát 5 sao" ở Cổng PH/HS (xem [cong-hoc-vien.md](cong-hoc-vien.md)); cổng lo bộ chọn học viên (chính tài khoản / con của phụ huynh / nhân sự xem hộ), phân hệ Khảo sát cấp đợt đang mở (hạn tăng dần, chưa đặt hạn lên đầu) và lịch sử bài đã làm ("Khảo sát: <đợt> - <học viên>") qua adapter `portalSurveys` (`cmd/menglish/wiring.go`). API trùng `/surveys/portal` bỏ. |
| `portal.student.survey.store` POST | `POST /portal/surveys` → `surveys.Service.Respond` | Khác | Gửi `survey_id` thay vì `survey_title` (Laravel dò đợt theo tên); đợt phải đang mở ("Đợt khảo sát đã đóng hoặc không còn tồn tại."). Kiểm như Laravel: học viên bắt buộc và mình được thao tác, góp ý bắt buộc ≥ 5 ký tự, số sao 1–5 (mặc định 5). Cùng thông báo cảm ơn. Lưu ở `ops.survey_responses` (Laravel: `academic_records` screen_key `06_khao_sat`). |
| `portal.student.survey.destroy` DELETE | `DELETE /portal/surveys/{id}` → `surveys.Service.RemoveResponse` | Đã làm | Chỉ bài của học viên mình được thao tác; "Đã xóa bài khảo sát thành công! Bạn có thể thực hiện lại.". |
| Nhật ký thao tác | `audit.Record` module "Khảo sát & Đề thi" (`survey.create/update/delete/submit/response_delete`) | Khác | Laravel ghi theo đường dẫn nên rơi vào nhóm chung. |
| Nhập dữ liệu cũ (`surveys`, `academic_records` khảo sát) | — | Chưa làm | Cột `legacy_id` đã có. |

## Hướng dẫn sử dụng

Nguồn Laravel: `app/Http/Controllers/UserGuideController.php`, `app/Support/Guide/UserGuide.php`, `resources/guides/*.php`,
`resources/js/Pages/Guide/Index.vue`, ảnh `public/images/huong-dan/`.

v2: web `v2/web/src/features/guide` (nội dung tĩnh `content.ts` sinh từ file PHP, tải riêng khi mở trang), ảnh
`v2/web/public/images/huong-dan/`. Không có API.

| Laravel | v2 | Trạng thái | Ghi chú |
| --- | --- | --- | --- |
| `guide.index` GET `/huong-dan?vai-tro=` (`auth`) | màn `/huong-dan?vai-tro=` (Cá nhân → Hướng dẫn sử dụng) | Đã làm | 8 bộ theo vai trò + "Dùng chung"; bộ của mình xếp đầu ("Của bạn", "Vai trò của bạn"); học viên / phụ huynh (`portal.student`, không `portal.staff`) chỉ thấy bộ Học viên. Tìm không dấu theo tiêu đề / mô tả / bước / lưu ý, mục lục, phóng to ảnh, **chữ đậm**. |
| Nút "Mở màn hình" (`openUrl`: route không tham số + `SidebarMenu::canSee`) | `openUrl` (bảng `ROUTE_PATHS` route Laravel → đường dẫn v2, kiểm quyền + điều kiện menu của mục) | Đã làm | Đủ route của các bộ hướng dẫn (Chấm công, Lương & Phạt, Cổng Giáo viên, Cổng PH/HS, Chất lượng lớp, Dự án học thuật, Giáo trình & Big Test, báo cáo tháng GV). `crm.closing-wizard` không có nút: v2 chốt khách ngay trên hồ sơ khách. |
| Nội dung `resources/guides/*.php` | `features/guide/content.ts` | Đã làm | Sinh lại khi Laravel đổi nội dung (xuất JSON bằng `php` rồi bọc thành TS); ảnh chép nguyên. Nội dung mô tả giao diện Laravel, một số chữ nút có thể khác v2. |
