// Một binary cho mọi vai trò: menglish api | migrate <lệnh goose> | user <lệnh> | import-legacy | healthcheck | version.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/approvals"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/attendance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit/auditapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/catalogs"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/config"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/crm"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/dashboard"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/finance"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/geo"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/inventory"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail/mailapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/notifyapi"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/payroll"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/secretbox"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/portal"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/projects"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/quality"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/reports"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/search"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/server"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/store"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/surveys"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/syllabus"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tasks"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tickets"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition"
)

// Gắn lúc build: -ldflags "-X main.version=<commit>".
var version = "dev"

func main() {
	if len(os.Args) < 2 {
		usage()
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	var err error
	switch os.Args[1] {
	case "api":
		err = runAPI(ctx)
	case "migrate":
		err = runMigrate(ctx, os.Args[2:])
	case "user":
		err = runUser(ctx, os.Args[2:])
	case "import-legacy":
		err = runImportLegacy(ctx, os.Args[2:])
	case "healthcheck":
		err = healthcheck()
	case "version":
		fmt.Println(version)
	default:
		usage()
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "lỗi:", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "Dùng: menglish api | migrate <up|down|status|...> | user <create|set-roles|reset-password> | import-legacy [--from DSN] [--only ...] [--dry-run] | healthcheck | version")
	os.Exit(2)
}

// deps mở Postgres, Redis, khóa mã hóa và dựng dịch vụ iam (dùng chung cho api và lệnh user).
type deps struct {
	cfg  config.Config
	log  *slog.Logger
	pool *pgxpool.Pool
	rdb  *redis.Client
	box  *secretbox.Box
	iam  *iam.Service
}

func openDeps(ctx context.Context) (*deps, error) {
	cfg, err := config.Load()
	if err != nil {
		return nil, err
	}
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: cfg.LogLevel}))
	box, err := secretbox.FromBase64(cfg.EncryptionKey)
	if err != nil {
		return nil, err
	}
	pool, err := store.OpenPostgres(ctx, cfg.DatabaseURL)
	if err != nil {
		return nil, err
	}
	rdb, err := store.OpenRedis(cfg.RedisURL)
	if err != nil {
		pool.Close()
		return nil, err
	}
	return &deps{cfg: cfg, log: log, pool: pool, rdb: rdb, box: box, iam: iam.NewService(pool, rdb, box, log)}, nil
}

func (d *deps) close() {
	d.rdb.Close()
	d.pool.Close()
}

func runAPI(ctx context.Context) error {
	d, err := openDeps(ctx)
	if err != nil {
		return err
	}
	defer d.close()
	cfg, log := d.cfg, d.log

	// Email: nghiệp vụ xếp thư bằng mail.Enqueue trong transaction; vòng nền gửi qua SMTP lưu ở màn Email nhận Ticket.
	mailSvc := mail.NewService(d.pool, d.box, log, mail.Options{BlockOutgoing: cfg.MailBlockOutgoing, PublicURL: cfg.PublicURL})
	go mailSvc.RunLoop(ctx, 5*time.Second)
	// Kho file chung: thư mục local (FILES_DIR) hoặc R2 / MinIO (FILES_DRIVER=s3). Module khác gắn file qua fileSvc.
	fileSvc, err := openFiles(cfg, log, d.pool)
	if err != nil {
		return err
	}
	go fileSvc.RunPurgeLoop(ctx, 6*time.Hour)
	d.iam.SetContractFiles(fileSvc.StaffContracts(d.iam))
	catalogSvc := catalogs.NewService(d.pool, d.box, log)
	catalogSvc.Mail = mailSvc
	studentSvc := students.NewService(d.pool, d.iam, log)
	// Phân hệ Lớp học làm phần việc lớp cho Học viên, Danh mục (khóa / trình độ / phòng / nghỉ lễ / ca dạy) và Order học liệu.
	cls := classes.NewService(d.pool, catalogSvc, log)
	go cls.RunStartStudyingLoop(ctx, time.Hour)
	auth := iam.NewHandler(d.iam, log).WithAddressSearch(geo.NewSearcher(d.rdb, log, cfg.PublicURL))
	// Công việc: lớp / buổi học gắn vào việc và lớp trực trong ngày của trợ giảng lấy từ phân hệ Lớp học.
	taskSvc := tasks.NewService(d.pool, log).WithClasses(cls.TaskClasses())
	// Chuyển việc đã qua hạn sang "Quá hạn" (lệnh tasks:mark-overdue chạy theo lịch ở bản Laravel).
	go taskSvc.RunOverdueLoop(ctx, 10*time.Minute)
	// Kho hết / âm sau khi xuất → phân hệ Công việc giao Admin việc nhập bù.
	inv := inventory.NewService(d.pool, log, inventory.DefaultConfig()).WithRestocker(taskSvc.Restocker())
	go inv.RunOverdueLoop(ctx, 15*time.Minute)
	studentSvc.Classes, catalogSvc.Classes, inv.Classes = cls, cls, cls
	// Học phí: phiếu thu duyệt thì xuất bán / hủy thì hoàn kho vật phẩm qua phân hệ Kho; chốt khách chưa đóng học phí
	// → task "Nhắc thu học phí" (phân hệ Công việc). tuitionSvc.Closing bọc phần chốt của Lớp học để lập học phí / phiếu thu.
	tuitionSvc := tuition.NewService(d.pool, d.box, catalogSvc, log).WithStock(inv).WithTasks(taskSvc)
	// Chốt & Xếp lớp: Lớp học tạo hồ sơ học viên và ghi danh trong giao dịch của CRM; xếp lớp từ màn Học viên hoàn tất
	// khách Chờ xếp lớp bên CRM.
	crmSvc := crm.NewService(d.pool, d.iam, log).WithStudents(tuitionSvc.Closing(cls.CRMStudents(studentSvc))).WithTuition(tuitionSvc)
	cls.CRM, cls.Tuition, studentSvc.Tuition = crmSvc, tuitionSvc, tuitionSvc
	// Lương & Phạt: bằng chứng vi phạm ở kho file.
	payrollSvc := payroll.NewService(d.pool, log).WithFiles(fileSvc)
	go payrollSvc.RunReminderLoop(ctx, time.Minute)
	// Chấm công: ảnh khuôn mặt ở kho file, phạm vi lớp của giờ dạy từ Lớp học; kỳ lương đã duyệt / chi trả khóa dữ liệu,
	// đi muộn tính lỗi tự lập biên bản (Lương & Phạt). Bảng lương đọc ca dạy hợp lệ + ngày công của Chấm công (wiring.go).
	attSvc := attendance.NewService(d.pool, d.iam, log).WithFiles(fileSvc).WithClasses(cls).
		WithPayrollLock(attendancePayroll{payrollSvc}).WithPenalties(attendancePayroll{payrollSvc})
	payrollSvc.WithAttendance(payrollAttendance{attSvc})
	// Giáo trình & Big Test: lớp được xem + giãn tiến độ (thêm buổi theo TKB) qua phân hệ Lớp học, tài liệu / file đính kèm
	// ở kho file, luật SLA ở Danh mục; biên bản trả kết quả trễ (Lương & Phạt), việc "Duyệt & phân phối đề" (Công việc).
	// Chưa có ở v2: danh sách bổ trợ sau Big Test (SupportListService thuộc báo cáo trực lớp), Zalo ZNS thật.
	sylSvc := syllabus.NewService(d.pool, log, syllabus.Deps{Classes: cls.SyllabusClasses(), Files: fileSvc, SLA: catalogSvc,
		Penalties: syllabusPenalties{payrollSvc}, Tasks: syllabusTasks{taskSvc}})
	// Nhắc lịch Big Test, SLA đề / kết quả, SLA duyệt giãn tiến độ (bigtests:* / syllabus:notify-adjustment-sla ở bản Laravel).
	go sylSvc.RunLoop(ctx, time.Minute)
	// Ảnh bằng chứng hoàn tiền lưu ở kho file; hoa hồng tư vấn (thu hồi khi hoàn phí / hủy hóa đơn) của phân hệ Lương.
	tuitionSvc.WithLeads(crmSvc).WithStudents(studentSvc).WithAccess(d.iam.LoadAccess).WithFiles(fileSvc).WithCommissions(payrollSvc.Commissions()).
		WithSepay(tuition.SepaySettings{WebhookEnabled: cfg.Sepay.WebhookEnabled, WebhookSecret: cfg.Sepay.WebhookSecret})
	// Nhắc nợ theo mốc, SLA tiền mặt / hoàn phí, bảo lưu bắt đầu / hết hạn (lệnh tuition:* / students:*-deferrals ở bản Laravel).
	go tuitionSvc.RunReminderLoop(ctx, time.Minute)
	// Leads: khách CRM. Sessions: buổi học thật của phân hệ Lớp học cho học thử.
	placeSvc := placement.NewService(d.pool, d.rdb, log, placement.Options{
		Leads: crmSvc.PlacementLeads(), Sessions: cls.PlacementSessions(), Files: fileSvc,
	})
	place := placement.NewHandler(placeSvc, log)
	// Cổng giáo viên: lớp / buổi / điểm danh của Lớp học, khách học thử của Test đầu vào, file bài tập / bài nộp ở kho file,
	// check-in ca dạy / bảng công / lương tạm tính / vi phạm (Chấm công + Lương), Unit / bài học của giáo trình lớp.
	// Bài phát âm (tab chấm bài) của Cổng học viên gắn ở wirePortalTeacher bên dưới. Chưa có ở v2: danh sách bổ trợ
	// (SupportListService::syncMiniTest thuộc báo cáo trực lớp).
	teacherSvc := teacher.NewService(d.pool, log, teacher.Deps{
		Classes: cls.TeacherPortal(), SLA: catalogSvc, Files: fileSvc, Trials: teacher.PlacementTrials(placeSvc),
		Work: teacherWork{att: attSvc, pay: payrollSvc}, Syllabus: teacherSyllabus{sylSvc},
	})
	// Báo cáo: số liệu tuyển sinh từ CRM / test đầu vào, bọc thêm số liệu lớp / buổi / điểm danh của Lớp học; việc follow-up
	// qua Công việc, ngày nghỉ từ danh mục; "Số lượng thu phí" từ Học phí.
	// KPI: mục KPI của báo cáo tuần lấy từ bộ tiêu chí đang áp dụng (bọc MetricsProvider của báo cáo).
	kpiSvc := kpi.NewService(d.pool, log)
	// Chất lượng lớp (dự giờ / checklist / họp giáo viên) theo phạm vi lớp của phân hệ Lớp học; Dự án học thuật đọc SLA
	// trễ / nhắc mốc từ danh mục, mốc trễ lập biên bản qua Lương & Phạt.
	qualitySvc := quality.NewService(d.pool, cls, log)
	projectSvc := projects.NewService(d.pool, log).WithSla(catalogSvc).WithPenalties(projectPenalties{p: payrollSvc, sla: catalogSvc})
	// Lệnh sla:run (academic.milestone_late / milestone_remind) ở bản Laravel.
	go projectSvc.RunSlaLoop(ctx, 5*time.Minute)
	reportSvc := reports.NewService(d.pool, log, reports.Deps{
		Metrics: kpiSvc.ReportMetrics(projectSvc.ReportMetrics(qualitySvc.ReportMetrics(tuitionSvc.ReportMetrics(
			cls.ReportMetrics(reports.NewMetrics(crmSvc.ReportSource()), d.iam.LoadAccess)), d.iam.LoadAccess), d.iam.LoadAccess)),
		Classes: cls.ReportClasses(), Tasks: taskSvc.ReportTasks(), Calendar: catalogSvc.ReportCalendar(),
	})
	// Nhắc hạn báo cáo ngày / tháng và tổng hợp cho Admin (lệnh reports:remind-* / reports:daily-digest ở bản Laravel).
	go reportSvc.RunReminderLoop(ctx, time.Minute)
	// Thu chi: sổ khoản chi + doanh thu tạm tính từ phiếu thu đã duyệt của Học phí, chi lương tự động từ kỳ lương.
	finSvc := finance.NewService(d.pool, log).WithRevenue(tuitionSvc.RevenueSource()).WithPayroll(payrollSvc.Finance())
	// Số liệu KPI tự động từ báo cáo / công việc / kho / lớp học / chấm công; tạo phiếu đầu kỳ hằng ngày (kpi:create-sheets).
	// TODO(gộp): nguồn Học phí / SLA CRM / Big Test khi có ở v2. Kỳ lương đã chốt khóa phiếu KPI.
	kpiSvc.AddSources(reportSvc.KpiSources(), taskSvc.KpiSources(), inv.KpiSources(), cls.KpiSources(), attSvc.KpiSources(), projectSvc.KpiSources()).
		WithJournal(reportSvc).WithAttendance(cls.KpiAttendance()).WithBoard(cls.KpiBoard(), taskSvc.KpiBoard()).
		WithPayrollLock(payrollSvc)
	kpiSvc.AddSources(teacherSvc.KpiSources()).WithHomework(teacherSvc.KpiBoard())
	go kpiSvc.RunSheetLoop(ctx, time.Hour)
	// Ticket hỗ trợ: file đính kèm ở kho file; hàng chờ "Ticket đang mở" / ticket khẩn ở Tổng quan và Nhật ký sự vụ.
	ticketSvc := tickets.NewService(d.pool, log).WithFiles(fileSvc)
	// Cổng Phụ huynh & Học sinh: học phí đọc qua adapter của Học phí; bài tập / bài nộp / nhận xét / mini test qua Cổng
	// giáo viên, tab Phát âm của màn chấm bài đọc bản ghi âm của cổng học viên (portal_teacher.go); khảo sát 5 sao của
	// phân hệ Khảo sát, kết quả Big Test / chặng đang học của Giáo trình (wiring.go).
	surveySvc := surveys.NewService(d.pool, log)
	portalSvc := portal.NewService(d.pool, fileSvc, studentSvc, log).WithTuition(tuitionSvc.PortalTuition()).
		WithSurveys(portalSurveys{surveySvc}).WithSyllabus(portalSyllabus{sylSvc})
	wirePortalTeacher(portalSvc, teacherSvc)
	// Hộp "Việc cần duyệt" (chỉ Admin), thứ tự nguồn như ApprovalRegistry của Laravel: Học phí (phiếu thu / hủy / hoàn),
	// học viên báo đã đóng học phí (Cổng PH/HS), ghi danh (Lớp học), xác nhận chính thức / chuyển cơ sở (CRM), đề xuất
	// giáo trình / giãn tiến độ / order đề Big Test (Giáo trình), Công việc, đơn chấm công (Chấm công). Chưa có ở v2: báo
	// cáo trực lớp (ClassReportApprovalSource).
	approvalSvc := approvals.NewService(d.rdb, log).Add(tuitionSvc.ApprovalSources()...).Add(portalSvc.ApprovalSource()).
		Add(cls.ApprovalSource()).Add(crmSvc.ApprovalSources()...).Add(sylSvc.ApprovalSources()...).
		Add(taskSvc.ApprovalSource(), attSvc.ApprovalSource())
	// Tổng quan + Báo cáo & sự vụ lớp: đọc số liệu các phân hệ qua adapter (Finance: doanh thu / phiếu chờ duyệt / quá hạn
	// của Học phí; Work: ngày công / đi muộn / phép của Chấm công, biên bản lỗi khi gắn phân hệ Lương & Phạt).
	dash := dashboard.NewService(dashboard.Deps{
		Leads: crmSvc.DashboardLeads(), Tasks: taskSvc.DashboardTasks(), Classes: cls.DashboardClasses(),
		Students: studentSvc.DashboardStudents(), Reports: reportSvc.DashboardReports(), Orders: inv.DashboardOrders(),
		Branches: d.iam, Finance: tuitionSvc.DashboardFinance(), Homework: teacherSvc.Dashboard(), Work: attSvc.DashboardWork(), Tickets: ticketSvc.DashboardTickets(), Projects: projectSvc.DashboardMilestones(), Payroll: payrollSvc,
	}, log)
	checks := map[string]server.Pinger{
		"postgres": d.pool,
		"redis":    server.PingFunc(func(ctx context.Context) error { return d.rdb.Ping(ctx).Err() }),
	}
	srv := &http.Server{
		Addr: cfg.HTTPAddr,
		Handler: server.New(server.Deps{
			Log: log, Version: version, Env: cfg.Env, Checks: checks,
			// Webhook SePay giữ đường dẫn cũ (/hook/sepay-gateway/v1/add-payment, /api/sepay/*) ngoài /api/v1.
			Root: tuition.NewHandler(tuitionSvc, log).RootRoutes(iam.SameOrigin, auth.LoadSession),
			API: apiRoutes(auth, platform{
				auditMW: audit.Middleware(d.pool, log, "/api/v1", iam.CurrentUserID),
				audit:   auditapi.NewHandler(auditapi.NewService(d.pool, log), log),
				notify:  notifyapi.NewHandler(notifyapi.NewService(d.pool), log),
				// Module mới có nhóm tìm kiếm thì thêm: search.Builtin(d.iam).Add(<module>.Searcher(...)).
				search: search.NewHandler(search.Builtin(d.iam).Add(crm.Searcher(crmSvc)).Add(students.Searcher(studentSvc)), log),
			},
				students.NewHandler(studentSvc, log),
				inventory.NewHandler(inv, log),
				catalogs.NewHandler(catalogSvc, log),
				classes.NewHandler(cls, log),
				tasks.NewHandler(taskSvc, log),
				place,
				reports.NewHandler(reportSvc, log),
				crm.NewHandler(crmSvc, log),
				tuition.NewHandler(tuitionSvc, log),
				finance.NewHandler(finSvc, log),
				kpi.NewHandler(kpiSvc, log),
				mailapi.NewHandler(mailSvc, log),
				dashboard.NewHandler(dash, log),
				files.NewHandler(fileSvc, log),
				portal.NewHandler(portalSvc, log),
				teacher.NewHandler(teacherSvc, log),
				attendance.NewHandler(attSvc, log),
				tickets.NewHandler(ticketSvc, log),
				approvals.NewHandler(approvalSvc, log),
				// Khảo sát: đợt khảo sát (survey.manage); học viên gửi / xóa khảo sát qua API Cổng PH/HS.
				surveys.NewHandler(surveySvc, log),
				quality.NewHandler(qualitySvc, log),
				projects.NewHandler(projectSvc, log),
				payroll.NewHandler(payrollSvc, log),
				syllabus.NewHandler(sylSvc, log),
			),
		}),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       60 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	errc := make(chan error, 1)
	go func() {
		log.Info("api started", "addr", cfg.HTTPAddr, "env", cfg.Env, "version", version)
		errc <- srv.ListenAndServe()
	}()

	select {
	case err := <-errc:
		return err
	case <-ctx.Done():
	}
	log.Info("shutting down")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), cfg.ShutdownTimeout)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}

// openFiles dựng kho file theo cấu hình; URL tải của driver local ký bằng khóa suy ra từ APP_ENCRYPTION_KEY.
func openFiles(cfg config.Config, log *slog.Logger, pool *pgxpool.Pool) (*files.Service, error) {
	key, err := filestore.DeriveKey(cfg.EncryptionKey)
	if err != nil {
		return nil, err
	}
	fc := cfg.Files
	store, err := filestore.New(filestore.Config{
		Driver: fc.Driver, Dir: fc.Dir, URLPrefix: files.APIBase + files.BlobPrefix, SignKey: key,
		S3Endpoint: fc.S3Endpoint, S3Bucket: fc.S3Bucket, S3AccessKey: fc.S3AccessKey, S3SecretKey: fc.S3SecretKey,
		S3Region: fc.S3Region, S3UseSSL: fc.S3UseSSL,
	})
	if err != nil {
		return nil, fmt.Errorf("kho file: %w", err)
	}
	log.Info("file store", "driver", store.Driver())
	return files.NewService(pool, store, log, fc.URLTTL), nil
}

// runMigrate chạy goose; sau "up" thì đồng bộ danh mục quyền và 9 vai trò, rồi bỏ cache quyền của mọi người.
func runMigrate(ctx context.Context, args []string) error {
	if len(args) == 0 {
		args = []string{"up"}
	}
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	pool, err := store.OpenPostgres(ctx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer pool.Close()
	if err := store.Migrate(ctx, pool, args[0], args[1:]...); err != nil || args[0] != "up" {
		return err
	}
	rep, err := iam.SyncCatalog(ctx, pool)
	if err != nil {
		return fmt.Errorf("đồng bộ danh mục quyền: %w", err)
	}
	fmt.Printf("Danh mục quyền: %d quyền; vai trò mới: %v; quyền đã bỏ: %v\n", rep.Permissions, rep.CreatedRoles, rep.RemovedPermissions)
	nSLA, err := catalogs.SyncSLA(ctx, pool)
	if err != nil {
		return fmt.Errorf("đồng bộ danh mục SLA: %w", err)
	}
	fmt.Printf("Danh mục SLA: %d luật\n", nSLA)
	if cfg.RedisURL == "" {
		return nil
	}
	rdb, err := store.OpenRedis(cfg.RedisURL)
	if err != nil {
		return err
	}
	defer rdb.Close()
	return iam.InvalidateAllAccess(ctx, rdb)
}

// healthcheck dùng cho HEALTHCHECK của Docker: image distroless không có curl.
func healthcheck() error {
	addr := os.Getenv("HTTP_ADDR")
	if addr == "" || addr[0] == ':' {
		addr = "127.0.0.1" + addr
		if addr == "127.0.0.1" {
			addr += ":8080"
		}
	}
	client := http.Client{Timeout: 3 * time.Second}
	resp, err := client.Get("http://" + addr + "/healthz")
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("healthz trả về %d", resp.StatusCode)
	}
	return nil
}
