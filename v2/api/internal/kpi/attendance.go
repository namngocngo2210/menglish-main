package kpi

import (
	"context"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/kpi/kpisrc"
)

// ───────────── Rà soát điểm danh (Học vụ) ─────────────

type AttendanceSummary struct {
	Present int `json:"present"`
	Late    int `json:"late"`
	Absent  int `json:"absent"`
	Excused int `json:"excused"`
}

type AttendancePage struct {
	Records []kpisrc.AttendanceRecord `json:"records"`
	Summary AttendanceSummary         `json:"summary"`
	Classes []kpisrc.Option           `json:"classes"`
	Date    string                    `json:"date"`
	ClassID *string                   `json:"class_id"`
	Pending *string                   `json:"pending"`
}

var ErrAttendanceNotFound = &iam.Error{Status: 404, Code: "not_found", Message: "Không tìm thấy lượt điểm danh."}

const attendancePending = "Chờ phân hệ Lớp học chuyển sang hệ thống mới."

// Attendance: điểm danh học viên theo ngày / lớp trong phạm vi Lớp học của người xem, thống kê có mặt / muộn / vắng / có phép.
func (s *Service) Attendance(ctx context.Context, a *iam.Access, date, classID string) (*AttendancePage, error) {
	day := dayOf(s.now())
	if d, err := time.ParseInLocation(time.DateOnly, date, vnZone); err == nil {
		day = d
	}
	out := &AttendancePage{Records: []kpisrc.AttendanceRecord{}, Classes: []kpisrc.Option{}, Date: day.Format(time.DateOnly)}
	var cid *uuid.UUID
	if id, err := uuid.Parse(classID); err == nil {
		cid, out.ClassID = &id, ptr(id.String())
	}
	if s.attendance == nil {
		out.Pending = ptr(attendancePending)
		return out, nil
	}
	recs, classes, err := s.attendance.AttendanceDay(ctx, a, day, cid)
	if err != nil {
		return nil, err
	}
	out.Records, out.Classes = nonNil(recs), nonNil(classes)
	for _, r := range out.Records {
		switch r.Status {
		case "present":
			out.Summary.Present++
		case "late":
			out.Summary.Late++
		case "absent":
			out.Summary.Absent++
		case "excused":
			out.Summary.Excused++
		}
	}
	return out, nil
}

type ReviewInput struct {
	Decision   string  `json:"decision"`
	ReviewNote *string `json:"review_note"`
}

// ReviewAttendance: Duyệt / Trả lại một lượt điểm danh.
func (s *Service) ReviewAttendance(ctx context.Context, a *iam.Access, id uuid.UUID, in ReviewInput) (string, error) {
	if in.Decision != "approved" && in.Decision != "rejected" {
		return "", iam.Invalid("Quyết định rà soát không hợp lệ.")
	}
	note := trimPtr(in.ReviewNote)
	if runes(note) > 1000 {
		return "", iam.Invalid("Ghi chú rà soát tối đa 1000 ký tự.")
	}
	if s.attendance == nil {
		return "", ErrAttendanceNotFound
	}
	err := pgx.BeginFunc(ctx, s.pool, func(tx pgx.Tx) error {
		r, ok, err := s.attendance.ReviewAttendance(ctx, tx, a, id, in.Decision, deref(note))
		if err != nil {
			return err
		}
		if !ok {
			return ErrAttendanceNotFound
		}
		desc := "Duyệt điểm danh " + r.Label
		if in.Decision == "rejected" {
			desc = "Trả lại điểm danh " + r.Label
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Action: "kpi.attendance_review", SubjectType: "student_attendance",
			SubjectID: r.ID.String(), SubjectLabel: r.Label, BranchID: r.BranchID, Description: desc,
			Changes: audit.Changes{"review_status": {Old: r.OldStatus, New: in.Decision}}})
	})
	if err != nil {
		return "", err
	}
	if in.Decision == "approved" {
		return "Đã duyệt điểm danh.", nil
	}
	return "Đã trả lại điểm danh để chỉnh sửa.", nil
}
