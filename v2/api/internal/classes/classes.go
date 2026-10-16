package classes

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"sort"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/students"
)

// Option: một lựa chọn cho ô chọn.
type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

// ───────────────────────── Danh sách lớp ─────────────────────────

const classesPageSize = 20

type ClassRow struct {
	ID             uuid.UUID   `json:"id"`
	Code           string      `json:"code"`
	Name           string      `json:"name"`
	Status         StatusMeta  `json:"status"`
	Meta           string      `json:"meta"` // "IELTS · B1 · Chi nhánh A"
	ScheduleText   *string     `json:"schedule_text"`
	Teacher        *string     `json:"teacher"`
	ForeignTeacher *string     `json:"foreign_teacher"`
	Seat           Seat        `json:"seat"`
	TotalSessions  int64       `json:"total_sessions"`
	DoneSessions   int64       `json:"done_sessions"`
	Next           *NextAction `json:"next"`
}

type StatusCount struct {
	Key   string `json:"key"`
	Label string `json:"label"`
	Count int64  `json:"count"`
}

type LabelCount struct {
	Label string `json:"label"`
	Total int64  `json:"total"`
}

type ListFilter struct {
	Search   string
	BranchID *uuid.UUID
	Status   string
	Program  string
	Level    string
	Page     int
}

type ListStats struct {
	Active            int64 `json:"active"`
	PendingSchedule   int64 `json:"pending_schedule"`
	Short             int64 `json:"short"`              // lớp chưa khai giảng còn thiếu học viên
	MissingAttendance int64 `json:"missing_attendance"` // buổi hôm nay chưa điểm danh
}

type ListResult struct {
	Classes       []ClassRow    `json:"classes"`
	Total         int64         `json:"total"`
	Page          int           `json:"page"`
	PerPage       int           `json:"per_page"`
	Branches      []Option      `json:"branches"`
	Statuses      []StatusCount `json:"statuses"`
	OpenCount     int64         `json:"open_count"`
	ProgramCounts []LabelCount  `json:"program_counts"`
	LevelCounts   []LabelCount  `json:"level_counts"`
	Stats         ListStats     `json:"stats"`
	CanCreate     bool          `json:"can_create"`
}

func metaLine(parts ...*string) string {
	var out []string
	for _, p := range parts {
		if p != nil && *p != "" {
			out = append(out, *p)
		}
	}
	return strings.Join(out, " · ")
}

// List: Danh sách lớp — chip trạng thái (mặc định ẩn lớp đã hủy), chip chương trình / cấp độ (sơ đồ khối),
// tiến độ buổi học, sĩ số và việc tiếp theo.
func (s *Service) List(ctx context.Context, actor *iam.Access, f ListFilter) (*ListResult, error) {
	if err := ensureBrowse(actor); err != nil {
		return nil, err
	}
	sc, err := s.visible(ctx, actor)
	if err != nil {
		return nil, err
	}
	if f.Page < 1 {
		f.Page = 1
	}
	today := s.today()
	search := likeArg(f.Search)
	var status, program, level *string
	if validStatus(f.Status) {
		status = &f.Status
	}
	program, level = optText(f.Program), optText(f.Level)

	counts, err := s.q.ClassStatusCounts(ctx, classesdb.ClassStatusCountsParams{AllClasses: sc.all, Ids: sc.ids, BranchID: f.BranchID, Search: search})
	if err != nil {
		return nil, err
	}
	byStatus := map[string]int64{}
	for _, c := range counts {
		byStatus[c.Status] = c.Total
	}
	out := &ListResult{Classes: []ClassRow{}, Page: f.Page, PerPage: classesPageSize, CanCreate: actor.Can("class.create")}
	for _, st := range Statuses {
		out.Statuses = append(out.Statuses, StatusCount{st.Key, st.Label, byStatus[st.Key]})
		if st.Key != StatusCancelled {
			out.OpenCount += byStatus[st.Key]
		}
	}
	programs, err := s.q.ClassProgramCounts(ctx, classesdb.ClassProgramCountsParams{
		AllClasses: sc.all, Ids: sc.ids, BranchID: f.BranchID, Search: search, Status: status,
	})
	if err != nil {
		return nil, err
	}
	for _, p := range programs {
		out.ProgramCounts = append(out.ProgramCounts, LabelCount{p.Label, p.Total})
	}
	levels, err := s.q.ClassLevelCounts(ctx, classesdb.ClassLevelCountsParams{
		AllClasses: sc.all, Ids: sc.ids, BranchID: f.BranchID, Search: search, Status: status, Program: program,
	})
	if err != nil {
		return nil, err
	}
	for _, l := range levels {
		out.LevelCounts = append(out.LevelCounts, LabelCount{l.Label, l.Total})
	}
	out.ProgramCounts, out.LevelCounts = nonNil(out.ProgramCounts), nonNil(out.LevelCounts)

	rows, err := s.q.ListClasses(ctx, classesdb.ListClassesParams{
		Today: today, AllClasses: sc.all, Ids: sc.ids, BranchID: f.BranchID, Search: search, Status: status,
		Program: program, Level: level, Lim: classesPageSize, Off: int32((f.Page - 1) * classesPageSize),
	})
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		ids = append(ids, r.ID)
	}
	next := map[uuid.UUID]time.Time{}
	if len(ids) > 0 {
		ns, err := s.q.NextSessionDates(ctx, classesdb.NextSessionDatesParams{ClassIds: ids, Today: today})
		if err != nil {
			return nil, err
		}
		for _, n := range ns {
			next[n.ClassID] = n.NextDate
		}
	}
	for _, r := range rows {
		out.Total = r.Total
		seat := seatSummary(r.Status, r.StartDate, r.RosterCount, r.MaxCapacity, r.MinStudents, today)
		var nd *time.Time
		if d, ok := next[r.ID]; ok {
			nd = &d
		}
		out.Classes = append(out.Classes, ClassRow{
			ID: r.ID, Code: r.Code, Name: r.Name, Status: statusMeta(r.Status), Meta: metaLine(r.Program, r.Level, &r.BranchName),
			ScheduleText: r.ScheduleText, Teacher: r.TeacherName, ForeignTeacher: r.ForeignTeacherName, Seat: seat,
			TotalSessions: r.TotalSessions, DoneSessions: r.DoneSessions, Next: nextAction(r.Status, seat, nd),
		})
	}

	out.Stats.Active, out.Stats.PendingSchedule = byStatus[StatusActive], byStatus[StatusPendingSchedule]
	if out.Stats.Short, err = s.q.ShortClasses(ctx, classesdb.ShortClassesParams{AllClasses: sc.all, Ids: sc.ids, BranchID: f.BranchID, Search: search}); err != nil {
		return nil, err
	}
	if out.Stats.MissingAttendance, err = s.q.MissingAttendanceToday(ctx, classesdb.MissingAttendanceTodayParams{
		Today: today, AllClasses: sc.all, Ids: sc.ids, BranchID: f.BranchID,
	}); err != nil {
		return nil, err
	}
	branches, err := s.q.ActiveBranches(ctx)
	if err != nil {
		return nil, err
	}
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name})
	}
	out.Branches = nonNil(out.Branches)
	return out, nil
}

// ───────────────────────── Form tạo / sửa ─────────────────────────

type CourseOption struct {
	Value string `json:"value"` // tên khóa = giá trị "Chương trình"
	Label string `json:"label"`
	Fee   int64  `json:"fee"` // học phí niêm yết, điền sẵn khi chọn
}

type RoomOption struct {
	Value    uuid.UUID   `json:"value"`
	Label    string      `json:"label"` // "Phòng 101 • Phòng học • 20 chỗ"
	Name     string      `json:"name"`
	BranchID uuid.UUID   `json:"branch_id"`
	Capacity *int32      `json:"capacity"`
	Classes  []RoomUsage `json:"classes"` // lớp còn mở đang gắn phòng (trừ lớp đang sửa)
}

type FormOptions struct {
	Branches        []Option       `json:"branches"`
	Courses         []CourseOption `json:"courses"`
	Programs        []Option       `json:"programs"` // khi sửa: giá trị cũ cố định + khóa học đang mở
	Levels          []Option       `json:"levels"`
	Rooms           []RoomOption   `json:"rooms"`
	Teachers        []Option       `json:"teachers"`
	ForeignTeachers []Option       `json:"foreign_teachers"`
	Assistants      []Option       `json:"assistants"` // chỉ lớp cũ còn trợ giảng cố định
}

// legacyPrograms / legacyLevels: giá trị cũ cố định của form Sửa lớp.
var legacyPrograms = []Option{
	{"IELTS", "IELTS Học thuật (Academic)"}, {"TOEIC", "TOEIC 4 kỹ năng"}, {"COMMUNICATION", "Tiếng Anh Giao tiếp phản xạ"},
	{"JUNIOR", "Tiếng Anh Thiếu niên (Junior)"}, {"BUSINESS", "Tiếng Anh Doanh nghiệp"},
}

var legacyLevels = []Option{
	{"B1", "Cấp độ B1 (Mục tiêu 5.5 - 6.0)"}, {"FOUNDATION", "Foundation (Mục tiêu 4.0 - 5.0)"},
	{"B2", "Cấp độ B2 (Mục tiêu 6.5 - 7.0)"}, {"ADVANCED", "Mastery (Mục tiêu 7.5+)"},
}

func staffLabel(u classesdb.UsersWithPermissionRow) string {
	switch {
	case u.Email != "":
		return u.FullName + " (" + u.Email + ")"
	case u.Code != "":
		return u.FullName + " (" + u.Code + ")"
	}
	return u.FullName
}

// staffOptions: nhân sự đang hoạt động có quyền đối tượng perm (class.teach / class.assist).
func (s *Service) staffOptions(ctx context.Context, perm string, withContact bool) ([]Option, error) {
	rows, err := s.q.UsersWithPermission(ctx, perm)
	if err != nil {
		return nil, err
	}
	out := make([]Option, 0, len(rows))
	for _, u := range rows {
		label := u.FullName
		if withContact {
			label = staffLabel(u)
		}
		out = append(out, Option{u.ID.String(), label})
	}
	return out, nil
}

// roomOptions: phòng của các chi nhánh cho ô "Phòng học" (lọc theo chi nhánh lớp ở giao diện), kèm lớp đang dùng.
func (s *Service) roomOptions(ctx context.Context, branchIDs []uuid.UUID, exclude *uuid.UUID) ([]RoomOption, error) {
	rooms, err := s.q.BranchRooms(ctx, branchIDs)
	if err != nil {
		return nil, err
	}
	ids := make([]uuid.UUID, 0, len(rooms))
	for _, r := range rooms {
		ids = append(ids, r.ID)
	}
	usage, err := s.roomUsage(ctx, ids, exclude)
	if err != nil {
		return nil, err
	}
	out := make([]RoomOption, 0, len(rooms))
	for _, r := range rooms {
		label := r.Name + " • " + r.TypeName
		if r.Capacity != nil && *r.Capacity > 0 {
			label += fmt.Sprintf(" • %d chỗ", *r.Capacity)
		}
		out = append(out, RoomOption{Value: r.ID, Label: label, Name: r.Name, BranchID: r.BranchID, Capacity: r.Capacity,
			Classes: nonNil(usage[r.ID])})
	}
	return out, nil
}

// FormOptions: dữ liệu form Tạo lớp (classID nil) / Sửa lớp.
func (s *Service) FormOptions(ctx context.Context, actor *iam.Access, classID *uuid.UUID) (*FormOptions, error) {
	var current *classesdb.GetClassRow
	if classID == nil {
		if !actor.Can("class.create") {
			return nil, forbidden("Bạn không có quyền tạo lớp học.")
		}
	} else {
		if !actor.Can("class.update") {
			return nil, forbidden("Bạn không có quyền chỉnh sửa lớp học.")
		}
		c, err := s.classFor(ctx, actor, *classID, "class.update")
		if err != nil {
			return nil, err
		}
		current = &c
	}
	out := &FormOptions{Assistants: []Option{}}
	branches, err := s.q.ActiveBranches(ctx)
	if err != nil {
		return nil, err
	}
	branchIDs := []uuid.UUID{}
	for _, b := range branches {
		out.Branches = append(out.Branches, Option{b.ID.String(), b.Name + " (" + b.Code + ")"})
		branchIDs = append(branchIDs, b.ID)
	}
	courses, err := s.q.ActiveCourses(ctx)
	if err != nil {
		return nil, err
	}
	for _, c := range courses {
		out.Courses = append(out.Courses, CourseOption{c.Name, c.Name, c.TuitionFee})
		out.Programs = append(out.Programs, Option{c.Name, c.Name})
	}
	levels, err := s.q.ActiveLevels(ctx)
	if err != nil {
		return nil, err
	}
	for _, l := range levels {
		out.Levels = append(out.Levels, Option{l.Code, l.Name + " (" + l.Target + ")"})
	}
	if current != nil {
		out.Programs = append(slices.Clone(legacyPrograms), out.Programs...)
		out.Levels = append(slices.Clone(legacyLevels), out.Levels...)
		if !slices.Contains(branchIDs, current.BranchID) {
			branchIDs = append(branchIDs, current.BranchID)
		}
	}
	if out.Rooms, err = s.roomOptions(ctx, branchIDs, classID); err != nil {
		return nil, err
	}
	if out.Teachers, err = s.staffOptions(ctx, "class.teach", true); err != nil {
		return nil, err
	}
	if out.ForeignTeachers, err = s.staffOptions(ctx, "class.teach", false); err != nil {
		return nil, err
	}
	if current != nil && current.AssistantID != nil {
		if out.Assistants, err = s.staffOptions(ctx, "class.assist", false); err != nil {
			return nil, err
		}
	}
	out.Branches, out.Courses, out.Programs, out.Levels = nonNil(out.Branches), nonNil(out.Courses), nonNil(out.Programs), nonNil(out.Levels)
	return out, nil
}

// ───────────────────────── Tạo / sửa ─────────────────────────

// OptID: id có thể vắng (giữ nguyên giá trị hiện tại), null / "" (gỡ) hoặc một uuid.
type OptID struct {
	Set bool
	ID  *uuid.UUID
}

func (o *OptID) UnmarshalJSON(b []byte) error {
	o.Set = true
	var v *string
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	if v == nil || strings.TrimSpace(*v) == "" {
		o.ID = nil
		return nil
	}
	id, err := uuid.Parse(strings.TrimSpace(*v))
	if err != nil {
		return err
	}
	o.ID = &id
	return nil
}

// SessionDraft: một buổi của thời khóa biểu đã render sẵn trên form tạo lớp.
type SessionDraft struct {
	Date  string `json:"date"`
	Shift string `json:"shift"`
	Start string `json:"start"`
	End   string `json:"end"`
	Room  string `json:"room"`
}

type ClassInput struct {
	Name             string     `json:"name"`
	Code             string     `json:"code"`
	BranchID         *uuid.UUID `json:"branch_id"`
	Program          string     `json:"program"`
	Level            string     `json:"level"`
	MaxCapacity      *int       `json:"max_capacity"`
	MinStudents      *int       `json:"min_students"`
	RoomID           OptID      `json:"room_id"`
	TeacherID        OptID      `json:"teacher_id"`
	ForeignTeacherID OptID      `json:"foreign_teacher_id"`
	TuitionFee       *int64     `json:"tuition_fee"`
	Notes            *string    `json:"notes"`
	// Chỉ khi sửa
	AssistantID  OptID   `json:"assistant_id"`
	Status       *string `json:"status"`
	StartDate    *string `json:"start_date"`
	EndDate      *string `json:"end_date"`
	ScheduleText *string `json:"schedule_text"`
	// Chỉ khi tạo: thời khóa biểu đã render sẵn (tạo luôn buổi học và kích hoạt lớp)
	Sessions []SessionDraft `json:"schedule_sessions"`
}

func (in *ClassInput) validate() error {
	in.Name, in.Code = strings.TrimSpace(in.Name), strings.TrimSpace(in.Code)
	in.Program, in.Level = strings.TrimSpace(in.Program), strings.TrimSpace(in.Level)
	switch {
	case in.Name == "":
		return iam.Invalid("Chưa nhập Tên lớp.")
	case tooLong(in.Name, 255):
		return iam.Invalid("Tên lớp không được vượt quá 255 ký tự.")
	case tooLong(in.Code, 50):
		return iam.Invalid("Mã lớp không được vượt quá 50 ký tự.")
	case in.BranchID == nil:
		return iam.Invalid("Chưa chọn Chi nhánh.")
	case in.Program == "":
		return iam.Invalid("Chưa chọn Chương trình.")
	case tooLong(in.Program, 100):
		return iam.Invalid("Chương trình không được vượt quá 100 ký tự.")
	case in.Level == "":
		return iam.Invalid("Chưa chọn Cấp độ.")
	case tooLong(in.Level, 100):
		return iam.Invalid("Cấp độ không được vượt quá 100 ký tự.")
	case in.MaxCapacity == nil || *in.MaxCapacity < 1:
		return iam.Invalid("Sĩ số tối đa phải lớn hơn 0.")
	case *in.MaxCapacity > 100:
		return iam.Invalid("Sĩ số tối đa không được lớn hơn 100.")
	case in.MinStudents != nil && *in.MinStudents < 1:
		return iam.Invalid("Ngưỡng khai giảng phải từ 1 học viên.")
	case in.MinStudents != nil && *in.MinStudents > 100:
		return iam.Invalid("Ngưỡng khai giảng không được lớn hơn 100.")
	case in.MinStudents != nil && *in.MinStudents > *in.MaxCapacity:
		return iam.Invalid("Ngưỡng khai giảng không được lớn hơn sĩ số tối đa.")
	case in.TuitionFee != nil && *in.TuitionFee < 0:
		return iam.Invalid("Học phí không được nhỏ hơn 0.")
	case in.Notes != nil && tooLong(*in.Notes, 5000):
		return iam.Invalid("Ghi chú không được vượt quá 5000 ký tự.")
	}
	return nil
}

var errStaffRole = iam.Invalid("Người được gán phải đang hoạt động và đúng vai trò (giáo viên/quản lý hoặc trợ giảng/học vụ).")

// checkStaff: GV chính / GVNN phải có "Được xếp dạy lớp" (class.teach), trợ giảng "Được xếp làm trợ giảng"
// (class.assist), đang hoạt động (assertValidTeachingStaff).
func (s *Service) checkStaff(ctx context.Context, teach []*uuid.UUID, assist []*uuid.UUID) error {
	check := func(perm string, ids []*uuid.UUID) error {
		var allowed []classesdb.UsersWithPermissionRow
		loaded := false
		for _, id := range ids {
			if id == nil {
				continue
			}
			if !loaded {
				var err error
				if allowed, err = s.q.UsersWithPermission(ctx, perm); err != nil {
					return err
				}
				loaded = true
			}
			if !slices.ContainsFunc(allowed, func(u classesdb.UsersWithPermissionRow) bool { return u.ID == *id }) {
				return errStaffRole
			}
		}
		return nil
	}
	if err := check("class.teach", teach); err != nil {
		return err
	}
	return check("class.assist", assist)
}

// branchFor: chi nhánh phải tồn tại và nằm trong phạm vi lớp của người dùng.
func (s *Service) branchFor(ctx context.Context, actor *iam.Access, id uuid.UUID) error {
	ok, err := s.q.BranchExists(ctx, id)
	if err != nil {
		return err
	}
	if !ok {
		return iam.Invalid("Chi nhánh không tồn tại, vui lòng chọn lại.")
	}
	if !actor.CoversBranch("class", &id) {
		return forbidden("Bạn chỉ được quản lý lớp thuộc chi nhánh của mình.")
	}
	return nil
}

// roomForBranch: phòng (chưa xóa) phải thuộc chi nhánh lớp; không chọn thì nil.
func (s *Service) roomForBranch(ctx context.Context, id *uuid.UUID, branch uuid.UUID, msg string) (*classesdb.GetRoomRow, error) {
	if id == nil {
		return nil, nil
	}
	r, err := s.q.GetRoom(ctx, *id)
	if isNoRows(err) || (err == nil && r.BranchID != branch) {
		return nil, iam.Invalid(msg)
	}
	if err != nil {
		return nil, err
	}
	return &r, nil
}

const msgRoomBranch = "Phòng học không thuộc chi nhánh đã chọn, vui lòng chọn lại."

// slugUpper: "Tiếng Anh Giao tiếp" → "TIENG-ANH-GIAO-TIEP" (Str::slug + strtoupper).
func slugUpper(s string) string {
	var b strings.Builder
	dash := false
	for _, r := range foldVi(strings.ToLower(s)) {
		switch {
		case (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9'):
			b.WriteRune(r)
			dash = false
		case b.Len() > 0 && !dash:
			b.WriteByte('-')
			dash = true
		}
	}
	return strings.ToUpper(strings.TrimRight(b.String(), "-"))
}

var viFold = map[rune]rune{}

func init() {
	groups := map[rune]string{
		'a': "àáạảãâầấậẩẫăằắặẳẵ", 'e': "èéẹẻẽêềếệểễ", 'i': "ìíịỉĩ", 'o': "òóọỏõôồốộổỗơờớợởỡ",
		'u': "ùúụủũưừứựửữ", 'y': "ỳýỵỷỹ", 'd': "đ",
	}
	for base, chars := range groups {
		for _, c := range chars {
			viFold[c] = base
		}
	}
}

func foldVi(s string) string {
	return strings.Map(func(r rune) rune {
		if f, ok := viFold[r]; ok {
			return f
		}
		return r
	}, s)
}

func randomSuffix(n int) string {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	b := make([]byte, n)
	_, _ = rand.Read(b)
	for i := range b {
		b[i] = alphabet[int(b[i])%len(alphabet)]
	}
	return string(b)
}

// uniqueCode: mã đã có thì thêm -1, -2… (bỏ qua lớp đã xóa).
func uniqueCode(ctx context.Context, q *classesdb.Queries, code string, except uuid.UUID) (string, error) {
	out := code
	for n := 1; ; n++ {
		taken, err := q.ClassCodeTaken(ctx, classesdb.ClassCodeTakenParams{Code: out, ExceptID: except})
		if err != nil {
			return "", err
		}
		if !taken {
			return out, nil
		}
		out = fmt.Sprintf("%s-%d", code, n)
	}
}

func isUnique(err error, constraint string) bool {
	var pe *pgconn.PgError
	return errors.As(err, &pe) && pe.Code == "23505" && pe.ConstraintName == constraint
}

var errCodeRace = conflict("Mã lớp vừa được dùng cho lớp khác, vui lòng thử lại.")

// parseDrafts: chuẩn hóa thời khóa biểu render từ form tạo lớp và chặn buổi chồng giờ trong cùng đợt.
func parseDrafts(drafts []SessionDraft) ([]planned, error) {
	if len(drafts) > 200 {
		return nil, iam.Invalid("Thời khóa biểu vượt quá 200 buổi mỗi lần tạo.")
	}
	out := make([]planned, 0, len(drafts))
	for i, d := range drafts {
		date, err := time.Parse(time.DateOnly, strings.TrimSpace(d.Date))
		shift, room := strings.TrimSpace(d.Shift), strings.TrimSpace(d.Room)
		_, okS := hm(d.Start)
		_, okE := hm(d.End)
		if err != nil || shift == "" || tooLong(shift, 50) || !okS || !okE || tooLong(room, 50) {
			return nil, iam.Invalid(fmt.Sprintf("Buổi học thứ %d không hợp lệ.", i+1))
		}
		if d.End <= d.Start {
			return nil, iam.Invalid(fmt.Sprintf("Buổi %s ngày %s có giờ kết thúc không sau giờ bắt đầu.", shift, date.Format(time.DateOnly)))
		}
		out = append(out, planned{Date: date, Start: d.Start, End: d.End, Room: optText(room), Shift: shift})
	}
	sort.SliceStable(out, func(i, j int) bool {
		if !out[i].Date.Equal(out[j].Date) {
			return out[i].Date.Before(out[j].Date)
		}
		return out[i].Start < out[j].Start
	})
	for i := 1; i < len(out); i++ {
		a, b := out[i-1], out[i]
		if a.Date.Equal(b.Date) && b.Start < a.End {
			return nil, iam.Invalid(fmt.Sprintf("Hai buổi ngày %s bị chồng giờ (%s-%s và %s-%s).", b.day(), a.Start, a.End, b.Start, b.End))
		}
	}
	return out, nil
}

// hm: "HH:MM" hợp lệ → số phút.
func hm(v string) (int, bool) {
	t, err := time.Parse("15:04", v)
	if err != nil || len(v) != 5 {
		return 0, false
	}
	return t.Hour()*60 + t.Minute(), true
}

type Saved struct {
	ID      uuid.UUID `json:"id"`
	Message string    `json:"message"`
	Tab     string    `json:"tab,omitempty"` // tab nên mở tiếp trên Trang lớp
}

// Create: tạo lớp; có thời khóa biểu render sẵn thì tạo luôn buổi học, ghi ngày khai giảng và kích hoạt lớp.
func (s *Service) Create(ctx context.Context, actor *iam.Access, in ClassInput) (*Saved, error) {
	if !actor.Can("class.create") {
		return nil, forbidden("Bạn không có quyền tạo lớp học.")
	}
	if err := in.validate(); err != nil {
		return nil, err
	}
	sessions, err := parseDrafts(in.Sessions)
	if err != nil {
		return nil, err
	}
	branch := *in.BranchID
	if err := s.branchFor(ctx, actor, branch); err != nil {
		return nil, err
	}
	// TKB do client render chưa biết lịch nghỉ lễ: bỏ buổi rơi vào ngày nghỉ của chi nhánh.
	if len(sessions) > 0 {
		holidays, err := s.holidayDates(ctx, branch, sessions[0].Date, sessions[len(sessions)-1].Date)
		if err != nil {
			return nil, err
		}
		sessions = slices.DeleteFunc(sessions, func(p planned) bool { return holidays[p.day()] })
		if len(sessions) == 0 {
			return nil, iam.Invalid("Tất cả buổi học đều rơi vào ngày nghỉ lễ, vui lòng chọn lại lịch.")
		}
	}
	teacher, foreign := in.TeacherID.ID, in.ForeignTeacherID.ID
	if err := s.checkStaff(ctx, []*uuid.UUID{teacher, foreign}, nil); err != nil {
		return nil, err
	}
	code := strings.ToUpper(in.Code)
	if code == "" {
		code = slugUpper(in.Program) + "-" + randomSuffix(4)
	}
	room, err := s.roomForBranch(ctx, in.RoomID.ID, branch, msgRoomBranch)
	if err != nil {
		return nil, err
	}
	var roomID *uuid.UUID
	var roomName *string
	if room != nil {
		roomID, roomName = &room.ID, &room.Name
	}
	if hit, err := findConflict(ctx, s.q, sessions, branch, []*uuid.UUID{teacher, foreign}, roomName, nil); err != nil {
		return nil, err
	} else if hit != nil {
		return nil, iam.Invalid("Xung đột lịch " + hit.where() + ".")
	}
	minStudents := min(DefaultMinStudents, *in.MaxCapacity)
	if in.MinStudents != nil {
		minStudents = *in.MinStudents
	}
	p := classesdb.CreateClassParams{
		Name: in.Name, BranchID: branch, Program: &in.Program, Level: &in.Level, TeacherID: teacher, ForeignTeacherID: foreign,
		RoomID: roomID, Room: roomName, MaxCapacity: int32(*in.MaxCapacity), MinStudents: int32(minStudents),
		TuitionFee: in.TuitionFee, Notes: optText(deref(in.Notes)), Status: StatusPendingSchedule, CreatedBy: &actor.UserID,
	}
	if cid, err := s.q.CourseIDByName(ctx, in.Program); err == nil {
		p.CourseID = &cid
	} else if !isNoRows(err) {
		return nil, err
	}
	if len(sessions) > 0 {
		p.Status = StatusActive
		p.StartDate, p.EndDate = &sessions[0].Date, &sessions[len(sessions)-1].Date
		var parts []string
		for _, se := range sessions {
			part := se.Shift + " " + se.Start + "-" + se.End
			if !slices.Contains(parts, part) {
				parts = append(parts, part)
			}
		}
		text := strings.Join(parts, "; ")
		if tooLong(text, 500) {
			text = string([]rune(text)[:500])
		}
		p.ScheduleText = &text
	}
	var id uuid.UUID
	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		var err error
		if p.Code, err = uniqueCode(ctx, q, code, uuid.Nil); err != nil {
			return err
		}
		if id, err = q.CreateClass(ctx, p); err != nil {
			return err
		}
		for _, se := range sessions {
			r := se.Room
			if r == nil {
				r = roomName
			}
			if _, err := q.InsertSession(ctx, classesdb.InsertSessionParams{
				ClassID: id, BranchID: branch, Date: se.Date, ShiftName: &se.Shift, Type: "regular", StartTime: se.Start, EndTime: se.End,
				Room: r, TeacherID: coalesceID(teacher, foreign), ForeignTeacherID: foreign, CreatedBy: &actor.UserID,
			}); err != nil {
				return err
			}
		}
		return nil
	})
	if isUnique(err, "classes_code_uq") {
		return nil, errCodeRace
	}
	if err != nil {
		return nil, err
	}
	// TODO(audit): AcademicRecord "Lớp mới: <tên>" (màn 13_tao_lop_moi) khi có nhật ký nghiệp vụ.
	s.log.Info("class created", "class_id", id, "code", p.Code, "sessions", len(sessions), "by", actor.UserID)
	if len(sessions) > 0 {
		return &Saved{ID: id, Tab: "overview", Message: fmt.Sprintf("Tạo lớp học '%s' (%s) thành công với %d buổi học đã lên lịch; lớp đã được kích hoạt.", in.Name, p.Code, len(sessions))}, nil
	}
	return &Saved{ID: id, Tab: "schedule", Message: fmt.Sprintf("Tạo lớp học '%s' (%s) thành công! Bước tiếp theo: cấu hình lịch học.", in.Name, p.Code)}, nil
}

func coalesceID(ids ...*uuid.UUID) *uuid.UUID {
	for _, id := range ids {
		if id != nil {
			return id
		}
	}
	return nil
}

func syncablePlanned(rows []classesdb.StaffSyncableSessionsRow, keep func(classesdb.StaffSyncableSessionsRow) bool, room *string) []planned {
	out := []planned{}
	for _, r := range rows {
		if keep != nil && !keep(r) {
			continue
		}
		p := planned{Date: r.Date, Start: r.StartTime, End: r.EndTime, Room: r.Room}
		if room != nil {
			p.Room = room
		}
		out = append(out, p)
	}
	return out
}

func sessionIDs(rows []classesdb.StaffSyncableSessionsRow) []uuid.UUID {
	out := make([]uuid.UUID, 0, len(rows))
	for _, r := range rows {
		out = append(out, r.ID)
	}
	return out
}

func parseOptDate(v *string, msg string) (*time.Time, bool, error) {
	if v == nil || strings.TrimSpace(*v) == "" {
		return nil, false, nil
	}
	t, err := time.Parse(time.DateOnly, strings.TrimSpace(*v))
	if err != nil {
		return nil, false, iam.Invalid(msg)
	}
	return &t, true, nil
}

// Update: sửa lớp; đổi GV / GVNN / trợ giảng / phòng / chi nhánh áp lên các buổi sắp tới chưa có dữ liệu thực tế,
// kiểm xung đột như khi tạo lớp.
func (s *Service) Update(ctx context.Context, actor *iam.Access, id uuid.UUID, in ClassInput) (*Saved, error) {
	if !actor.Can("class.update") {
		return nil, forbidden("Bạn không có quyền chỉnh sửa lớp học.")
	}
	c, err := s.classFor(ctx, actor, id, "class.update")
	if err != nil {
		return nil, err
	}
	if err := in.validate(); err != nil {
		return nil, err
	}
	status := c.Status
	if in.Status != nil && strings.TrimSpace(*in.Status) != "" {
		if !validStatus(*in.Status) {
			return nil, iam.Invalid("Trạng thái lớp không hợp lệ.")
		}
		status = *in.Status
	}
	startDate, endDate := c.StartDate, c.EndDate
	if d, ok, err := parseOptDate(in.StartDate, "Ngày bắt đầu không hợp lệ."); err != nil {
		return nil, err
	} else if ok {
		startDate = d
	}
	if d, ok, err := parseOptDate(in.EndDate, "Ngày kết thúc không hợp lệ."); err != nil {
		return nil, err
	} else if ok {
		endDate = d
	}
	scheduleText := c.ScheduleText
	if in.ScheduleText != nil && strings.TrimSpace(*in.ScheduleText) != "" {
		if tooLong(strings.TrimSpace(*in.ScheduleText), 500) {
			return nil, iam.Invalid("Mô tả lịch học không được vượt quá 500 ký tự.")
		}
		scheduleText = optText(*in.ScheduleText)
	}
	branch := *in.BranchID
	if err := s.branchFor(ctx, actor, branch); err != nil {
		return nil, err
	}
	pick := func(o OptID, cur *uuid.UUID) *uuid.UUID {
		if o.Set {
			return o.ID
		}
		return cur
	}
	newTeacher, newAssistant, newForeign := pick(in.TeacherID, c.TeacherID), pick(in.AssistantID, c.AssistantID), pick(in.ForeignTeacherID, c.ForeignTeacherID)
	if err := s.checkStaff(ctx, []*uuid.UUID{in.TeacherID.ID, in.ForeignTeacherID.ID}, []*uuid.UUID{in.AssistantID.ID}); err != nil {
		return nil, err
	}
	code := c.Code
	if in.Code != "" {
		code = strings.ToUpper(in.Code)
	}
	// Gửi room_id (kể cả rỗng = gỡ phòng) thì đổi phòng; đổi chi nhánh mà không gửi phòng thì gỡ phòng của chi nhánh cũ.
	newRoomID, newRoom := c.RoomID, c.Room
	switch {
	case in.RoomID.Set:
		room, err := s.roomForBranch(ctx, in.RoomID.ID, branch, msgRoomBranch)
		if err != nil {
			return nil, err
		}
		newRoomID, newRoom = nil, nil
		if room != nil {
			newRoomID, newRoom = &room.ID, &room.Name
		}
	case c.BranchID != branch:
		newRoomID, newRoom = nil, nil
	}
	today := s.today()
	future, err := s.q.StaffSyncableSessions(ctx, classesdb.StaffSyncableSessionsParams{ClassID: id, Today: today})
	if err != nil {
		return nil, err
	}
	// GVNN không cố định: buổi đã gán GVNN riêng (khác GVNN mặc định cũ của lớp) giữ nguyên khi đổi GVNN của lớp.
	foreignSync := slices.DeleteFunc(slices.Clone(future), func(r classesdb.StaffSyncableSessionsRow) bool {
		return r.ForeignTeacherID != nil && !sameID(r.ForeignTeacherID, c.ForeignTeacherID)
	})
	previousRoom := c.Room
	if len(future) > 0 {
		type check struct {
			sessions []planned
			staff    []*uuid.UUID
		}
		var checks []check
		if newTeacher != nil && !sameID(newTeacher, c.TeacherID) {
			checks = append(checks, check{syncablePlanned(future, nil, nil), []*uuid.UUID{newTeacher}})
		}
		if newForeign != nil && !sameID(newForeign, c.ForeignTeacherID) {
			checks = append(checks, check{syncablePlanned(foreignSync, nil, nil), []*uuid.UUID{newForeign}})
		}
		if newAssistant != nil && !sameID(newAssistant, c.AssistantID) {
			checks = append(checks, check{syncablePlanned(future, nil, nil), []*uuid.UUID{newAssistant}})
		}
		if newRoom != nil && deref(newRoom) != deref(previousRoom) {
			checks = append(checks, check{syncablePlanned(future, func(r classesdb.StaffSyncableSessionsRow) bool {
				return r.Room == nil || (previousRoom != nil && *r.Room == *previousRoom)
			}, newRoom), nil})
		}
		for _, ch := range checks {
			hit, err := findConflict(ctx, s.q, ch.sessions, branch, ch.staff, nil, &id)
			if err != nil {
				return nil, err
			}
			if hit != nil {
				return nil, iam.Invalid("Xung đột lịch " + hit.where() + ".")
			}
		}
	}
	minStudents := min(int(c.MinStudents), *in.MaxCapacity)
	if c.MinStudents == 0 {
		minStudents = min(DefaultMinStudents, *in.MaxCapacity)
	}
	if in.MinStudents != nil {
		minStudents = *in.MinStudents
	}
	tuition, notes := c.TuitionFee, c.Notes
	if in.TuitionFee != nil {
		tuition = in.TuitionFee
	}
	if in.Notes != nil && strings.TrimSpace(*in.Notes) != "" {
		notes = optText(*in.Notes)
	}
	p := classesdb.UpdateClassParams{
		ID: id, Name: in.Name, BranchID: branch, Program: &in.Program, Level: &in.Level,
		MaxCapacity: int32(*in.MaxCapacity), MinStudents: int32(minStudents), RoomID: newRoomID, Room: newRoom,
		TeacherID: newTeacher, AssistantID: newAssistant, ForeignTeacherID: newForeign, TuitionFee: tuition, Notes: notes,
		Status: status, StartDate: startDate, EndDate: endDate, ScheduleText: scheduleText,
	}
	if cid, err := s.q.CourseIDByName(ctx, in.Program); err == nil {
		p.CourseID = &cid
	} else if !isNoRows(err) {
		return nil, err
	}
	all := sessionIDs(future)
	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		var err error
		if p.Code, err = uniqueCode(ctx, q, code, id); err != nil {
			return err
		}
		if err := q.UpdateClass(ctx, p); err != nil {
			return err
		}
		if len(all) == 0 {
			return nil
		}
		if !sameID(newForeign, c.ForeignTeacherID) && len(foreignSync) > 0 {
			if err := q.SetSessionsForeignTeacher(ctx, classesdb.SetSessionsForeignTeacherParams{ForeignTeacherID: newForeign, Ids: sessionIDs(foreignSync)}); err != nil {
				return err
			}
		}
		if !sameID(newTeacher, c.TeacherID) || !sameID(newForeign, c.ForeignTeacherID) {
			// Lớp không có GV chính thì người đứng lớp của buổi là GVNN của chính buổi đó.
			if err := q.SetSessionsTeacher(ctx, classesdb.SetSessionsTeacherParams{TeacherID: newTeacher, Ids: all}); err != nil {
				return err
			}
		}
		if !sameID(newAssistant, c.AssistantID) {
			if err := q.SetSessionsAssistant(ctx, classesdb.SetSessionsAssistantParams{AssistantID: newAssistant, Ids: all}); err != nil {
				return err
			}
		}
		if branch != c.BranchID {
			if err := q.SetSessionsBranch(ctx, classesdb.SetSessionsBranchParams{BranchID: branch, Ids: all}); err != nil {
				return err
			}
		}
		if deref(newRoom) != deref(previousRoom) {
			return q.SetSessionsRoom(ctx, classesdb.SetSessionsRoomParams{Room: newRoom, Ids: all, PreviousRoom: previousRoom})
		}
		return nil
	})
	if isUnique(err, "classes_code_uq") {
		return nil, errCodeRace
	}
	if err != nil {
		return nil, err
	}
	s.log.Info("class updated", "class_id", id, "by", actor.UserID)
	return &Saved{ID: id, Message: fmt.Sprintf("Đã cập nhật lớp học '%s' (%s) thành công!", in.Name, p.Code)}, nil
}

// ───────────────────────── GVNN theo buổi ─────────────────────────

type ForeignInput struct {
	// "none" = gỡ GVNN khỏi các buổi đã chọn (phải chọn rõ, tránh lưu nhầm ô trống thành gỡ hàng loạt)
	ForeignTeacherID string      `json:"foreign_teacher_id"`
	SessionIDs       []uuid.UUID `json:"session_ids"`
	SetDefault       bool        `json:"set_default"` // đặt làm GVNN mặc định của lớp
}

// AssignForeignTeacher: gán / đổi / gỡ GVNN cho các buổi sắp tới (chưa điểm danh) của lớp.
func (s *Service) AssignForeignTeacher(ctx context.Context, actor *iam.Access, id uuid.UUID, in ForeignInput) (string, error) {
	if !actor.Can("class.update") {
		return "", forbidden("Bạn không có quyền chỉnh sửa lớp học.")
	}
	c, err := s.classFor(ctx, actor, id, "class.update")
	if err != nil {
		return "", err
	}
	raw := strings.TrimSpace(in.ForeignTeacherID)
	if raw == "" {
		return "", iam.Invalid(`Chọn GVNN, hoặc chọn "Không có GVNN" để gỡ.`)
	}
	var foreign *uuid.UUID
	if raw != "none" {
		fid, err := uuid.Parse(raw)
		if err != nil {
			return "", errStaffRole
		}
		foreign = &fid
	}
	if len(in.SessionIDs) == 0 {
		return "", iam.Invalid("Chọn ít nhất một buổi học để gán GVNN.")
	}
	if len(in.SessionIDs) > 300 {
		return "", iam.Invalid("Mỗi lần chỉ gán GVNN cho tối đa 300 buổi.")
	}
	if err := s.checkStaff(ctx, []*uuid.UUID{foreign}, nil); err != nil {
		return "", err
	}
	syncable, err := s.q.StaffSyncableSessions(ctx, classesdb.StaffSyncableSessionsParams{ClassID: id, Today: s.today()})
	if err != nil {
		return "", err
	}
	wanted := map[uuid.UUID]bool{}
	for _, sid := range in.SessionIDs {
		wanted[sid] = true
	}
	chosen := slices.DeleteFunc(slices.Clone(syncable), func(r classesdb.StaffSyncableSessionsRow) bool { return !wanted[r.ID] })
	if len(chosen) != len(wanted) {
		return "", iam.Invalid("Chỉ đổi GVNN được cho buổi sắp tới của lớp này, chưa điểm danh/chấm công.")
	}
	if foreign != nil {
		hit, err := findConflict(ctx, s.q, syncablePlanned(chosen, nil, nil), c.BranchID, []*uuid.UUID{foreign}, nil, &id)
		if err != nil {
			return "", err
		}
		if hit != nil {
			return "", iam.Invalid("GVNN bị trùng lịch " + hit.where() + ".")
		}
	}
	ids := sessionIDs(chosen)
	err = s.tx(ctx, func(_ pgx.Tx, q *classesdb.Queries) error {
		if err := q.SetSessionsForeignTeacher(ctx, classesdb.SetSessionsForeignTeacherParams{ForeignTeacherID: foreign, Ids: ids}); err != nil {
			return err
		}
		if err := q.SetSessionsTeacher(ctx, classesdb.SetSessionsTeacherParams{TeacherID: c.TeacherID, Ids: ids}); err != nil {
			return err
		}
		if in.SetDefault {
			return q.SetClassForeignTeacher(ctx, classesdb.SetClassForeignTeacherParams{ID: id, ForeignTeacherID: foreign})
		}
		return nil
	})
	if err != nil {
		return "", err
	}
	s.log.Info("class foreign teacher assigned", "class_id", id, "foreign_teacher_id", foreign, "sessions", len(ids), "by", actor.UserID)
	if foreign == nil {
		return fmt.Sprintf("Đã gỡ GVNN khỏi %d buổi.", len(ids)), nil
	}
	// TODO(notify): gửi GVNN (nếu không phải người thao tác) "Bạn được xếp dạy GVNN lớp <mã>" —
	// "<n> buổi lớp <tên>: <dd/mm, … tối đa 6 ngày>…." kèm link tab Lịch & buổi học.
	names, err := s.q.UserNames(ctx, []uuid.UUID{*foreign})
	if err != nil {
		return "", err
	}
	name := ""
	if len(names) > 0 {
		name = names[0].FullName
	}
	return fmt.Sprintf("Đã gán GVNN %s cho %d buổi.", name, len(ids)), nil
}

// ───────────────────────── Xóa ─────────────────────────

// Delete: xóa mềm; còn học viên đang xếp lớp hoặc buổi học chưa diễn ra thì chặn (nên chuyển lớp sang Đã hủy).
func (s *Service) Delete(ctx context.Context, actor *iam.Access, id uuid.UUID) (string, error) {
	if !actor.Can("class.delete") {
		return "", forbidden("Bạn không có quyền xóa lớp học.")
	}
	c, err := s.classFor(ctx, actor, id, "class.delete")
	if err != nil {
		return "", err
	}
	enrolled, err := s.q.ActiveEnrollmentCount(ctx, id)
	if err != nil {
		return "", err
	}
	upcoming, err := s.q.UpcomingScheduledCount(ctx, classesdb.UpcomingScheduledCountParams{ClassID: id, Today: s.today()})
	if err != nil {
		return "", err
	}
	if enrolled > 0 || upcoming > 0 {
		return "", conflict(fmt.Sprintf("Không thể xóa lớp '%s' (%s) vì còn %d học viên đang xếp lớp và %d buổi học chưa diễn ra. Hãy chuyển lớp sang trạng thái Đã hủy thay vì xóa.", c.Name, c.Code, enrolled, upcoming))
	}
	if err := s.q.SoftDeleteClass(ctx, id); err != nil {
		return "", err
	}
	s.log.Info("class deleted", "class_id", id, "by", actor.UserID)
	return fmt.Sprintf("Đã xóa lớp học '%s' (%s) thành công.", c.Name, c.Code), nil
}

// ───────────────────────── Phòng / nhân sự còn trống ─────────────────────────

type AvailabilityInput struct {
	BranchID       *uuid.UUID `json:"branch_id"`
	Date           string     `json:"date"`
	StartTime      string     `json:"start_time"`
	EndTime        string     `json:"end_time"`
	ExcludeClassID *uuid.UUID `json:"exclude_class_id"`
}

type Availability struct {
	OccupiedRooms           []string    `json:"occupied_rooms"`
	OccupiedTeachers        []uuid.UUID `json:"occupied_teachers"` // mọi nhân sự (GV chính, GVNN, trợ giảng) đang bận
	OccupiedAssistants      []uuid.UUID `json:"occupied_assistants"`
	OccupiedForeignTeachers []uuid.UUID `json:"occupied_foreign_teachers"`
}

// CheckAvailability: phòng (cùng chi nhánh) và nhân sự (mọi chi nhánh) đang có buổi chưa hủy chồng giờ trong ngày.
func (s *Service) CheckAvailability(ctx context.Context, in AvailabilityInput) (*Availability, error) {
	date, err := time.Parse(time.DateOnly, strings.TrimSpace(in.Date))
	if err != nil {
		return nil, iam.Invalid("Ngày không hợp lệ.")
	}
	_, ok1 := hm(in.StartTime)
	_, ok2 := hm(in.EndTime)
	if !ok1 || !ok2 {
		return nil, iam.Invalid("Giờ không hợp lệ (định dạng HH:MM).")
	}
	if in.EndTime <= in.StartTime {
		return nil, iam.Invalid("Giờ kết thúc phải sau giờ bắt đầu.")
	}
	rows, err := s.q.OverlappingSessions(ctx, classesdb.OverlappingSessionsParams{
		Date: date, ExcludeClass: in.ExcludeClassID, StartTime: in.StartTime, EndTime: in.EndTime,
	})
	if err != nil {
		return nil, err
	}
	out := &Availability{OccupiedRooms: []string{}, OccupiedTeachers: []uuid.UUID{}, OccupiedAssistants: []uuid.UUID{}, OccupiedForeignTeachers: []uuid.UUID{}}
	add := func(list *[]uuid.UUID, id *uuid.UUID) {
		if id != nil && !slices.Contains(*list, *id) {
			*list = append(*list, *id)
		}
	}
	for _, r := range rows {
		if in.BranchID != nil && r.BranchID == *in.BranchID && r.Room != nil && *r.Room != "" && !slices.Contains(out.OccupiedRooms, *r.Room) {
			out.OccupiedRooms = append(out.OccupiedRooms, *r.Room)
		}
		add(&out.OccupiedTeachers, r.TeacherID)
		add(&out.OccupiedTeachers, r.ForeignTeacherID)
		add(&out.OccupiedTeachers, r.AssistantID)
		add(&out.OccupiedAssistants, r.AssistantID)
		add(&out.OccupiedForeignTeachers, r.ForeignTeacherID)
	}
	return out, nil
}

// studentStatusLabel: nhãn trạng thái học viên (dùng chung với phân hệ Học viên).
func studentStatusLabel(st string) string { return students.StatusLabel(st) }
