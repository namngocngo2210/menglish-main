# Luồng màn hình Giáo viên: lớp → chặng → buổi → báo cáo

## Doanh thu lớp và học phí khi xếp / chuyển lớp

Doanh thu tính lương theo `PayrollFormulaService::classRevenue` lấy lớp từ `student_tuitions.class_id`.
Xếp lớp gắn các khoản NULL chưa dùng hết vào lớp chính; chuyển lớp mang các khoản chưa dùng hết trước ngày
chuyển sang lớp mới, giữ lớp cũ cho khoản đã dùng hết. Không tự tính lại lương, kỳ đã khóa giữ nguyên.
Xem [quy tắc gắn lớp học phí và lệnh bù dữ liệu](hoc-phi-gan-lop.md).

## Lương đứng lớp Học thuật (TK-2026-0018)

Học thuật (`academic_lead`, vai trò tính lương `Roles::ACADEMIC_LEAD`) có ca dạy lớp hợp lệ trong kỳ được cộng
**Lương đứng lớp**, kể cả khi chưa bật cờ **Kiêm nhiệm giảng dạy**. Ca gồm chính khóa (`regular`) và dạy thay (`sub`)
có `class_id`, trạng thái `valid`. Ca bị loại bởi quy tắc đi muộn / về sớm không tính buổi. Kèm 1-1, chấm bài,
workshop không tính phần trăm học phí.

**Công thức:** cộng qua từng buổi được tính: `P% × Σ (giá niêm yết trên học phí của từng HS được tính ÷ số buổi học phí)`.
Giá niêm yết là `StudentTuition.total_amount`, trước `discount_amount` / khuyến mãi; số buổi là `session_count > 0`.
Ưu tiên khoản gắn đúng lớp (`student_tuitions.class_id`), mới nhất theo id; nếu thiếu thì khoản mới nhất có số buổi
hợp lệ của HS; chưa có khoản học phí thì lấy giá niêm yết một buổi của lớp (`SessionLedger::classUnitPrice`).
**Không xét thanh toán, số tiền đã thu, công nợ hay trạng thái học phí**: chưa đóng phí vẫn được tính theo giá niêm yết.

Chỉ tính HS **còn giữ ở lớp cuối kỳ**: đã vào lớp không muộn hơn ngày cuối kỳ, chưa rời / chuyển / thôi học ở hoặc
trước ngày đó, không mang trạng thái thôi học (`dropped`) / bảo lưu (`deferred`), không có đơn bảo lưu đã duyệt
bao phủ ngày cuối kỳ (tính cả `defer_from` và `defer_to`). HS bảo lưu hoặc thôi học cuối kỳ đóng góp **0 cho mọi
buổi của kỳ**, kể cả buổi trước khi nghỉ. Hồ sơ cũ có `current_class_id` nhưng chưa có lượt xếp lớp vẫn tính từ
ngày tạo hồ sơ. Trạng thái tại cuối kỳ được khôi phục từ nhật ký thay đổi trạng thái nếu HS đổi trạng thái sau kỳ;
hồ sơ cũ không có nhật ký (hoặc nhật ký đã được dọn theo thời hạn lưu) dùng trạng thái hiện có. Trong tập HS còn giữ, chỉ tính các buổi từ ngày vào lớp và ngoài
cửa sổ bảo lưu đã duyệt từng ngày.

`P` mặc định **40%**, lấy từ tham số `payroll_teaching_share_percent`. Đơn giá riêng **% học phí** của Học thuật
(`TeacherHourlyRate::UNIT_TUITION`) có hiệu lực tại ngày dạy **thay thế** mức mặc định (ví dụ 50%). Thay đơn giá
giữa kỳ sẽ tính từng buổi theo mức hiệu lực; hàng lớp hiển thị nhiều mức %. Màn **Đơn giá giáo viên** mặc định
loại **Học thuật kiêm giảng dạy** cho mọi Học thuật, không phụ thuộc cờ kiêm nhiệm.

**Ví dụ:** lớp giữ 10 HS, mỗi HS có học phí niêm yết 12.000.000đ / 24 buổi = 500.000đ/HS/buổi, tất cả đã vào lớp
trước các buổi dạy và không bảo lưu. Giá trị một buổi = 10 × 500.000 = 5.000.000đ. Học thuật dạy 8 buổi:
**40% × 8 × 5.000.000 = 16.000.000đ** lương đứng lớp.

Phiếu lương có bảng theo lớp: **Lớp | Số buổi dạy | HS còn giữ cuối tháng | Giá trị 1 buổi | % | Thành tiền**, kèm tổng.
Giá trị một buổi là tổng giá niêm yết theo buổi của HS giữ cuối kỳ; tổng giá trị các buổi thực tính còn phụ thuộc
ngày vào lớp và bảo lưu từng ngày. Tiền được làm tròn đến đồng theo lớp rồi cộng tổng.

KPI giữ HS của phần đứng lớp vẫn theo khối tính cũ (`HS giữ được × bậc chọn trên phiếu lương`), áp dụng cả Học thuật
chưa bật cờ nhưng đã dạy. **Phiếu KPI giảng dạy riêng** vẫn phụ thuộc cờ kiêm nhiệm như trước. Học thuật chưa bật cờ
và không có ca lớp hợp lệ giữ phiếu lương cũ; đã bật cờ nhưng chưa có ca vẫn có phần kiêm nhiệm với lương đứng lớp 0đ.
Kỳ đã khóa không tự tính lại khi đổi dữ liệu học phí / lớp.

## Lương giáo viên nước ngoài (GVNN — TK-0019)

GVNN có vai trò riêng `foreign_teacher` (`Roles::FOREIGN_TEACHER`), thuộc nhóm giáo viên và cổng GV, được xếp dạy / giao buổi
như giáo viên. Bảng lương tách nhóm **GVNN** để không lẫn với GV Việt Nam part-time. Việc xem / sửa / duyệt vẫn theo permission
và phạm vi dữ liệu; vai trò chỉ xác định công thức lương.

Mỗi GVNN có **đơn giá cá nhân theo phút** (`minute`), lấy phiên bản có ngày hiệu lực gần nhất không sau ngày dạy. Tiền dạy của
mỗi ca hợp lệ = số phút × đơn giá phút. Ca gắn buổi trên lịch lấy khoảng thời gian bắt đầu đến kết thúc **theo lịch**, kể cả
check-in muộn; ca nhập tay không có buổi trên lịch lấy số giờ công × 60. Thiếu đơn giá phút hiệu lực thì ca có tiền bằng 0 và
cảnh báo thiếu đơn giá; không tự đổi đơn giá theo giờ / buổi hay dùng mức chung.

GVNN không tính thuế TNCN, phạt đi muộn, quên chấm công, vắng hoặc các phạt tự động. Ca vắng không có công hợp lệ không phát
sinh tiền dạy và không cộng thêm tiền phạt. **Biên bản lập tay đã được người có thẩm quyền quyết phạt vẫn được trừ lương** khi
đủ điều kiện; các khoản nhập tay khác vẫn đi theo luồng hiện tại. KPI giữ học sinh dùng bậc do người tính lương chọn tay; nếu
cần bậc mà chưa chọn thì KPI còn chờ chốt, chặn chốt kỳ theo quy tắc chung.

Khoản **Buổi có GVNN** trên phiếu GV Việt Nam đếm **theo từng buổi** của chính GV Việt Nam: buổi đó phải có
`class_sessions.foreign_teacher_id` và GVNN được gán có ca công hợp lệ cùng lớp / ngày. Không suy ra từ GVNN mặc định của lớp,
không đếm cả ngày chỉ vì ngày đó có GVNN. Số buổi là căn cứ để Kế toán nhập tiền tay; hệ thống chưa tự chốt đơn giá khoản này.
Phiếu của chính GVNN luôn có khoản Buổi có GVNN bằng 0. Đổi GVNN của buổi sau lần tính không tự bật cảnh báo "dữ liệu đã
đổi" (buổi học cập nhật quá thường xuyên): Kế toán bấm **Đồng bộ & Tính lại** để đếm lại.

Dữ liệu demo dùng `gv.native1@menglish.edu.vn`, **7.500đ/phút**. `demo:luong` đồng bộ vai trò và sửa đơn giá mẫu cũ khi chạy lại,
không sửa đơn giá tự nhập hoặc tự tính lại kỳ đã khóa. Xem [chi tiết dữ liệu demo](demo-luong-kpi-seed.md).

## Bối cảnh (rà soát 26/10/2026 trên staging)

- **GV không có lối vào lớp của mình.** `/classes` và Trang lớp chạy đúng phạm vi (GV chỉ thấy lớp mình dạy), nhưng nhóm sidebar
  "Lớp học" neo theo `class.update` (GV không có) và Cổng GV không có mục lớp nào → GV chỉ mở được lớp có ca hôm nay (nút trên
  màn Check-in). Report / thống kê của lớp không có ca hôm nay không vào được.
- **"Xem bài giảng" (syllabus theo buổi + tài liệu + nút XÁC NHẬN chặng) bị gỡ khỏi menu** (e4bfc4f3, 23/10) → GV chỉ xác nhận
  qua thông báo. "Bài giảng hôm nay" cũng không có trong menu.
- **Chưa XÁC NHẬN chặng vẫn bấm "Đã dạy"** (staging: 2 lớp FAM0 dạy 4/6 buổi, chặng chưa xác nhận).
- Màn "Chặng đang dạy" chỉ có Order đề; danh sách lớp ở "Báo cáo giảng dạy" không bấm được.
- Giao việc Học thuật (bước 8–10 của `docs/Syllabus_Quy_trinh_xu_ly.xlsx`) chạy đúng: không đổi.

## Quyết định (chủ dự án 26/10/2026)

1. **Lớp của tôi** (`/classes?mine=1`) nằm ở nhóm **Lớp học** khu Đào tạo (cạnh Giáo trình / E-learning / Big Test; chủ dự án
   26/10/2026: GV phải thấy mục Lớp học ở Đào tạo). Cổng GV xếp theo thứ tự luồng: **Bài giảng hôm nay** → **Syllabus & tài
   liệu** (XÁC NHẬN chặng) → Check-in & Điểm danh hôm nay → Chấm bài nộp → … `mine=1` lọc lớp mình dạy (GV chính / TG / GVNN /
   dạy thay theo buổi) — người quản lý kiêm dạy cũng xem được lớp của riêng mình. Route thuộc nhiều workspace → workspace đầu
   tiên mà người xem thấy tab của route đó (vd. GV ở "Buổi bổ trợ của tôi" vẫn thấy tab Cổng GV).
2. Thẻ ở "Chặng đang dạy": nút **XÁC NHẬN** (chặng chưa xác nhận, người được xác nhận), **Bài giảng**, **Trang lớp**, kèm hạn
   xác nhận. Danh sách lớp ở "Báo cáo giảng dạy" mở Trang lớp.
3. **Bắt buộc XÁC NHẬN trước khi "Đã dạy"** (chặn ở server; màn Bài giảng hiện cảnh báo + nút ĐỌC & XÁC NHẬN). XÁC NHẬN chỉ
   bấm được sau khi đọc tổng quan chặng ≥ 30 giây và mở hết tài liệu chặng (docs/kho-syllabus.md §7.3 bước 2).
4. **Hạn xác nhận = 1 giờ trước buổi học đầu tiên của chặng** (buổi thường / học bù chưa hủy, từ ngày bắt đầu chặng). Giao chặng
   sát giờ (đã qua mốc) → hạn tính theo buổi kế tiếp. SLA mới `gv.syllabus_confirm` (nhóm Giáo viên, 1 giờ, Admin đổi / tắt được
   ở Cài đặt SLA), chạy trong `sla:enforce`: quá hạn chưa xác nhận → biên bản cho GV được giao chặng (bậc tái phạm như nhận xét
   trễ: nhắc nhở → 30.000đ → 60.000đ), báo GV + Admin + Học thuật. Không phạt hồi tố (chỉ hạn sau thời điểm bật SLA GV).
5. Giới hạn: đổi GV khi mốc SLA của chặng đã chốt (đã xác nhận đúng hạn / đã lập biên bản) thì GV mới không bị tính SLA lại cho
   chặng đó (vẫn bị chặn "Đã dạy" tới khi xác nhận).

## Kiểm thử

- Menu GV theo thứ tự, "Lớp của tôi" ở nhóm Lớp học (Đào tạo); `?mine=1` chỉ trả lớp mình dạy; GV ở Buổi bổ trợ vẫn thấy tab Cổng GV.
- "Chặng đang dạy" trả nút XÁC NHẬN / Bài giảng / Trang lớp; "Báo cáo giảng dạy" có link lớp.
- "Đã dạy" bị chặn khi chưa xác nhận.
- SLA: xác nhận đúng hạn → không biên bản; quá hạn → 1 biên bản (chạy lại không nhân đôi); giao sát giờ → hạn buổi kế tiếp;
  hạn trước thời điểm bật → không phạt.

## Sprint

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | Test đỏ | Xong |
| 2 | Menu Cổng GV + `mine=1` + workspace + liên kết các màn | Xong |
| 3 | Chặn "Đã dạy" + SLA `gv.syllabus_confirm` | Xong |
| 4 | Test xanh, guard-rail (Navigation*, Ziggy, AllRoutesSmoke), cập nhật `docs/kho-syllabus.md` | Xong |
| 5 | Deploy staging, kiểm tra bằng tài khoản GV (`gv.cohuu2`): `?mine=1` đúng 3 lớp, tab Cổng GV theo thứ tự, thẻ chặng có XÁC NHẬN + hạn | Xong (26/10/2026) |

## Biên bản phạt trên phiếu lương

Biên bản đã quyết phạt (`fined`) và chưa nộp trực tiếp được trừ theo `deduct_from_date` nếu đã chuyển kỳ, hoặc `violation_date` nếu chưa chuyển (`Penalty::scopeDeductibleFor`). Không cần chờ hết hạn nộp mới xuất hiện trên phiếu. Ngày vi phạm và quyết định phạt không thay đổi khi chuyển kỳ.

Kế toán có quyền `payroll.edit` dùng **Chuyển sang kỳ sau** cạnh biên bản trên bảng lương, phiếu lương hoặc màn Biên bản, nhập lý do bắt buộc tối đa 500 ký tự. Hệ thống chuyển ngày trừ sang ngày sau cuối kỳ nguồn, ghi người / thời gian / lý do và gỡ liên kết phiếu nguồn. Kỳ đích có thể chưa được tạo; khi có kỳ phù hợp, khoản phạt sẽ được nhận lúc tính lương. Có thể chuyển tiếp ở kỳ sau nếu vẫn chưa trừ lương.

Không chuyển biên bản chưa quyết phạt, đã nộp, đã trừ, đã hủy hoặc đã đóng vụ. Kỳ nguồn Đã chốt phải **Mở lại** trước (quyền `payroll.approve`); Đã gửi GV xem / Đã trả thì khóa. Kỳ đích đã tồn tại phải là Đang tính; không có kỳ nguồn đang nhận biên bản cũng bị từ chối.

**Phải Đồng bộ & Tính lại kỳ nguồn** (`payroll.calculate`) để cập nhật số khấu trừ trước khi chốt lại; kỳ đích đã tính cũng phải tính lại để nhận khoản phạt. Trợ giảng không được thêm khoản cộng nhập tay, lương buổi có GVNN hay KPI nhập tay để bù thiếu; có thể chuyển phạt hoặc giảm khoản trừ nhập tay đã nhập. Xem [Khấu trừ vượt thu nhập (TK-0017)](vong-doi-ky-luong.md#khấu-trừ-vượt-thu-nhập-tk-0017) về điều kiện chốt và quyền từng bước.
