package students

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students/studentsdb"
)

var (
	ErrNotFound         = &iam.Error{Status: 404, Code: "student_not_found", Message: "Không tìm thấy học viên."}
	ErrGuardianNotFound = &iam.Error{Status: 404, Code: "guardian_not_found", Message: "Không tìm thấy phụ huynh của học viên."}
	ErrStale            = &iam.Error{Status: 409, Code: "stale", Message: "Hồ sơ vừa được người khác cập nhật, vui lòng tải lại trang."}
)

// ClassHooks là phần việc của phân hệ Lớp học (academic.classes, enrollments), gắn vào khi gộp. Chưa có thì:
// phạm vi "Của tôi" không thấy học viên nào, kết thúc bảo lưu về Chờ khai giảng, thôi học chỉ bỏ lớp hiện tại.
type ClassHooks interface {
	// VisibleClassIDs: lớp người dùng được xem; phạm vi học viên "Của tôi" = học viên thuộc các lớp này.
	VisibleClassIDs(ctx context.Context, actor *iam.Access) ([]uuid.UUID, error)
	// ClassStarted: lớp đã khai giảng (ngày bắt đầu ≤ hôm nay).
	ClassStarted(ctx context.Context, classID uuid.UUID) (bool, error)
	// DropEnrollments: thôi học → đóng các lượt xếp lớp còn hiệu lực (dropped, left_at hôm nay), cùng transaction.
	DropEnrollments(ctx context.Context, tx pgx.Tx, studentID uuid.UUID) error
}

// TuitionHooks là phần việc của phân hệ Học phí (khoảng bảo lưu, đóng băng số buổi / công nợ nằm ở khoản học phí).
// Chưa gắn thì hồ sơ không có hạn bảo lưu và kết thúc bảo lưu không đụng học phí.
type TuitionHooks interface {
	// DeferredUntil: ngày cuối bảo lưu ghi trên khoản học phí mới nhất (nil = không có).
	DeferredUntil(ctx context.Context, studentID uuid.UUID) (*time.Time, error)
	// ReleaseDeferral: bỏ đóng băng, xóa khoảng bảo lưu, nhắc nợ chạy lại (StudentDeferralService::releaseTuition).
	ReleaseDeferral(ctx context.Context, tx pgx.Tx, studentID, actor uuid.UUID) error
}

type Service struct {
	pool    *pgxpool.Pool
	q       *studentsdb.Queries
	iam     *iam.Service
	log     *slog.Logger
	now     func() time.Time
	Classes ClassHooks
	Tuition TuitionHooks
}

func NewService(pool *pgxpool.Pool, iamSvc *iam.Service, log *slog.Logger) *Service {
	return &Service{pool: pool, q: studentsdb.New(pool), iam: iamSvc, log: log, now: time.Now}
}

func (s *Service) today() time.Time { return dateOf(s.now()) }

// ───────────────────────── Phạm vi dữ liệu ─────────────────────────

// scope theo student.scope_*: Toàn hệ thống → mọi học viên; Chi nhánh → học viên thuộc chi nhánh của tôi;
// Của tôi → học viên thuộc các lớp tôi được xem (Student::scopeVisibleTo).
type scope struct {
	limitBranches bool
	branches      []uuid.UUID
	limitClasses  bool
	classes       []uuid.UUID
}

func (s *Service) scopeOf(ctx context.Context, actor *iam.Access) (scope, error) {
	switch actor.Level("student") {
	case iam.ScopeAll:
		return scope{}, nil
	case iam.ScopeBranch:
		// Học viên chưa gán chi nhánh xét theo chi nhánh của lớp đang học (truy vấn / inScope).
		return scope{limitBranches: true, branches: append([]uuid.UUID{}, actor.BranchIDs()...)}, nil
	}
	sc := scope{limitClasses: true, classes: []uuid.UUID{}}
	if s.Classes != nil {
		ids, err := s.Classes.VisibleClassIDs(ctx, actor)
		if err != nil {
			return scope{}, err
		}
		sc.classes = append(sc.classes, ids...)
	}
	return sc, nil
}

// inScope: covers, cộng phần cần tra lớp (học viên chưa gán chi nhánh thuộc lớp của chi nhánh mình; lượt xếp lớp
// còn hiệu lực ở lớp mình được xem).
func (s *Service) inScope(ctx context.Context, sc scope, id uuid.UUID, branch, class *uuid.UUID) (bool, error) {
	if sc.covers(branch, class) {
		return true, nil
	}
	if (sc.limitBranches && branch == nil && class != nil) || sc.limitClasses {
		return s.q.InScopeViaClasses(ctx, studentsdb.InScopeViaClassesParams{
			ID: id, LimitBranches: sc.limitBranches, BranchIds: nonNilIDs(sc.branches),
			LimitClasses: sc.limitClasses, ClassIds: nonNilIDs(sc.classes),
		})
	}
	return false, nil
}

func nonNilIDs(v []uuid.UUID) []uuid.UUID {
	if v == nil {
		return []uuid.UUID{}
	}
	return v
}

func (sc scope) covers(branch, class *uuid.UUID) bool {
	if sc.limitBranches && (branch == nil || !slices.Contains(sc.branches, *branch)) {
		return false
	}
	if sc.limitClasses && (class == nil || !slices.Contains(sc.classes, *class)) {
		return false
	}
	return true
}

// ───────────────────────── Danh sách ─────────────────────────

type Student struct {
	ID             uuid.UUID  `json:"id"`
	Code           string     `json:"code"`
	FullName       string     `json:"full_name"`
	DOB            *string    `json:"dob"`
	Phone          string     `json:"phone"`
	Email          *string    `json:"email"`
	BranchID       *uuid.UUID `json:"branch_id"`
	BranchName     *string    `json:"branch_name"`
	CurrentClassID *uuid.UUID `json:"current_class_id"`
	Status         string     `json:"status"`
	StatusLabel    string     `json:"status_label"`
	StudyStartedOn *string    `json:"study_started_on"`
	TenureLabel    *string    `json:"tenure_label"`
	LongTerm       bool       `json:"long_term"`
	CreatedAt      time.Time  `json:"created_at"`
}

type ListFilter struct {
	Search       string
	BranchID     *uuid.UUID
	ClassID      *uuid.UUID
	Statuses     []string
	Tenure       int
	Sort         string // "tenure" = học lâu nhất trước; mặc định hồ sơ mới nhất
	WithoutClass bool   // học viên chưa có lớp (chờ xếp lớp), bỏ học viên đã thôi học
	Page         int
	PerPage      int
}

type ListResult struct {
	Students     []Student `json:"students"`
	Total        int64     `json:"total"`
	Page         int       `json:"page"`
	PerPage      int       `json:"per_page"`
	TotalVisible int64     `json:"total_visible"`
	HasFilters   bool      `json:"has_filters"`
}

var perPageOptions = []int{10, 20, 50}

func likePattern(q string) *string {
	q = strings.TrimSpace(q)
	if q == "" {
		return nil
	}
	p := "%" + strings.NewReplacer(`\`, `\\`, "%", `\%`, "_", `\_`).Replace(q) + "%"
	return &p
}

func (s *Service) List(ctx context.Context, actor *iam.Access, f ListFilter) (*ListResult, error) {
	sc, err := s.scopeOf(ctx, actor)
	if err != nil {
		return nil, err
	}
	if !slices.Contains(perPageOptions, f.PerPage) {
		f.PerPage = 20
	}
	if f.Page < 1 {
		f.Page = 1
	}
	statuses := []string{}
	for _, st := range f.Statuses {
		if validStatus(st) && !slices.Contains(statuses, st) {
			statuses = append(statuses, st)
		}
	}
	today := s.today()
	p := studentsdb.ListStudentsParams{
		LimitBranches: sc.limitBranches, BranchIds: sc.branches, LimitClasses: sc.limitClasses, ClassIds: sc.classes,
		Search: likePattern(f.Search), BranchID: f.BranchID, ClassID: f.ClassID, Statuses: statuses,
		WithoutClass: f.WithoutClass, SortTenure: f.Sort == "tenure",
		Lim: int32(f.PerPage), Off: int32((f.Page - 1) * f.PerPage),
	}
	if slices.Contains(TenureFilters, f.Tenure) {
		by := today.AddDate(-f.Tenure, 0, 0)
		p.StartedBy = &by
	}
	rows, err := s.q.ListStudents(ctx, p)
	if err != nil {
		return nil, err
	}
	visible, err := s.q.CountVisibleStudents(ctx, studentsdb.CountVisibleStudentsParams{
		LimitBranches: sc.limitBranches, BranchIds: sc.branches, LimitClasses: sc.limitClasses, ClassIds: sc.classes,
	})
	if err != nil {
		return nil, err
	}
	out := &ListResult{
		Students: make([]Student, 0, len(rows)), Page: f.Page, PerPage: f.PerPage, TotalVisible: visible,
		HasFilters: p.Search != nil || f.BranchID != nil || f.ClassID != nil || len(statuses) > 0 || p.StartedBy != nil || f.WithoutClass,
	}
	for _, r := range rows {
		out.Total = r.Total
		out.Students = append(out.Students, Student{
			ID: r.ID, Code: r.Code, FullName: r.FullName, DOB: fmtDate(r.Dob), Phone: r.Phone, Email: realEmail(r.Email),
			BranchID: r.BranchID, BranchName: r.BranchName, CurrentClassID: r.CurrentClassID,
			Status: r.Status, StatusLabel: StatusLabel(r.Status), StudyStartedOn: fmtDate(r.StudyStartedOn),
			TenureLabel: TenureLabel(r.StudyStartedOn, r.Status, today), LongTerm: LongTerm(r.StudyStartedOn, r.Status, today),
			CreatedAt: r.CreatedAt,
		})
	}
	return out, nil
}

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

type BranchOption struct {
	ID   uuid.UUID `json:"id"`
	Code string    `json:"code"`
	Name string    `json:"name"`
}

// Options: bộ lọc của danh sách (chi nhánh trong phạm vi, 6 trạng thái, thâm niên, sắp xếp).
type Options struct {
	Branches []BranchOption `json:"branches"`
	Statuses []StatusOption `json:"statuses"`
	Tenures  []Option       `json:"tenures"`
	Sorts    []Option       `json:"sorts"`
}

func (s *Service) Options(ctx context.Context, actor *iam.Access) (*Options, error) {
	limit := actor.BranchLimit("student")
	rows, err := s.q.ListBranchOptions(ctx, studentsdb.ListBranchOptionsParams{Limited: limit != nil, Ids: append([]uuid.UUID{}, limit...)})
	if err != nil {
		return nil, err
	}
	out := &Options{Branches: make([]BranchOption, 0, len(rows)), Statuses: Statuses, Sorts: []Option{{"tenure", "Học lâu nhất"}}}
	for _, b := range rows {
		out.Branches = append(out.Branches, BranchOption{b.ID, b.Code, b.Name})
	}
	for _, y := range TenureFilters {
		out.Tenures = append(out.Tenures, Option{fmt.Sprint(y), fmt.Sprintf("Từ %d năm trở lên", y)})
	}
	return out, nil
}

// ───────────────────────── Hồ sơ ─────────────────────────

type Account struct {
	Login              *string    `json:"login"`
	Status             string     `json:"status"`
	MustChangePassword bool       `json:"must_change_password"`
	LastLoginAt        *time.Time `json:"last_login_at"`
}

type Guardian struct {
	ID                 uuid.UUID  `json:"id"`
	FullName           string     `json:"full_name"`
	Phone              *string    `json:"phone"`
	Relationship       *string    `json:"relationship"`
	Status             string     `json:"status"`
	MustChangePassword bool       `json:"must_change_password"`
	LastLoginAt        *time.Time `json:"last_login_at"`
	Children           int64      `json:"children"`
	LinkedAt           time.Time  `json:"linked_at"`
}

type Event struct {
	ID         uuid.UUID `json:"id"`
	Kind       string    `json:"kind"`
	Message    string    `json:"message"`
	FromStatus *string   `json:"from_status"`
	ToStatus   *string   `json:"to_status"`
	ActorName  *string   `json:"actor_name"`
	CreatedAt  time.Time `json:"created_at"`
}

// View: phần người xem được thấy / làm (StudentProfileController::show và scoped).
type View struct {
	Scoped          bool `json:"scoped"`
	CanEdit         bool `json:"can_edit"`
	CanChangeStatus bool `json:"can_change_status"`
	CanAssignClass  bool `json:"can_assign_class"`
	CanDelete       bool `json:"can_delete"`
	CanViewAcademic bool `json:"can_view_academic"`
	CanViewContact  bool `json:"can_view_contact"`
	CanViewTuition  bool `json:"can_view_tuition"`
}

type Profile struct {
	ID             uuid.UUID  `json:"id"`
	Code           string     `json:"code"`
	FullName       string     `json:"full_name"`
	DOB            *string    `json:"dob"`
	Age            *int       `json:"age"`
	Gender         *string    `json:"gender"`
	Phone          *string    `json:"phone"`
	Email          *string    `json:"email"`
	Address        *string    `json:"address"`
	ParentName     *string    `json:"parent_name"`
	ParentPhone    *string    `json:"parent_phone"`
	School         *string    `json:"school"`
	Target         *string    `json:"target"`
	EntranceScore  *string    `json:"entrance_score"`
	Notes          *string    `json:"notes"`
	BranchID       *uuid.UUID `json:"branch_id"`
	BranchName     *string    `json:"branch_name"`
	CurrentClassID *uuid.UUID `json:"current_class_id"`
	LeadID         *uuid.UUID `json:"lead_id"`
	Status         string     `json:"status"`
	StatusLabel    string     `json:"status_label"`
	StudyStartedOn *string    `json:"study_started_on"`
	TenureLabel    *string    `json:"tenure_label"`
	LongTerm       bool       `json:"long_term"`
	// Hạn bảo lưu nằm ở khoản học phí (phân hệ Học phí, TuitionHooks)
	DeferredUntil *string    `json:"deferred_until"`
	CreatedAt     time.Time  `json:"created_at"`
	UpdatedAt     time.Time  `json:"updated_at"`
	Version       int32      `json:"version"`
	Account       *Account   `json:"account"`
	Guardians     []Guardian `json:"guardians"`
	History       []Event    `json:"history"`
	View          View       `json:"view"`
}

func age(dob *time.Time, today time.Time) *int {
	if dob == nil {
		return nil
	}
	a := today.Year() - dob.Year()
	if today.Month() < dob.Month() || (today.Month() == dob.Month() && today.Day() < dob.Day()) {
		a--
	}
	return &a
}

// find tìm học viên theo id hoặc mã trong phạm vi người dùng; ngoài phạm vi trả 404 như Laravel.
func (s *Service) find(ctx context.Context, actor *iam.Access, idOrCode string) (studentsdb.GetStudentRow, error) {
	p := studentsdb.GetStudentParams{}
	if id, err := uuid.Parse(idOrCode); err == nil {
		p.ID = &id
	} else {
		code := strings.ToUpper(strings.TrimSpace(idOrCode))
		p.Code = &code
	}
	st, err := s.q.GetStudent(ctx, p)
	if errors.Is(err, pgx.ErrNoRows) {
		return st, ErrNotFound
	}
	if err != nil {
		return st, err
	}
	sc, err := s.scopeOf(ctx, actor)
	if err != nil {
		return st, err
	}
	if ok, err := s.inScope(ctx, sc, st.ID, st.BranchID, st.CurrentClassID); err != nil {
		return st, err
	} else if !ok {
		return st, ErrNotFound
	}
	return st, nil
}

// Visible: học viên nằm trong phạm vi xem của người dùng (Student::visibleTo) — cổng học viên dùng khi nhân sự xem hộ.
func (s *Service) Visible(ctx context.Context, actor *iam.Access, id uuid.UUID) (bool, error) {
	_, err := s.find(ctx, actor, id.String())
	if errors.Is(err, ErrNotFound) {
		return false, nil
	}
	return err == nil, err
}

// Get trả hồ sơ học viên. scoped = "Xem theo phân quyền": chỉ gửi phần người xem có quyền (lớp học / điểm danh cần
// attendance_student.view hoặc student.update; liên hệ cần student.update hoặc tuition.view).
func (s *Service) Get(ctx context.Context, actor *iam.Access, idOrCode string, scoped bool) (*Profile, error) {
	st, err := s.find(ctx, actor, idOrCode)
	if err != nil {
		return nil, err
	}
	v := View{
		Scoped: scoped, CanEdit: actor.Can("student.update"), CanChangeStatus: actor.Can("student.change_status"),
		CanAssignClass: actor.Can("student.assign_class"), CanDelete: actor.Can("student.delete"),
		CanViewAcademic: true, CanViewContact: true, CanViewTuition: actor.Can("tuition.view"),
	}
	if scoped {
		v.CanViewAcademic = actor.Can("attendance_student.view") || v.CanEdit
		v.CanViewContact = v.CanEdit || v.CanViewTuition
	}
	today := s.today()
	p := &Profile{
		ID: st.ID, Code: st.Code, FullName: st.FullName, DOB: fmtDate(st.Dob), Age: age(st.Dob, today), Gender: st.Gender,
		School: st.School, Target: st.Target, EntranceScore: st.EntranceScore, Notes: st.Notes,
		BranchID: st.BranchID, BranchName: st.BranchName, CurrentClassID: st.CurrentClassID, LeadID: st.LeadID,
		Status: st.Status, StatusLabel: StatusLabel(st.Status), StudyStartedOn: fmtDate(st.StudyStartedOn),
		TenureLabel: TenureLabel(st.StudyStartedOn, st.Status, today), LongTerm: LongTerm(st.StudyStartedOn, st.Status, today),
		CreatedAt: st.CreatedAt, UpdatedAt: st.UpdatedAt, Version: st.Version,
		Guardians: []Guardian{}, History: []Event{}, View: v,
	}
	if s.Tuition != nil && st.Status == StatusDeferred {
		until, err := s.Tuition.DeferredUntil(ctx, st.ID)
		if err != nil {
			return nil, err
		}
		p.DeferredUntil = fmtDate(until)
	}
	if v.CanViewContact {
		p.Phone, p.Email, p.Address, p.ParentName, p.ParentPhone = &st.Phone, st.Email, st.Address, st.ParentName, st.ParentPhone
		guardians, err := s.q.ListGuardians(ctx, st.ID)
		if err != nil {
			return nil, err
		}
		for _, g := range guardians {
			p.Guardians = append(p.Guardians, Guardian{
				ID: g.GuardianID, FullName: g.FullName, Phone: g.Phone, Relationship: g.Relationship, Status: g.Status,
				MustChangePassword: g.MustChangePassword, LastLoginAt: g.LastLoginAt, Children: g.Children, LinkedAt: g.CreatedAt,
			})
		}
	}
	if st.UserID != nil && st.AccountStatus != nil {
		p.Account = &Account{Login: st.AccountCode, Status: *st.AccountStatus, LastLoginAt: st.AccountLastLoginAt}
		if st.AccountMustChangePassword != nil {
			p.Account.MustChangePassword = *st.AccountMustChangePassword
		}
	}
	if !scoped {
		events, err := s.q.ListEvents(ctx, st.ID)
		if err != nil {
			return nil, err
		}
		for _, e := range events {
			p.History = append(p.History, Event{
				ID: e.ID, Kind: e.Kind, Message: e.Message, FromStatus: e.FromStatus, ToStatus: e.ToStatus,
				ActorName: e.ActorName, CreatedAt: e.CreatedAt,
			})
		}
	}
	return p, nil
}

// ───────────────────────── Tạo (từ CRM) ─────────────────────────

// Created: hồ sơ vừa tạo kèm tài khoản cổng học viên. TemporaryPassword trống khi dùng lại tài khoản có sẵn.
type Created struct {
	ID                uuid.UUID `json:"id"`
	Code              string    `json:"code"`
	UserID            uuid.UUID `json:"user_id"`
	Login             string    `json:"login"`
	TemporaryPassword string    `json:"temporary_password,omitempty"`
}

// NextCode cấp trước một mã học viên HV-00001 (màn chốt hiện mã này trong nội dung chuyển khoản, rồi truyền lại vào
// CreateInput.Code). Mã cấp ra không bị dùng lại kể cả khi không chốt.
func (s *Service) NextCode(ctx context.Context, tx pgx.Tx) (string, error) {
	q := s.q
	if tx != nil {
		q = q.WithTx(tx)
	}
	for range 5 {
		n, err := q.NextStudentNumber(ctx)
		if err != nil {
			return "", err
		}
		code := fmt.Sprintf("HV-%05d", n)
		taken, err := q.StudentCodeTaken(ctx, code)
		if err != nil {
			return "", err
		}
		if !taken {
			return code, nil
		}
	}
	return "", errors.New("students: không cấp được mã học viên")
}

// Create tạo hồ sơ học viên khi chốt Lead, trong transaction tx của CRM (nil = tự mở transaction), kèm tài khoản cổng
// học viên như Laravel: Lead có email mà email đó là tài khoản học viên chưa gắn hồ sơ thì dùng lại; email thuộc tài khoản
// khác thì báo lỗi; chưa có thì tạo tài khoản mới (đăng nhập bằng mã học viên, mật khẩu tạm bắt đổi lần đầu, vai trò Học viên).
// Người gọi tự kiểm quyền chốt Lead và phạm vi của mình.
func (s *Service) Create(ctx context.Context, tx pgx.Tx, actor *iam.Access, in CreateInput) (*Created, error) {
	if tx == nil {
		var out *Created
		err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
			var err error
			out, err = s.Create(ctx, tx, actor, in)
			return err
		})
		return out, err
	}
	if err := in.validate(); err != nil {
		return nil, err
	}
	q := s.q.WithTx(tx)
	if in.BranchID != nil {
		ok, err := q.BranchExists(ctx, *in.BranchID)
		if err != nil {
			return nil, err
		}
		if !ok {
			return nil, iam.Invalid("Chi nhánh không tồn tại.")
		}
	}
	code := strings.ToUpper(strings.TrimSpace(in.Code))
	if code != "" && codeRe.MatchString(code) {
		if taken, err := q.StudentCodeTaken(ctx, code); err != nil {
			return nil, err
		} else if taken {
			code = ""
		}
	} else {
		code = ""
	}
	if code == "" {
		var err error
		if code, err = s.NextCode(ctx, tx); err != nil {
			return nil, err
		}
	}

	out := &Created{Code: code, Login: code}
	email := strings.ToLower(strings.TrimSpace(in.Email))
	var existing *iam.Account
	if email != "" {
		var err error
		if existing, err = s.iam.AccountByEmailTx(ctx, tx, email); err != nil {
			return nil, err
		}
	}
	if existing != nil {
		// Chỉ dùng lại tài khoản cổng học viên chưa gắn hồ sơ; tài khoản nhân sự / phụ huynh → báo trùng email.
		linked, err := q.StudentUserLinked(ctx, &existing.ID)
		if err != nil {
			return nil, err
		}
		if linked || existing.Kind != "student" {
			return nil, iam.Invalid("Email của Lead đã thuộc một tài khoản khác. Vui lòng cập nhật email riêng cho học viên.")
		}
		if existing.Status != "active" {
			if err := s.iam.ActivateAccountTx(ctx, tx, existing.ID); err != nil {
				return nil, err
			}
		}
		out.UserID = existing.ID
		if existing.Code != nil {
			out.Login = *existing.Code
		} else if existing.Email != nil {
			out.Login = *existing.Email
		}
	} else {
		u, password, err := s.iam.CreateAccountTx(ctx, tx, iam.AccountInput{
			NewUser: iam.NewUser{Kind: "student", Code: code, Email: email, Phone: in.Phone, FullName: in.FullName},
			Roles:   []string{iam.RoleStudent}, BranchID: in.BranchID, CreatedBy: actor.UserID,
		})
		if err != nil {
			return nil, err
		}
		out.UserID, out.TemporaryPassword = u.ID, password
	}

	id, err := q.CreateStudent(ctx, studentsdb.CreateStudentParams{
		Code: code, UserID: &out.UserID, FullName: strings.TrimSpace(in.FullName), Phone: strings.TrimSpace(in.Phone),
		Email: strPtr(email), Dob: in.DOB, Gender: strPtr(in.Gender), Address: strPtr(in.Address), School: strPtr(in.School),
		ParentName: strPtr(in.ParentName), ParentPhone: strPtr(in.ParentPhone), BranchID: in.BranchID,
		CurrentClassID: in.CurrentClassID, LeadID: in.LeadID, Target: strPtr(in.Target),
		EntranceScore: strPtr(in.EntranceScore), TotalLessons: in.TotalLessons, CreatedBy: &actor.UserID,
	})
	if err != nil {
		return nil, err
	}
	out.ID = id
	msg := "Tạo hồ sơ học viên " + code + "."
	if in.LeadID != nil {
		msg = "Tạo hồ sơ học viên " + code + " khi chốt Lead."
	}
	if err := s.event(ctx, q, id, "created", msg, nil, ptr(StatusWaitingStart), actor); err != nil {
		return nil, err
	}
	name := strings.TrimSpace(in.FullName)
	if err := audit.Record(ctx, tx, audit.Entry{
		ActorID: &actor.UserID, Module: audit.ModuleStudents, Event: audit.EventCreated, Action: "student.create",
		SubjectType: "student", SubjectID: id.String(), SubjectLabel: name + " (" + code + ")", BranchID: in.BranchID,
		Description: `Tạo mới học viên "` + name + `"`,
	}); err != nil {
		return nil, err
	}
	s.log.Info("student created", "student_id", id, "code", code, "lead_id", in.LeadID, "by", actor.UserID)
	return out, nil
}

func ptr[T any](v T) *T { return &v }

func (s *Service) event(ctx context.Context, q *studentsdb.Queries, id uuid.UUID, kind, msg string, from, to *string, actor *iam.Access) error {
	return q.AddEvent(ctx, studentsdb.AddEventParams{
		StudentID: id, Kind: kind, Message: msg, FromStatus: from, ToStatus: to, CreatedBy: &actor.UserID,
	})
}

// ───────────────────────── Sửa, đổi trạng thái, bảo lưu, xóa ─────────────────────────

// locked khóa dòng học viên trong tx và kiểm phạm vi người dùng.
func (s *Service) locked(ctx context.Context, q *studentsdb.Queries, actor *iam.Access, id uuid.UUID) (studentsdb.AcademicStudent, error) {
	st, err := q.LockStudent(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return st, ErrNotFound
	}
	if err != nil {
		return st, err
	}
	sc, err := s.scopeOf(ctx, actor)
	if err != nil {
		return st, err
	}
	if ok, err := s.inScope(ctx, sc, st.ID, st.BranchID, st.CurrentClassID); err != nil {
		return st, err
	} else if !ok {
		return st, ErrNotFound
	}
	return st, nil
}

func eqStr(a *string, b string) bool {
	if a == nil {
		return strings.TrimSpace(b) == ""
	}
	return *a == strings.TrimSpace(b)
}

func eqDate(a, b *time.Time) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return a.Equal(*b)
}

// Update sửa hồ sơ (form "Chỉnh sửa thông tin"). Trạng thái chỉ đổi khi có student.change_status.
func (s *Service) Update(ctx context.Context, actor *iam.Access, id uuid.UUID, in UpdateInput) error {
	vals, err := in.validate(s.today())
	if err != nil {
		return err
	}
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		st, err := s.locked(ctx, q, actor, id)
		if err != nil {
			return err
		}
		if in.Version != nil && *in.Version != st.Version {
			return ErrStale
		}
		var changed []string
		for _, f := range []struct {
			label string
			same  bool
		}{
			{"họ tên", st.FullName == strings.TrimSpace(in.FullName)}, {"số điện thoại", st.Phone == strings.TrimSpace(in.Phone)},
			{"email", eqStr(st.Email, in.Email)}, {"ngày sinh", eqDate(st.Dob, vals.dob)}, {"giới tính", eqStr(st.Gender, in.Gender)},
			{"mục tiêu học tập", eqStr(st.Target, in.Target)}, {"địa chỉ", eqStr(st.Address, in.Address)},
			{"trường học", eqStr(st.School, in.School)}, {"họ tên phụ huynh", eqStr(st.ParentName, in.ParentName)},
			{"SĐT phụ huynh", eqStr(st.ParentPhone, in.ParentPhone)}, {"ngày bắt đầu học", eqDate(st.StudyStartedOn, vals.studyStartedOn)},
			{"ghi chú", eqStr(st.Notes, in.Notes)},
		} {
			if !f.same {
				changed = append(changed, f.label)
			}
		}
		email := strPtr(in.Email)
		if email != nil {
			*email = strings.ToLower(*email)
		}
		p := studentsdb.UpdateStudentParams{
			ID: id, FullName: strings.TrimSpace(in.FullName), Phone: strings.TrimSpace(in.Phone), Email: email,
			Dob: vals.dob, Gender: strPtr(in.Gender), Target: strPtr(in.Target), Address: strPtr(in.Address),
			School: strPtr(in.School), ParentName: strPtr(in.ParentName), ParentPhone: strPtr(in.ParentPhone),
			StudyStartedOn: vals.studyStartedOn, Notes: strPtr(in.Notes),
		}
		if _, err = q.UpdateStudent(ctx, p); err != nil {
			return err
		}
		// TODO(attendance): xóa ngày bắt đầu học → lấy lại theo buổi có mặt / đi muộn đầu tiên (Student::recordStudyStart).
		if len(changed) > 0 {
			if err := s.event(ctx, q, id, "updated", "Cập nhật hồ sơ: "+strings.Join(changed, ", ")+".", nil, nil, actor); err != nil {
				return err
			}
		}
		if len(changed) > 0 {
			after := st
			after.FullName, after.Phone, after.Email, after.Dob, after.Gender = p.FullName, p.Phone, p.Email, p.Dob, p.Gender
			after.Target, after.Address, after.School, after.ParentName = p.Target, p.Address, p.School, p.ParentName
			after.ParentPhone, after.StudyStartedOn, after.Notes = p.ParentPhone, p.StudyStartedOn, p.Notes
			if err := recordStudent(ctx, tx, actor, after, audit.EventUpdated, "student.update", "Cập nhật hồ sơ học viên "+after.FullName,
				audit.Diff(studentSnapshot(st), studentSnapshot(after))); err != nil {
				return err
			}
		}
		if status := strings.TrimSpace(in.Status); status != "" && status != st.Status && actor.Can("student.change_status") {
			if _, err := s.applyStatus(ctx, tx, actor, st, status); err != nil {
				return err
			}
		}
		s.log.Info("student updated", "student_id", id, "by", actor.UserID)
		return nil
	})
}

// applyStatus đổi trạng thái trong tx và ghi lịch sử; trả thông báo cho người dùng.
func (s *Service) applyStatus(ctx context.Context, tx pgx.Tx, actor *iam.Access, st studentsdb.AcademicStudent, status string) (string, error) {
	q := s.q.WithTx(tx)
	msg := fmt.Sprintf("Đã chuyển trạng thái học viên %s từ \"%s\" sang \"%s\".", st.FullName, StatusLabel(st.Status), StatusLabel(status))
	if status == st.Status {
		return msg, nil
	}
	if err := q.SetStudentStatus(ctx, studentsdb.SetStudentStatusParams{ID: st.ID, Status: status}); err != nil {
		return "", err
	}
	if status == StatusDropped {
		// Thôi học: rời lớp đang học, đóng lượt xếp lớp còn hiệu lực; giữ lịch sử học tập.
		if s.Classes != nil {
			if err := s.Classes.DropEnrollments(ctx, tx, st.ID); err != nil {
				return "", err
			}
		}
		msg += " Học viên đã được đưa ra khỏi danh sách lớp đang học; lịch sử học tập vẫn được giữ."
	}
	// Rời "Bảo lưu" bằng tay → bỏ đóng băng học phí (StudentDeferralService::releaseTuition).
	if st.Status == StatusDeferred && s.Tuition != nil {
		if err := s.Tuition.ReleaseDeferral(ctx, tx, st.ID, actor.UserID); err != nil {
			return "", err
		}
	}
	text := fmt.Sprintf("Đổi trạng thái: %s → %s.", StatusLabel(st.Status), StatusLabel(status))
	if err := s.event(ctx, q, st.ID, "status_changed", text, &st.Status, &status, actor); err != nil {
		return "", err
	}
	s.log.Info("student status changed", "student_id", st.ID, "from", st.Status, "to", status, "by", actor.UserID)
	return msg, nil
}

// DropForCancelledClosing: CRM hủy chốt khách Chờ xếp lớp → học viên chuyển Thôi học, ghi lý do vào ghi chú hồ sơ
// (CrmController::cancelClosing). Chạy trong giao dịch của CRM; quyền đã kiểm ở CRM (lead.stage_back).
func (s *Service) DropForCancelledClosing(ctx context.Context, tx pgx.Tx, actor *iam.Access, id uuid.UUID, reason string) error {
	q := s.q.WithTx(tx)
	st, err := q.LockStudent(ctx, id)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil
	}
	if err != nil {
		return err
	}
	if _, err := s.applyStatus(ctx, tx, actor, st, StatusDropped); err != nil {
		return err
	}
	return q.AppendStudentNote(ctx, studentsdb.AppendStudentNoteParams{ID: id, Line: "Huỷ chốt " + s.today().Format("02/01/2006") + ": " + reason})
}

// ChangeStatus: đổi trạng thái học tập (6 trạng thái) qua thao tác riêng, tách khỏi sửa hồ sơ.
func (s *Service) ChangeStatus(ctx context.Context, actor *iam.Access, id uuid.UUID, status string) (string, error) {
	status = strings.TrimSpace(status)
	if status == "" {
		return "", iam.Invalid("Vui lòng chọn trạng thái.")
	}
	if !validStatus(status) {
		return "", iam.Invalid("Giá trị đã chọn trong trường trạng thái không hợp lệ.")
	}
	var msg string
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		st, err := s.locked(ctx, s.q.WithTx(tx), actor, id)
		if err != nil {
			return err
		}
		msg, err = s.applyStatus(ctx, tx, actor, st, status)
		return err
	})
	return msg, err
}

// EndDeferral: "Kết thúc bảo lưu" thủ công. Lớp đang học đã khai giảng → Đang học, còn lại → Chờ khai giảng.
func (s *Service) EndDeferral(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	var msg string
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		st, err := s.locked(ctx, q, actor, id)
		if err != nil {
			return err
		}
		if st.Status != StatusDeferred {
			return iam.Invalid("Học viên không ở trạng thái Bảo lưu.")
		}
		next := StatusWaitingStart
		if st.CurrentClassID != nil && s.Classes != nil {
			started, err := s.Classes.ClassStarted(ctx, *st.CurrentClassID)
			if err != nil {
				return err
			}
			if started {
				next = StatusStudying
			}
		}
		if err := q.SetStudentStatus(ctx, studentsdb.SetStudentStatusParams{ID: id, Status: next}); err != nil {
			return err
		}
		// Bỏ đóng băng số buổi / công nợ, xóa khoảng bảo lưu, nhắc nợ chạy lại (StudentDeferralService::releaseTuition).
		if s.Tuition != nil {
			if err := s.Tuition.ReleaseDeferral(ctx, tx, id, actor.UserID); err != nil {
				return err
			}
		}
		if err := notifyDeferralEnded(ctx, tx, &actor.UserID, st, next); err != nil {
			return err
		}
		text := "Kết thúc bảo lưu → " + StatusLabel(next) + "."
		if err := s.event(ctx, q, id, "deferral_ended", text, &st.Status, &next, actor); err != nil {
			return err
		}
		msg = fmt.Sprintf("Đã kết thúc bảo lưu cho học viên %s: chuyển sang \"%s\".", st.FullName, StatusLabel(next))
		s.log.Info("student deferral ended", "student_id", id, "status", next, "by", actor.UserID)
		return nil
	})
	return msg, err
}

// Delete xóa mềm hồ sơ (lịch sử, điểm danh, học phí giữ nguyên).
func (s *Service) Delete(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	var msg string
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		st, err := s.locked(ctx, q, actor, id)
		if err != nil {
			return err
		}
		if err := s.event(ctx, q, id, "deleted", "Xóa hồ sơ học viên.", nil, nil, actor); err != nil {
			return err
		}
		if err := q.SoftDeleteStudent(ctx, id); err != nil {
			return err
		}
		if err := recordStudent(ctx, tx, actor, st, audit.EventDeleted, "student.delete", "Xóa hồ sơ học viên "+st.FullName,
			audit.Diff(studentSnapshot(st), nil)); err != nil {
			return err
		}
		msg = fmt.Sprintf("Đã xóa hồ sơ học viên %s!", st.FullName)
		s.log.Info("student deleted", "student_id", id, "code", st.Code, "by", actor.UserID)
		return nil
	})
	return msg, err
}

// ───────────────────────── Phụ huynh, tài khoản ─────────────────────────

// GuardianResult: phụ huynh vừa gắn; TemporaryPassword chỉ có khi vừa tạo tài khoản mới (hiện một lần).
type GuardianResult struct {
	Guardian          Guardian `json:"guardian"`
	Created           bool     `json:"created"`
	Login             string   `json:"login"`
	TemporaryPassword string   `json:"temporary_password,omitempty"`
}

// AddGuardian gắn phụ huynh theo SĐT: đã có tài khoản phụ huynh với SĐT này (phụ huynh nhiều con) thì gắn luôn,
// chưa có thì tạo tài khoản phụ huynh (đăng nhập bằng SĐT, mật khẩu tạm, vai trò Học viên để vào cổng học viên).
func (s *Service) AddGuardian(ctx context.Context, actor *iam.Access, studentID uuid.UUID, in GuardianInput) (*GuardianResult, error) {
	if err := in.validate(); err != nil {
		return nil, err
	}
	phone := iam.NormalizePhone(in.Phone)
	out := &GuardianResult{Login: phone}
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		st, err := s.locked(ctx, q, actor, studentID)
		if err != nil {
			return err
		}
		acc, err := s.iam.GuardianByPhoneTx(ctx, tx, phone)
		if err != nil {
			return err
		}
		var gid uuid.UUID
		name := strings.TrimSpace(in.FullName)
		if acc != nil {
			gid, name = acc.ID, acc.FullName
		} else {
			u, password, err := s.iam.CreateAccountTx(ctx, tx, iam.AccountInput{
				NewUser: iam.NewUser{Kind: "guardian", Phone: phone, FullName: name},
				Roles:   []string{iam.RoleStudent}, BranchID: st.BranchID, CreatedBy: actor.UserID,
			})
			if err != nil {
				return err
			}
			gid, out.Created, out.TemporaryPassword = u.ID, true, password
		}
		n, err := q.LinkGuardian(ctx, studentsdb.LinkGuardianParams{
			StudentID: studentID, GuardianID: gid, Relationship: strPtr(in.Relationship), CreatedBy: &actor.UserID,
		})
		if err != nil {
			return err
		}
		if n == 0 {
			return iam.Invalid("Phụ huynh này đã được gắn với học viên.")
		}
		text := fmt.Sprintf("Gắn phụ huynh %s (%s).", name, phone)
		if out.Created {
			text = fmt.Sprintf("Gắn phụ huynh %s (%s), cấp tài khoản phụ huynh mới.", name, phone)
		}
		if err := s.event(ctx, q, studentID, "guardian_linked", text, nil, nil, actor); err != nil {
			return err
		}
		guardians, err := q.ListGuardians(ctx, studentID)
		if err != nil {
			return err
		}
		for _, g := range guardians {
			if g.GuardianID == gid {
				out.Guardian = Guardian{
					ID: g.GuardianID, FullName: g.FullName, Phone: g.Phone, Relationship: g.Relationship, Status: g.Status,
					MustChangePassword: g.MustChangePassword, LastLoginAt: g.LastLoginAt, Children: g.Children, LinkedAt: g.CreatedAt,
				}
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("guardian linked", "student_id", studentID, "guardian_id", out.Guardian.ID, "created", out.Created, "by", actor.UserID)
	return out, nil
}

// RemoveGuardian bỏ gắn phụ huynh (tài khoản phụ huynh giữ nguyên, có thể còn gắn với con khác).
func (s *Service) RemoveGuardian(ctx context.Context, actor *iam.Access, studentID, guardianID uuid.UUID) error {
	return pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		q := s.q.WithTx(tx)
		if _, err := s.locked(ctx, q, actor, studentID); err != nil {
			return err
		}
		acc, err := s.iam.AccountByIDTx(ctx, tx, guardianID)
		if err != nil {
			return err
		}
		n, err := q.UnlinkGuardian(ctx, studentsdb.UnlinkGuardianParams{StudentID: studentID, GuardianID: guardianID})
		if err != nil {
			return err
		}
		if n == 0 || acc == nil {
			return ErrGuardianNotFound
		}
		phone := ""
		if acc.Phone != nil {
			phone = " (" + *acc.Phone + ")"
		}
		s.log.Info("guardian unlinked", "student_id", studentID, "guardian_id", guardianID, "by", actor.UserID)
		return s.event(ctx, q, studentID, "guardian_unlinked", "Bỏ gắn phụ huynh "+acc.FullName+phone+".", nil, nil, actor)
	})
}

// AccountReset: thông tin đăng nhập vừa cấp lại (mật khẩu chỉ hiện một lần).
type AccountReset struct {
	Login             string `json:"login"`
	TemporaryPassword string `json:"temporary_password"`
	Message           string `json:"message"`
}

// ResetAccount cấp mật khẩu tạm cho tài khoản cổng học viên (CrmController::resetStudentAccount): chỉ tài khoản thuần
// học viên, không đụng tài khoản nhân sự.
func (s *Service) ResetAccount(ctx context.Context, actor *iam.Access, id uuid.UUID) (*AccountReset, error) {
	var acc *iam.Account
	var st studentsdb.AcademicStudent
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		var err error
		if st, err = s.locked(ctx, s.q.WithTx(tx), actor, id); err != nil {
			return err
		}
		if st.UserID != nil {
			if acc, err = s.iam.AccountByIDTx(ctx, tx, *st.UserID); err != nil {
				return err
			}
		}
		if acc == nil || acc.Kind != "student" || !slices.Equal(acc.Roles, []string{iam.RoleStudent}) {
			return iam.Invalid("Học viên chưa có tài khoản cổng học viên riêng, liên hệ Admin để cấp tài khoản.")
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	password, err := s.iam.ResetAccountPassword(ctx, acc.ID)
	if err != nil {
		return nil, err
	}
	login := st.Code
	if acc.Code != nil {
		login = *acc.Code
	}
	err = pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		if err := s.event(ctx, s.q.WithTx(tx), id, "account_reset", "Cấp mật khẩu tạm cho tài khoản học viên "+login+".", nil, nil, actor); err != nil {
			return err
		}
		// Như Laravel: ghi vào phân hệ Người dùng & Phân quyền, đối tượng là tài khoản.
		label := st.FullName
		if acc.Code != nil {
			label += " (" + *acc.Code + ")"
		}
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleUsers, Event: audit.EventUpdated, Action: "student.account_reset",
			SubjectType: "user", SubjectID: acc.ID.String(), SubjectLabel: label, BranchID: st.BranchID,
			Description: "Cấp mật khẩu tạm cho tài khoản học viên (bắt buộc đổi ở lần đăng nhập tới)",
		})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("student account reset", "student_id", id, "user_id", acc.ID, "by", actor.UserID)
	return &AccountReset{
		Login: login, TemporaryPassword: password,
		Message: "Đã cấp mật khẩu tạm cho học viên " + st.FullName + ". Gửi thông tin đăng nhập cho phụ huynh; học viên phải đổi mật khẩu ở lần đăng nhập đầu.",
	}, nil
}
