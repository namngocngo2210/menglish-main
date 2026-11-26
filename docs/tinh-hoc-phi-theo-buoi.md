# Số buổi dư đầu kỳ và nghỉ hè (TK-2026-0172)

Sổ buổi theo học viên: **balance = opening_sessions + buổi đã đóng − buổi đã dùng**. Chuyển lớp giữ nguyên số dư này. File nhập học phí có hai cột tùy chọn “Số buổi” và “Đã học”: dư đầu kỳ = max(0, Số buổi − Đã học). Cả hai phải là số nguyên không âm, Đã học không vượt Số buổi. Chỉ áp dụng một lần cho mỗi học viên, kể cả dư bằng 0; dòng cũ không có hai cột hoặc để trống cả hai không thay đổi dư đầu kỳ. Có thể nhập riêng hai cột này mà không tạo hợp đồng học phí.

Học vụ và Admin khai báo hoặc xóa một khoảng nghỉ hè trên hồ sơ học viên. Lưu lại **thay thế** khoảng cũ, không cộng dồn. Chỉ tính buổi chính khóa / học bù chưa hủy của lớp hiện tại, trong khoảng ngày bao gồm hai đầu và chưa tới giờ bắt đầu. Buổi đã diễn ra đã giảm số buổi còn lại của khóa, không trừ hè thêm lần nữa; ngày lễ không có buổi hoặc buổi đã hủy không được tính.

**Buổi cần thu = max(0, còn lại của khóa − balance − buổi hè)**. Chặn buổi hè ở số buổi còn lại của khóa; hè không tạo thêm dư. Dư chuyển tiếp giữ công thức **max(0, balance − còn lại)**: dư lớn hơn khóa thì thu 0 và giữ phần thừa cho khóa sau. Balance âm vẫn được giữ để không xóa thiếu hụt, còn “Buổi dư” hiển thị tối thiểu 0.

Dư đầu kỳ, dư chuyển tiếp và trừ hè đều chỉ là số buổi, không đổi ra tiền. Đơn giá giữ theo lớp (hoặc hợp đồng đang thu theo quy tắc hiện có); tiền buổi = số buổi thu × đơn giá. Giới hạn buổi thu sau trừ hè không được coi là đã thu hết số buổi chưa đóng thực của hợp đồng để thu toàn bộ tiền còn nợ. Chuyển nhượng bị chặn nếu số buổi chuyển vượt max(0, balance) thực tế của học viên.
