package teacher

import (
	"context"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Nhận xét buổi học cho từng học sinh (TeacherPortalController::remarks / remarksStore): theo buổi (?session= hoặc
// ?date=, mặc định buổi hôm nay, không có thì buổi gần nhất trong 30 ngày); "Lưu nháp" chưa hiện cho học viên.

// RemarkFields: trường nhận xét (mockup 05_nhan_xet_buoi_hoc_cho_tung_hoc_sinh).
var RemarkFields = []string{"monsters_group", "monsters_bonus", "grammar", "attitude", "result", "comment"}

type RemarkStudent struct {
	ID        uuid.UUID         `json:"id"`
	Name      string            `json:"name"`
	AttStatus string            `json:"att_status"` // present | late | absent | excused | none
	Absent    bool              `json:"absent"`
	Remark    map[string]string `json:"remark"`
}

type SessionRef struct {
	ID    uuid.UUID `json:"id"`
	Label string    `json:"label"`
}

type RemarksPage struct {
	Classroom      ClassRef        `json:"classroom"`
	Session        *SessionRef     `json:"session"`
	RecordState    *string         `json:"record_state"` // draft | saved | null
	BlockReason    string          `json:"block_reason"`
	Students       []RemarkStudent `json:"students"`
	RecentSessions []SessionChoice `json:"recent_sessions"`
	TrialGuests    TrialGuests     `json:"trial_guests"`
}

func remarkMap(r *teacherdb.SheetRemarksRow) map[string]string {
	m := make(map[string]string, len(RemarkFields))
	for _, f := range RemarkFields {
		m[f] = ""
	}
	if r != nil {
		m["monsters_group"], m["monsters_bonus"], m["grammar"] = deref(r.MonstersGroup), deref(r.MonstersBonus), deref(r.Grammar)
		m["attitude"], m["result"], m["comment"] = deref(r.Attitude), deref(r.Result), deref(r.Comment)
	}
	return m
}

// RemarksPage: sessionID / day từ query (?session= / ?date=); cả hai nil = buổi hôm nay hoặc buổi gần nhất.
func (s *Service) RemarksPage(ctx context.Context, a *iam.Access, classID uuid.UUID, sessionID *uuid.UUID, day *time.Time) (*RemarksPage, error) {
	c, err := s.loadClass(ctx, a, classID)
	if err != nil {
		return nil, err
	}
	today := s.today()
	all, err := s.q.ClassSessionsBetween(ctx, teacherdb.ClassSessionsBetweenParams{ClassID: classID,
		FromDate: today.AddDate(0, 0, -makeupLookbackDays), ToDate: today})
	if err != nil {
		return nil, err
	}
	recent := make([]teacherdb.ClassSessionsBetweenRow, 0, len(all))
	for _, r := range all {
		if r.Type != "support" {
			recent = append(recent, r)
		}
	}
	se, err := s.resolveSession(ctx, a.UserID, classID, sessionID, day)
	if err != nil {
		return nil, err
	}
	if se == nil && sessionID == nil && day == nil {
		// Hôm nay lớp không có buổi: mở buổi gần nhất trong ô chọn.
		for _, r := range recent {
			if r.Status != "cancelled" {
				got, err := s.q.GetSession(ctx, r.ID)
				if err != nil {
					return nil, err
				}
				se = &got
				break
			}
		}
	}
	if err := s.authorizeClass(ctx, a, c, se); err != nil {
		return nil, err
	}
	out := &RemarksPage{Classroom: classRef(c), Students: []RemarkStudent{}, RecentSessions: []SessionChoice{}}
	inRecent := false
	for _, r := range recent {
		inRecent = inRecent || (se != nil && r.ID == se.ID)
		out.RecentSessions = append(out.RecentSessions, SessionChoice{ID: r.ID, Label: sessionChoiceLabel(r.Date, r.StartTime, r.EndTime, r.Type, r.Status),
			Disabled: r.Status == "cancelled"})
	}
	if se != nil {
		if !inRecent {
			// Buổi đang xem cũ hơn cửa sổ vẫn phải có trong ô chọn.
			out.RecentSessions = append(out.RecentSessions, SessionChoice{ID: se.ID,
				Label: sessionChoiceLabel(se.Date, se.StartTime, se.EndTime, se.Type, se.Status), Disabled: se.Status == "cancelled"})
		}
		out.BlockReason = s.blockReason(se)
		numbers, err := s.sessionNumbers(ctx, classID)
		if err != nil {
			return nil, err
		}
		label := se.Date.Format("02/01/2006") + " · " + se.StartTime + "-" + se.EndTime
		if n := numbers[se.ID]; n > 0 {
			label = "Buổi " + strconv.Itoa(n) + ": " + label
		}
		out.Session = &SessionRef{ID: se.ID, Label: label}
		roster, err := s.q.SessionRoster(ctx, teacherdb.SessionRosterParams{ClassID: classID, SessionID: &se.ID})
		if err != nil {
			return nil, err
		}
		att, err := s.q.SessionAttendanceStatuses(ctx, se.ID)
		if err != nil {
			return nil, err
		}
		status := make(map[uuid.UUID]string, len(att))
		for _, r := range att {
			status[r.StudentID] = r.Status
		}
		existing := map[uuid.UUID]*teacherdb.SheetRemarksRow{}
		sheet, err := s.q.RemarkSheet(ctx, se.ID)
		switch {
		case err == nil:
			state := "saved"
			if sheet.Status == "draft" {
				state = "draft"
			}
			out.RecordState = &state
			rows, err := s.q.SheetRemarks(ctx, sheet.ID)
			if err != nil {
				return nil, err
			}
			for i := range rows {
				existing[rows[i].StudentID] = &rows[i]
			}
		case !isNoRows(err):
			return nil, err
		}
		for _, st := range roster {
			as := status[st.ID]
			if as == "" {
				as = "none"
			}
			out.Students = append(out.Students, RemarkStudent{ID: st.ID, Name: st.FullName, AttStatus: as,
				Absent: as == "absent" || as == "excused", Remark: remarkMap(existing[st.ID])})
		}
	}
	if out.TrialGuests, err = s.guestsOf(ctx, se); err != nil {
		return nil, err
	}
	return out, nil
}

// RemarksInput: nhận xét theo học viên (khóa = id học viên) — chỉ học viên của buổi được lưu.
type RemarksInput struct {
	SessionID *uuid.UUID                   `json:"session_id"`
	Date      string                       `json:"date"` // YYYY-MM-DD khi không chọn buổi
	Action    string                       `json:"action"`
	Remarks   map[string]map[string]string `json:"remarks"`
}

func (s *Service) SaveRemarks(ctx context.Context, a *iam.Access, classID uuid.UUID, in RemarksInput) (uuid.UUID, string, error) {
	c, err := s.loadClass(ctx, a, classID)
	if err != nil {
		return uuid.Nil, "", err
	}
	if in.Action != "" && in.Action != "draft" && in.Action != "final" {
		return uuid.Nil, "", iam.Invalid("Thao tác không hợp lệ.")
	}
	for _, r := range in.Remarks {
		for _, v := range r {
			if tooLong(v, 2000) {
				return uuid.Nil, "", iam.Invalid("Nhận xét không được vượt quá 2000 ký tự.")
			}
		}
	}
	var day *time.Time
	if strings.TrimSpace(in.Date) != "" {
		d, ok := parseDay(in.Date)
		if !ok {
			return uuid.Nil, "", iam.Invalid("Ngày không hợp lệ.")
		}
		day = &d
	}
	se, err := s.resolveSession(ctx, a.UserID, classID, in.SessionID, day)
	if err != nil {
		return uuid.Nil, "", err
	}
	if err := s.authorizeClass(ctx, a, c, se); err != nil {
		return uuid.Nil, "", err
	}
	if se == nil {
		return uuid.Nil, "", iam.Invalid("Lớp không có buổi học trong ngày này. Hãy chọn buổi cần nhận xét.")
	}
	if reason := s.blockReason(se); reason != "" {
		return uuid.Nil, "", iam.Invalid(reason)
	}
	roster, err := s.q.SessionRoster(ctx, teacherdb.SessionRosterParams{ClassID: classID})
	if err != nil {
		return uuid.Nil, "", err
	}
	draft := in.Action == "draft"
	status := "completed"
	if draft {
		status = "draft"
	}
	err = s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		sheetID, err := q.UpsertRemarkSheet(ctx, teacherdb.UpsertRemarkSheetParams{SessionID: se.ID, ClassID: classID, Status: status, ByUser: &a.UserID})
		if err != nil {
			return err
		}
		if err := q.ClearRemarks(ctx, sheetID); err != nil {
			return err
		}
		n := 0
		for _, st := range roster {
			r, ok := in.Remarks[st.ID.String()]
			if !ok {
				continue
			}
			p := teacherdb.InsertRemarkParams{SheetID: sheetID, StudentID: st.ID, MonstersGroup: optText(r["monsters_group"]),
				MonstersBonus: optText(r["monsters_bonus"]), Grammar: optText(r["grammar"]), Attitude: optText(r["attitude"]),
				Result: optText(r["result"]), Comment: optText(r["comment"])}
			if p.MonstersGroup == nil && p.MonstersBonus == nil && p.Grammar == nil && p.Attitude == nil && p.Result == nil && p.Comment == nil {
				continue
			}
			if err := q.InsertRemark(ctx, p); err != nil {
				return err
			}
			n++
		}
		title := "Nhận xét lớp " + c.Name + " buổi " + se.Date.Format("02/01/2006") + " " + se.StartTime
		desc := title + " (" + strconv.Itoa(n) + " học sinh)"
		if draft {
			desc += " — lưu nháp"
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleStudents, Event: audit.EventUpdated,
			Action: "remark_sheet.save", SubjectType: "remark_sheet", SubjectID: sheetID.String(), SubjectLabel: title,
			BranchID: &c.BranchID, Description: desc})
	})
	if err != nil {
		return uuid.Nil, "", err
	}
	if draft {
		return se.ID, "Đã lưu nháp nhận xét (chưa hiển thị cho học viên).", nil
	}
	return se.ID, "Đã lưu nhận xét buổi học!", nil
}
