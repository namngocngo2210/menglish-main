package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"strings"

	_ "github.com/go-sql-driver/mysql"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/legacy"
)

// legacySteps: các bước nhập dữ liệu Laravel theo thứ tự phụ thuộc (bước sau tra id của bước trước).
func legacySteps() []legacy.Step {
	return iam.LegacySteps()
}

// runImportLegacy: menglish import-legacy [--from DSN] [--only branches,users] [--dry-run]
// DSN MySQL dạng user:pass@tcp(host:3306)/menglish, lấy từ --from hoặc biến LEGACY_MYSQL_DSN. Chỉ đọc MySQL.
// Chạy lại nhiều lần được: bản ghi đã nhập thì cập nhật theo dữ liệu mới nhất của Laravel.
func runImportLegacy(ctx context.Context, args []string) error {
	fs := flag.NewFlagSet("import-legacy", flag.ContinueOnError)
	from := fs.String("from", os.Getenv("LEGACY_MYSQL_DSN"), "DSN MySQL của Laravel")
	only := fs.String("only", "", "chỉ chạy các bước này, cách nhau bởi dấu phẩy")
	dry := fs.Bool("dry-run", false, "chạy thử rồi hoàn tác, chỉ in báo cáo")
	list := fs.Bool("list", false, "liệt kê các bước")
	if err := fs.Parse(args); err != nil {
		return err
	}
	steps := legacySteps()
	if *list {
		for _, s := range steps {
			fmt.Println(s.Name)
		}
		return nil
	}
	if *from == "" {
		return errors.New("thiếu --from hoặc LEGACY_MYSQL_DSN")
	}
	dsn := *from
	if !strings.Contains(dsn, "parseTime=") {
		sep := "?"
		if strings.Contains(dsn, "?") {
			sep = "&"
		}
		dsn += sep + "parseTime=true&loc=Asia%2FHo_Chi_Minh&charset=utf8mb4"
	}
	src, err := sql.Open("mysql", dsn)
	if err != nil {
		return err
	}
	defer src.Close()
	if err := src.PingContext(ctx); err != nil {
		return fmt.Errorf("không kết nối được MySQL: %w", err)
	}
	d, err := openDeps(ctx)
	if err != nil {
		return err
	}
	defer d.close()

	var names []string
	if *only != "" {
		names = strings.Split(*only, ",")
	}
	report, err := legacy.Import(ctx, src, d.pool, d.log, steps, legacy.Options{Only: names, DryRun: *dry})
	out, _ := json.MarshalIndent(report, "", "  ")
	fmt.Println(string(out))
	if err != nil {
		return err
	}
	if *dry {
		fmt.Println("Chạy thử: đã hoàn tác, chưa ghi gì.")
		return nil
	}
	// Quyền và vai trò có thể đã đổi: bỏ cache quyền của mọi người.
	if err := iam.InvalidateAllAccess(ctx, d.rdb); err != nil {
		d.log.Warn("invalidate access cache failed", "err", err)
	}
	fmt.Println("Đã nhập xong.")
	return nil
}
