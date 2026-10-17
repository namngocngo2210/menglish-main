package classes

import (
	"context"
	"math"
	"slices"
	"strconv"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/classes/classesdb"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// Phần việc phân hệ Lớp học làm cho KPI: tỷ lệ chuyên cần các lớp giáo viên dạy (class_attendance_rate), rà soát điểm danh
// của Học vụ (KpiController::attendanceReview) và số liệu lớp của bảng KPI tự động (KpiBoardService).

// KpiSources: nguồn class_attendance_rate.
func (s *Service) KpiSources() kpisrc.Sources {
	return kpisrc.Sources{"class_attendance_rate": s.kpiAttendanceRate}
}

// kpiAttendanceRate: chuyên cần (có mặt + đi muộn ÷ lượt điểm danh) từng lớp, trung bình theo sĩ số (TeachingQuality::totals).
func (s *Service) kpiAttendanceRate(ctx context.Context, userID uuid.UUID, from, to time.Time) (kpisrc.Result, error) {
	rows, err := s.q.KpiTeacherAttendance(ctx, classesdb.KpiTeacherAttendanceParams{FromDate: from, ToDate: to, UserID: &userID})
	if err != nil {
		return kpisrc.Result{}, err
	}
	var out []kpisrc.Record
	sum, weight := 0.0, 0.0
	for _, r := range rows {
		if r.AttendanceTotal == 0 {
			continue
		}
		rate := math.Round(float64(r.Attended)/float64(r.AttendanceTotal)*1000) / 10
		w := float64(max(1, r.Students))
		sum += rate * w
		weight += w
		name := r.Code
		if name == "" {
			name = r.Name
		}
		out = append(out, kpisrc.Record{Text: "Lớp " + name + ": " + kpisrc.Pct(rate) + " (" + strconv.Itoa(int(r.Students)) + " học sinh)"})
	}
	res := kpisrc.Result{Records: out}
	if weight > 0 {
		res.Value = kpisrc.F(math.Round(sum/weight*10) / 10)
	}
	return res, nil
}

// ───────────── Rà soát điểm danh ─────────────

type KpiAttendance struct{ cls *Service }

func (s *Service) KpiAttendance() KpiAttendance { return KpiAttendance{s} }

var reviewLabels = map[string]string{"pending_review": "Chờ duyệt", "approved": "Đã duyệt", "rejected": "Từ chối"}

func attendanceLabel(status string) string {
	i := slices.IndexFunc(AttendanceStatuses, func(o Option) bool { return o.Value == status })
	if i < 0 {
		return status
	}
	return AttendanceStatuses[i].Label
}

// AttendanceDay: điểm danh của ngày (lọc lớp) trong phạm vi Lớp học của người xem, kèm danh sách lớp người xem thấy.
func (k KpiAttendance) AttendanceDay(ctx context.Context, a *iam.Access, day time.Time, classID *uuid.UUID) ([]kpisrc.AttendanceRecord, []kpisrc.Option, error) {
	sc, err := k.cls.visible(ctx, a)
	if err != nil {
		return nil, nil, err
	}
	rows, err := k.cls.q.KpiAttendanceDay(ctx, classesdb.KpiAttendanceDayParams{Day: day, AllClasses: sc.all, Ids: nonNil(sc.ids), ClassID: classID})
	if err != nil {
		return nil, nil, err
	}
	recs := make([]kpisrc.AttendanceRecord, 0, len(rows))
	for _, r := range rows {
		teacher := r.TeacherName
		if teacher == "" {
			teacher = "—"
		}
		review := reviewLabels[r.ReviewStatus]
		if review == "" {
			review = "Chưa rà soát"
		}
		recs = append(recs, kpisrc.AttendanceRecord{ID: r.ID, Student: r.StudentName, Class: r.ClassName, Teacher: teacher, Note: r.Note,
			Status: r.Status, StatusLabel: attendanceLabel(r.Status), ReviewStatus: r.ReviewStatus, ReviewLabel: review})
	}
	cls, err := k.cls.q.KpiVisibleClasses(ctx, classesdb.KpiVisibleClassesParams{AllClasses: sc.all, Ids: nonNil(sc.ids)})
	if err != nil {
		return nil, nil, err
	}
	opts := make([]kpisrc.Option, 0, len(cls))
	for _, c := range cls {
		opts = append(opts, kpisrc.Option{Value: c.ID.String(), Label: c.Name})
	}
	return recs, opts, nil
}

// ReviewAttendance: Duyệt / Trả lại một lượt điểm danh của lớp người xem thấy. Laravel cập nhật thêm số buổi đã học của học viên
// theo điểm danh đã duyệt; v2 chưa có cột attended_lessons (số buổi tính từ điểm danh khi cần).
func (k KpiAttendance) ReviewAttendance(ctx context.Context, tx pgx.Tx, a *iam.Access, id uuid.UUID, decision, note string) (kpisrc.ReviewedAttendance, bool, error) {
	sc, err := k.cls.visible(ctx, a)
	if err != nil {
		return kpisrc.ReviewedAttendance{}, false, err
	}
	q := k.cls.q.WithTx(tx)
	r, err := q.KpiLockAttendance(ctx, id)
	if isNoRows(err) || (err == nil && !sc.has(r.ClassID)) {
		return kpisrc.ReviewedAttendance{}, false, nil
	}
	if err != nil {
		return kpisrc.ReviewedAttendance{}, false, err
	}
	var notePtr *string
	if note != "" {
		notePtr = &note
	}
	if err := q.KpiReviewAttendance(ctx, classesdb.KpiReviewAttendanceParams{ID: id, ReviewStatus: decision, ReviewedBy: &a.UserID,
		ReviewNote: notePtr}); err != nil {
		return kpisrc.ReviewedAttendance{}, false, err
	}
	return kpisrc.ReviewedAttendance{ID: r.ID, OldStatus: r.ReviewStatus, BranchID: &r.BranchID,
		Label: r.StudentName + " · " + r.ClassName + " · " + r.SessionDate.Format("02/01/2006")}, true, nil
}

// ───────────── Bảng KPI tự động ─────────────

type KpiBoard struct{ cls *Service }

func (s *Service) KpiBoard() KpiBoard { return KpiBoard{s} }

// KpiBoardClasses: lớp phụ trách (GV / trợ giảng / GVNN), giữ chân học viên, chuyên cần trong kỳ theo người.
func (b KpiBoard) KpiBoardClasses(ctx context.Context, userIDs []uuid.UUID, from, to time.Time) (map[uuid.UUID]kpisrc.ClassStats, error) {
	rows, err := b.cls.q.KpiBoardClasses(ctx, classesdb.KpiBoardClassesParams{FromDate: from, ToDate: to, UserIds: userIDs})
	if err != nil {
		return nil, err
	}
	out := map[uuid.UUID]kpisrc.ClassStats{}
	for _, uid := range userIDs {
		var st kpisrc.ClassStats
		for _, r := range rows {
			if !slices.ContainsFunc([]*uuid.UUID{r.TeacherID, r.AssistantID, r.ForeignTeacherID}, func(p *uuid.UUID) bool { return p != nil && *p == uid }) {
				continue
			}
			st.Classes++
			st.Students += int(r.Students)
			st.Retained += int(r.Retained)
			st.AttendanceTotal += int(r.AttendanceTotal)
			st.AttendancePresent += int(r.AttendancePresent)
		}
		out[uid] = st
	}
	return out, nil
}
