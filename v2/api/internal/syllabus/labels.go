package syllabus

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
)

// Nhãn / mã hiển thị, loại thông báo và nhật ký vận hành của phân hệ.

func init() {
	for k, t := range map[string]notify.TypeInfo{
		"syllabus_stage":    {Label: "Chặng giáo trình", Icon: "menu_book", Tone: notify.TonePrimary},
		"syllabus_proposal": {Label: "Đề xuất sửa giáo trình", Icon: "edit_note", Tone: notify.ToneWarning},
		"syllabus_review":   {Label: "Kết quả duyệt giáo trình", Icon: "fact_check", Tone: notify.ToneInfo},
		"big_test_order":    {Label: "Order đề Big Test", Icon: "quiz", Tone: notify.TonePrimary},
		"big_test_upcoming": {Label: "Nhắc lịch Big Test", Icon: "event_upcoming", Tone: notify.ToneWarning},
		"big_test_reminder": {Label: "Nhắc lịch thi", Icon: "alarm", Tone: notify.ToneInfo},
		"info":              {Label: "Thông tin", Icon: "info", Tone: notify.ToneInfo},
	} {
		notify.RegisterType(k, t)
	}
	for k, v := range map[string]string{
		"syllabus_curriculum": "Giáo trình", "syllabus_stage": "Chặng giáo trình", "syllabus_unit": "Unit giáo trình",
		"syllabus_lesson": "Buổi giáo trình", "syllabus_document": "Tài liệu giáo trình", "syllabus_assignment": "Chặng của lớp",
		"syllabus_proposal": "Đề xuất sửa giáo trình", "syllabus_pacing": "Yêu cầu giãn tiến độ", "big_test": "Đợt Big Test",
		"big_test_order": "Order đề Big Test", "big_test_result": "Kết quả Big Test",
	} {
		audit.RegisterSubject(k, v)
	}
	audit.RegisterPath("/teacher/order-test", audit.ModuleSyllabus)
}

// record ghi nhật ký vận hành phân hệ Giáo trình trong tx.
func record(ctx context.Context, tx pgx.Tx, by *uuid.UUID, event, action, subjectType string, id uuid.UUID, label, desc string, branch *uuid.UUID) error {
	return audit.Record(ctx, tx, audit.Entry{
		ActorID: by, Module: audit.ModuleSyllabus, Event: event, Action: action, SubjectType: subjectType,
		SubjectID: id.String(), SubjectLabel: label, BranchID: branch, Description: desc,
	})
}

// send gửi thông báo, bỏ qua danh sách rỗng.
func send(ctx context.Context, db notify.DB, ids []uuid.UUID, n notify.Notification) error {
	if len(ids) == 0 {
		return nil
	}
	_, err := notify.Send(ctx, db, ids, n)
	return err
}

// notifyReview: kết quả duyệt gửi người gửi yêu cầu (loại syllabus_review).
func notifyReview(ctx context.Context, db notify.DB, user *uuid.UUID, title, body, link string, by uuid.UUID) error {
	if user == nil || *user == uuid.Nil {
		return nil
	}
	return send(ctx, db, []uuid.UUID{*user}, notify.Notification{Type: "syllabus_review", Title: title, Body: body, Link: link, CreatedBy: &by})
}

// stageLabel: "Chặng N: tên" trừ khi tên đã bắt đầu bằng "Chặng N" (SyllabusStage::label).
func stageLabel(position int16, name string) string {
	prefix := "Chặng " + strconv.Itoa(int(position))
	if strings.HasPrefix(strings.ToLower(name), strings.ToLower(prefix)) {
		return name
	}
	return prefix + ": " + name
}

// stageOr: nhãn chặng thật (còn), không thì tên chặng lưu lúc mở / nhập tự do.
func stageOr(position *int16, title *string, fallback string) string {
	if position != nil && title != nil {
		return stageLabel(*position, *title)
	}
	return fallback
}

// rangeLabel: "Unit 1–3 · Buổi 1–12" (SyllabusStage::rangeSummary); không có unit / buổi → nhãn chặng.
func rangeLabel(unitMin, unitMax, sessionMin, sessionMax int32, label string) string {
	var parts []string
	span := func(prefix string, a, b int32) string {
		if a == b {
			return fmt.Sprintf("%s %d", prefix, a)
		}
		return fmt.Sprintf("%s %d–%d", prefix, a, b)
	}
	if unitMin > 0 {
		parts = append(parts, span("Unit", unitMin, unitMax))
	}
	if sessionMin > 0 {
		parts = append(parts, span("Buổi", sessionMin, sessionMax))
	}
	if len(parts) == 0 {
		return label
	}
	return strings.Join(parts, " · ")
}

// assignmentCode: CH-<năm mở>-<số 3 chữ số> (SyllabusAssignment::code).
func assignmentCode(no *int64, openedAt *time.Time, createdAt time.Time) string {
	at := createdAt
	if openedAt != nil {
		at = *openedAt
	}
	return fmt.Sprintf("CH-%d-%03d", at.In(vnZone).Year(), deref(no))
}

var statusLabels = map[string]string{"pending": "Chờ duyệt", "approved": "Đã duyệt", "rejected": "Đã từ chối"}

var statusColors = map[string]string{"pending": "warning", "approved": "success", "rejected": "error"}

func statusLabel(s string) string {
	if l, ok := statusLabels[s]; ok {
		return l
	}
	return s
}

func statusColor(s string) string {
	if c, ok := statusColors[s]; ok {
		return c
	}
	return "neutral"
}

var orderStatusLabels = map[string]string{"pending": "Chờ duyệt", "approved": "Đã duyệt & phân phối", "rejected": "Đã từ chối"}

var orderTypeLabels = map[string]string{"mini": "Mini Test", "big": "Big Test"}

func orderStatusLabel(s string) string {
	if l, ok := orderStatusLabels[s]; ok {
		return l
	}
	return s
}

func orderTypeLabel(t string) string {
	if l, ok := orderTypeLabels[t]; ok {
		return l
	}
	return t
}

var resultStatusLabels = map[string]string{"draft": "Nháp", "pending_review": "Chờ duyệt", "approved": "Đã duyệt", "sent": "Đã gửi phụ huynh"}

func resultStatusLabel(s string) string {
	if l, ok := resultStatusLabels[s]; ok {
		return l
	}
	return s
}

var assignmentStatusLabels = map[string]string{"in_progress": "Đang mở", "completed": "Đã đóng"}

// proposalTarget: "Buổi N: tên (Unit M)" / "Unit M: tên" / "Chung toàn giáo trình" (SyllabusChangeProposal::target_label).
func proposalTarget(sessionNo *int32, lessonTitle *string, unitNumber *int32, unitTitle *string) string {
	if sessionNo != nil {
		s := fmt.Sprintf("Buổi %d: %s", *sessionNo, deref(lessonTitle))
		if unitNumber != nil {
			s += fmt.Sprintf(" (Unit %d)", *unitNumber)
		}
		return s
	}
	if unitNumber != nil {
		return fmt.Sprintf("Unit %d: %s", *unitNumber, deref(unitTitle))
	}
	return "Chung toàn giáo trình"
}

// Loại tài liệu theo đuôi (SyllabusDocument::kind / icon / inline_viewable).
func docKind(ext string) string {
	switch ext {
	case "pdf":
		return "pdf"
	case "doc", "docx":
		return "word"
	case "ppt", "pptx":
		return "slide"
	case "xls", "xlsx":
		return "sheet"
	case "jpg", "jpeg", "png", "gif", "webp":
		return "image"
	case "mp3", "wav", "m4a", "ogg":
		return "audio"
	case "mp4", "mov", "webm", "m4v":
		return "video"
	}
	return "file"
}

func docIcon(kind string) string {
	switch kind {
	case "pdf":
		return "picture_as_pdf"
	case "word":
		return "description"
	case "slide":
		return "co_present"
	case "sheet":
		return "table_chart"
	case "image":
		return "image"
	case "audio":
		return "headphones"
	case "video":
		return "movie"
	}
	return "draft"
}

func inlineKind(kind string) bool {
	return kind == "pdf" || kind == "image" || kind == "audio" || kind == "video"
}

// sizeHuman: "12 B" / "3.4 KB" / "1.2 MB" (SyllabusDocument::size_human).
func sizeHuman(b int64) string {
	switch {
	case b < 1024:
		return fmt.Sprintf("%d B", b)
	case b < 1048576:
		return trimZero(float64(b)/1024) + " KB"
	}
	return trimZero(float64(b)/1048576) + " MB"
}

func trimZero(f float64) string {
	s := strconv.FormatFloat(f, 'f', 1, 64)
	return strings.TrimSuffix(s, ".0")
}

// fmtScore: điểm 1 chữ số thập phân ("7.5", "8"); nil → "-".
func fmtScore(v *float64) string {
	if v == nil {
		return "-"
	}
	return trimZero(*v)
}

func uniqueIDs(ids ...*uuid.UUID) []uuid.UUID {
	out := []uuid.UUID{}
	seen := map[uuid.UUID]bool{}
	for _, id := range ids {
		if id != nil && *id != uuid.Nil && !seen[*id] {
			seen[*id] = true
			out = append(out, *id)
		}
	}
	return out
}

// dedupe: bỏ id trùng / uuid.Nil, giữ thứ tự.
func dedupe(ids []uuid.UUID) []uuid.UUID {
	out := []uuid.UUID{}
	seen := map[uuid.UUID]bool{}
	for _, id := range ids {
		if id != uuid.Nil && !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	return out
}
