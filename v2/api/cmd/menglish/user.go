package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"

	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
)

// runUser: tạo tài khoản đầu tiên và cấp lại mật khẩu từ dòng lệnh (trước khi có màn quản lý người dùng).
//
//	menglish user create --code MES001 --email admin@... --name "Nguyễn Văn A" [--kind staff] [--phone 09...]
//	menglish user reset-password <mã | email | SĐT phụ huynh>
func runUser(ctx context.Context, args []string) error {
	if len(args) == 0 {
		return errors.New("dùng: menglish user create ... | reset-password <mã|email|SĐT>")
	}
	d, err := openDeps(ctx)
	if err != nil {
		return err
	}
	defer d.close()

	switch args[0] {
	case "create":
		fs := flag.NewFlagSet("user create", flag.ContinueOnError)
		var in iam.NewUser
		fs.StringVar(&in.Kind, "kind", "staff", "staff | student | guardian")
		fs.StringVar(&in.Code, "code", "", "mã đăng nhập, vd. MES001 hoặc HV-00001")
		fs.StringVar(&in.Email, "email", "", "email")
		fs.StringVar(&in.Phone, "phone", "", "số điện thoại")
		fs.StringVar(&in.FullName, "name", "", "họ tên")
		if err := fs.Parse(args[1:]); err != nil {
			return err
		}
		if in.FullName == "" || (in.Code == "" && in.Email == "" && in.Phone == "") {
			return errors.New("cần --name và ít nhất một trong --code, --email, --phone")
		}
		u, password, err := d.iam.CreateUser(ctx, in)
		if err != nil {
			return err
		}
		printCredentials("Đã tạo tài khoản", u, password)
	case "reset-password":
		if len(args) != 2 {
			return errors.New("dùng: menglish user reset-password <mã|email|SĐT phụ huynh>")
		}
		u, password, err := d.iam.ResetPassword(ctx, args[1])
		if errors.Is(err, pgx.ErrNoRows) {
			return fmt.Errorf("không tìm thấy tài khoản %q", args[1])
		}
		if err != nil {
			return err
		}
		printCredentials("Đã cấp lại mật khẩu, mọi thiết bị đã bị đăng xuất", u, password)
	default:
		return fmt.Errorf("lệnh user không hỗ trợ: %s", args[0])
	}
	return nil
}

func printCredentials(title string, u iam.User, password string) {
	login := u.FullName
	for _, p := range []*string{u.Code, u.Email, u.Phone} {
		if p != nil {
			login = *p
			break
		}
	}
	fmt.Fprintf(os.Stdout, "%s: %s\n  Đăng nhập: %s\n  Mật khẩu tạm: %s\n  (Bắt đổi mật khẩu ở lần đăng nhập đầu.)\n",
		title, u.FullName, login, password)
}
