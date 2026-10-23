# Đổi vai trò trên bản thử nghiệm (dungthu)

## Bối cảnh

Muốn test màn hình của từng vai trò trên dungthu phải đăng xuất rồi đăng nhập lại bằng tài khoản của vai trò đó.
Bản trước (PR #137, đã đóng) yêu cầu Super Admin đăng nhập một lần rồi mới đổi được; bản này bỏ hẳn bước đăng nhập.

Dữ liệu dungthu là bản sao dữ liệu thật (import từ hosting), nên không để ai có link cũng vào được: cần **mã truy cập
chung**, mỗi trình duyệt nhập một lần.

## Cách dùng

1. Mở `https://dungthu.meducation.vn/login` → khung vàng **Xem thử theo vai trò** → nhập mã truy cập → **Mở**.
2. Danh sách vai trò hiện ra (chỉ vai trò đang có tài khoản hoạt động). Bấm tên vai trò → vào bằng tài khoản đầu tiên
   (tên A→Z); hoặc chọn đúng một người ở ô bên cạnh (tối đa 20 người / vai trò).
3. Đang ở trong app: nút vàng **Đổi vai trò** cạnh chuông thông báo, cùng danh sách đó.

Đổi vai trò là đăng nhập hẳn bằng tài khoản đó: quyền, phạm vi dữ liệu, menu, trang đầu giống hệt người dùng thật.

## Thiết kế

- `config/role_switcher.php`: `ROLE_SWITCHER_ENABLED` + `ROLE_SWITCHER_CODE`. Thiếu một trong hai, hoặc
  `APP_ENV=production` → tắt (route 404, không hiện gì).
- `App\Support\RoleSwitcher`: điều kiện bật, kiểm tra mã, cookie `role_switcher` (30 ngày, mã hoá; giá trị là HMAC
  của mã với `APP_KEY` → đổi mã thì mọi cookie cũ mất hiệu lực), danh sách tài khoản theo vai trò (shared prop
  `roleSwitcher`; chưa nhập mã thì không trả danh sách).
- `RoleSwitcherController`:
  - `POST /doi-vai-tro/mo-khoa` (`role-switcher.unlock`): kiểm tra mã, sai quá 5 lần / phút / IP thì chặn tạm.
  - `POST /doi-vai-tro/{user}` (`role-switcher.switch`): cần cookie hợp lệ, tài khoản đích đang hoạt động và có vai
    trò; đăng nhập, tạo lại phiên, ghi nhật ký `auth`, chuyển về trang đầu (`AuthenticatedSessionController::homeUrl`).
    Dùng được khi chưa đăng nhập lẫn khi đang đăng nhập; tài khoản bị bắt đổi mật khẩu vẫn chuyển đi được.
- Vue: `Layouts/Shell/RoleSwitcherAccounts.vue` (danh sách), `RoleSwitcherMenu.vue` (topbar), khung trong `Auth/Login.vue`.

## Bật trên dungthu

Thêm vào `.env.staging` trên máy chủ rồi deploy lại staging (`docker/menglish.sh staging deploy`):

```
ROLE_SWITCHER_ENABLED=true
ROLE_SWITCHER_CODE=<mã đủ dài, chia cho người test>
```

Thu hồi quyền: đổi `ROLE_SWITCHER_CODE` (hoặc tắt) rồi deploy lại.

## Tiến độ

| Sprint | Nội dung | Trạng thái |
|---|---|---|
| 1 | Config, `RoleSwitcher`, controller + route, khung login, nút topbar, `RoleSwitcherTest` | Xong |
