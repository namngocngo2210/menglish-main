# Dữ liệu test cho ca BA bị chặn (TK-2026-0114)

Lệnh (chỉ staging/local/testing; production → từ chối, không ghi gì). Chạy lại an toàn (khóa cố định `TS-*` / `@test.menglish`):

```bash
docker/menglish.sh staging artisan staging:seed-test-data                 # sinh nhật HS-TB-04 = hôm nay
docker/menglish.sh staging artisan staging:seed-test-data --birthday=2016-03-09   # hoặc ngày chọn (chạy lại để đổi)
# tùy chọn: --password=<mật khẩu> (mặc định Test@2026)
```

Cần có sẵn dữ liệu DemoPhase1 (lớp `DEMO-CG-FAM1` = "Starters FAM 1 · K26 (CG)", 1 tài khoản Tư vấn). Thiếu thì lệnh dừng và báo. Không đụng tài khoản golden (ft-a/pt-a/ht-a.gd) và không tạo/sửa kỳ lương (xem `payroll-test-period.md`).

| # | Ca BA | Dữ liệu tạo |
|---|---|---|
| 1 | HS-TK-02, HS-SM-01 | HS `TS-HS-01` (8 tuổi, có ngày sinh), đăng nhập `hs-tk-02.test@test.menglish`, `must_change_password=false`, học lớp FAM 1 CG |
| 2 | HS-TB-04 | HS `TS-HS-02` (`hs-tb-04.test@…`), sinh nhật = hôm nay hoặc `--birthday` |
| 3 | GV-DX-04 | `hocthuat-gv.test@…` vai trò Học thuật + `academic_teaching=true`, là GV chính lớp `TS-CG-ACTIVE`/`TS-CG-UPCOMING` |
| 4 | GV-NX-07 | Khách học thử "Khách học thử (test)" (CM đặt) vào buổi sắp tới của `DEMO-CG-FAM1` |
| 5 | GV-BN-03 | HS `TS-HS-03` (`hs-bn-03.test@…`) "Chờ khai giảng" trong lớp `TS-CG-UPCOMING` đã mở Chặng 1 |
| 6 | HS-SM-01 | Đề `TS-601` (khối 6, kì gk1, 3 câu, đã xuất bản) cho `TS-HS-01` |
| 7 | HS-PA-03 | Audio mẫu "TS Audio mẫu" (.wav) trong giáo trình của lớp FAM 1 |
| 8 | TC-NN-01..04, HV-08 | `nn-x.gd@test.menglish` tên "GD NN-X (test)", vai trò GVNN, GV nước ngoài của `TS-CG-ACTIVE`, công (`teacher_timesheets`) cho mọi buổi đã qua |
| 9 | HP-DH-01, HP-DH-10 | `TS-HS-04`: nợ, hạn +7 ngày (nhóm Sắp đến hạn); `TS-HS-05`: Nghỉ hè, đã đóng 36 buổi (còn buổi dư) |

## Không seed được "đẹp" — nên cân nhắc tính năng UI (chưa làm)

- **HS-SM-01**: kho đề luyện chỉ có khối 6–9, mà HS dưới 10 tuổi tính ra khối ≤4. Seed ép khối 6 bằng `students.grade_adjust`. Nếu BA muốn "HS tiểu học làm đề luyện" thật thì cần quyết định nghiệp vụ (mở khối 1–5) — đề xuất, không làm khi chưa cần.
- **HS-PA-03**: file audio là WAV im lặng 1 giây (chỉ để thấy bài nghe mẫu + phát được). Nội dung thật phải do Học thuật tải lên.
- **TC-NN / HV-08**: công GVNN là dòng `valid` tạo thẳng, không qua check-in thật; ca R-14 (GV bán thời gian có 2 ca liền kề) không nằm trong danh sách seed.
- Mật khẩu test dùng chung, đặt bằng `--password`.
