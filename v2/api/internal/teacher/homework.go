package teacher

import (
	"context"
	"encoding/json"
	"net/url"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/files"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/platform/filestore"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/teacher/teacherdb"
)

// Giao bài tập về nhà (TeacherPortalController::homework*): chọn buổi học, hạn nộp, ghi chú nhắc cả lớp, tài liệu tham khảo
// (YouTube, file nghe, Quizizz), hạng mục bài tập (≥ 1) kèm yêu cầu. Hạng mục đã có học sinh nộp bài thì khóa (không
// bỏ được khi sửa); bài đã có bài nộp không xóa được.

const (
	audioSubject      = "homework_audio"
	submissionSubject = "homework_submission"
)

// Category: hạng mục bài tập (Homework::CATEGORIES) — khóa trùng homework_type khi học viên nộp bài.
type Category struct {
	Key         string `json:"key"`
	Label       string `json:"label"`
	Icon        string `json:"icon"`
	Placeholder string `json:"placeholder"`
}

var Categories = []Category{
	{"video", "Quay video", "videocam", "Nhập chi tiết yêu cầu bài tập (bắt buộc)..."},
	{"vocabulary", "Viết từ vựng", "edit_document", "Nhập danh sách từ vựng cần viết, số lần chép phạt (bắt buộc)..."},
	{"workbook", "Workbook", "menu_book", "Nhập số trang, bài tập cụ thể trong Workbook (bắt buộc)..."},
	{"extra_book", "Sách bổ trợ", "library_books", "Nhập tên sách và bài tập cần làm (bắt buộc)..."},
	{"quiz", "Quiz", "psychology_alt", "Nhập chủ đề Quiz hoặc link bài tập (bắt buộc)..."},
	{"bgd_book", "Sách bộ giáo dục", "school", "Nhập chi tiết bài tập sách giáo khoa..."},
}

func categoryLabel(key string) string {
	for _, c := range Categories {
		if c.Key == key {
			return c.Label
		}
	}
	return key
}

func validCategory(key string) bool {
	return slices.ContainsFunc(Categories, func(c Category) bool { return c.Key == key })
}

// AudioPolicy: file nghe đính kèm bài tập (audio, tối đa 50 MB như Laravel max:51200).
var AudioPolicy = filestore.Policy{Types: filestore.Audio, MaxBytes: 51200 << 10, TooLarge: "File nghe tối đa 50 MB."}

type Option struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

type HomeworkItem struct {
	Key    string `json:"key"`
	Label  string `json:"label"`
	Text   string `json:"text"`
	Locked bool   `json:"locked"`
}

type HomeworkRow struct {
	ID          uuid.UUID      `json:"id"`
	Title       string         `json:"title"`
	DueLabel    string         `json:"due_label"`
	SessionDate string         `json:"session_date"`
	TeacherName string         `json:"teacher_name"`
	Items       []HomeworkItem `json:"items"`
	Description string         `json:"description"`
	ClassNote   string         `json:"class_note"`
	YoutubeURL  string         `json:"youtube_url"`
	AudioURL    string         `json:"audio_url"`
	QuizizzURL  string         `json:"quizizz_url"`
	Deletable   bool           `json:"deletable"`
}

type HomeworkEdit struct {
	ID         uuid.UUID         `json:"id"`
	SessionID  *uuid.UUID        `json:"session_id"`
	DueAt      string            `json:"due_at"`
	ClassNote  string            `json:"class_note"`
	YoutubeURL string            `json:"youtube_url"`
	QuizizzURL string            `json:"quizizz_url"`
	HasAudio   bool              `json:"has_audio"`
	Items      map[string]string `json:"items"`
	Locked     []string          `json:"locked"`
}

type HomeworkPage struct {
	Classroom         ClassRef      `json:"classroom"`
	Categories        []Category    `json:"categories"`
	Sessions          []Option      `json:"sessions"`
	SelectedSessionID *string       `json:"selected_session_id"`
	DueDefault        string        `json:"due_default"` // YYYY-MM-DDTHH:MM
	Editing           *HomeworkEdit `json:"editing"`
	Homeworks         []HomeworkRow `json:"homeworks"`
}

func parseItems(raw []byte) map[string]string {
	if len(raw) == 0 {
		return nil
	}
	m := map[string]string{}
	if json.Unmarshal(raw, &m) != nil {
		return nil
	}
	return m
}

// itemKeys: khóa hạng mục theo thứ tự Categories (khóa lạ xếp cuối).
func itemKeys(items map[string]string) []string {
	keys := make([]string, 0, len(items))
	for _, c := range Categories {
		if _, ok := items[c.Key]; ok {
			keys = append(keys, c.Key)
		}
	}
	for k := range items {
		if !validCategory(k) {
			keys = append(keys, k)
		}
	}
	return keys
}

type hwLite struct {
	ID        uuid.UUID
	Items     map[string]string
	CreatedAt time.Time
}

// lockedTypes: hạng mục đã có học sinh của lớp nộp bài (từ lúc giao bài) — theo homework_type của bài nộp.
func (s *Service) lockedTypes(ctx context.Context, q *teacherdb.Queries, classID uuid.UUID, hws []hwLite) (map[uuid.UUID][]string, error) {
	out := map[uuid.UUID][]string{}
	var since *time.Time
	for _, h := range hws {
		if len(h.Items) > 0 && (since == nil || h.CreatedAt.Before(*since)) {
			since = ptr(h.CreatedAt)
		}
	}
	if since == nil {
		return out, nil
	}
	subs, err := q.RosterSubmissionTypes(ctx, teacherdb.RosterSubmissionTypesParams{ClassID: &classID, Since: *since})
	if err != nil {
		return nil, err
	}
	for _, h := range hws {
		if len(h.Items) == 0 {
			continue
		}
		var locked []string
		for _, key := range itemKeys(h.Items) {
			if slices.ContainsFunc(subs, func(sb teacherdb.RosterSubmissionTypesRow) bool {
				return sb.HomeworkType == key && !sb.CreatedAt.Before(h.CreatedAt)
			}) {
				locked = append(locked, key)
			}
		}
		if len(locked) > 0 {
			out[h.ID] = locked
		}
	}
	return out, nil
}

// sessionNumbers: số thứ tự buổi chính khóa của lớp.
func (s *Service) sessionNumbers(ctx context.Context, classID uuid.UUID) (map[uuid.UUID]int, error) {
	rows, err := s.q.SessionNumbers(ctx, classID)
	if err != nil {
		return nil, err
	}
	out := make(map[uuid.UUID]int, len(rows))
	for _, r := range rows {
		out[r.ID] = int(r.No)
	}
	return out, nil
}

func dueLabel(dueAt, dueDate *time.Time) string {
	switch {
	case dueAt != nil:
		return dueAt.In(vnZone).Format("15:04 02/01/2006")
	case dueDate != nil:
		return dueDate.Format("02/01/2006")
	}
	return "Không giới hạn"
}

// HomeworkPage: trang giao bài (edit = id bài đang sửa, session = buổi chọn sẵn).
func (s *Service) HomeworkPage(ctx context.Context, a *iam.Access, classID uuid.UUID, edit, session *uuid.UUID) (*HomeworkPage, error) {
	c, err := s.classFor(ctx, a, classID)
	if err != nil {
		return nil, err
	}
	rows, err := s.q.ClassHomeworks(ctx, classID)
	if err != nil {
		return nil, err
	}
	lite := make([]hwLite, len(rows))
	for i, r := range rows {
		lite[i] = hwLite{r.ID, parseItems(r.Items), r.CreatedAt}
	}
	locked, err := s.lockedTypes(ctx, s.q, classID, lite)
	if err != nil {
		return nil, err
	}
	today := s.today()
	sessions, err := s.q.HomeworkSessions(ctx, teacherdb.HomeworkSessionsParams{ClassID: classID,
		FromDate: today.AddDate(0, 0, -makeupLookbackDays), ToDate: today.AddDate(0, 0, 14)})
	if err != nil {
		return nil, err
	}
	numbers, err := s.sessionNumbers(ctx, classID)
	if err != nil {
		return nil, err
	}
	var nos []int
	for _, se := range sessions {
		if n := numbers[se.ID]; n > 0 {
			nos = append(nos, n)
		}
	}
	lessons, err := s.syl.Lessons(ctx, classID, nos)
	if err != nil {
		return nil, err
	}
	out := &HomeworkPage{Classroom: classRef(c), Categories: Categories, Sessions: []Option{}, Homeworks: []HomeworkRow{},
		DueDefault: s.now().In(vnZone).AddDate(0, 0, 3).Format("2006-01-02") + "T20:00"}
	var todaySession *uuid.UUID
	for _, se := range sessions {
		label := se.Date.Format("02/01") + " " + se.StartTime
		if n := numbers[se.ID]; n > 0 {
			label = "Buổi " + strconv.Itoa(n) + ": " + label
			if l, ok := lessons[n]; ok {
				if l.Title != "" {
					label += " — " + l.Title
				} else if l.Unit != "" {
					label += " — " + l.Unit
				}
			}
		}
		if se.Type == "makeup" {
			label += " (học bù)"
		}
		out.Sessions = append(out.Sessions, Option{se.ID.String(), label})
		if todaySession == nil && se.Date.Equal(today) {
			todaySession = ptr(se.ID)
		}
	}
	for _, r := range rows {
		items := parseItems(r.Items)
		row := HomeworkRow{ID: r.ID, Title: r.Title, DueLabel: dueLabel(r.DueAt, r.DueDate), TeacherName: deref(r.TeacherName),
			Items: []HomeworkItem{}, Description: deref(r.Description), ClassNote: deref(r.ClassNote), YoutubeURL: deref(r.YoutubeUrl),
			QuizizzURL: deref(r.QuizizzUrl), Deletable: len(locked[r.ID]) == 0}
		if r.SessionDate != nil {
			row.SessionDate = r.SessionDate.Format("02/01")
		}
		if r.AudioFileID != nil {
			row.AudioURL = files.URL(*r.AudioFileID)
		}
		for _, k := range itemKeys(items) {
			row.Items = append(row.Items, HomeworkItem{Key: k, Label: categoryLabel(k), Text: items[k], Locked: slices.Contains(locked[r.ID], k)})
		}
		out.Homeworks = append(out.Homeworks, row)
		if edit != nil && *edit == r.ID {
			e := &HomeworkEdit{ID: r.ID, SessionID: r.SessionID, ClassNote: row.ClassNote, YoutubeURL: row.YoutubeURL,
				QuizizzURL: row.QuizizzURL, HasAudio: r.AudioFileID != nil, Items: items, Locked: locked[r.ID]}
			if e.Items == nil {
				e.Items = map[string]string{}
			}
			if e.Locked == nil {
				e.Locked = []string{}
			}
			switch {
			case r.DueAt != nil:
				e.DueAt = r.DueAt.In(vnZone).Format("2006-01-02T15:04")
			case r.DueDate != nil:
				e.DueAt = r.DueDate.Format("2006-01-02") + "T23:59"
			}
			if e.DueAt != "" {
				out.DueDefault = e.DueAt
			}
			out.Editing = e
		}
	}
	if edit != nil && out.Editing == nil {
		return nil, ErrHomeworkNotFound
	}
	switch {
	case out.Editing != nil && out.Editing.SessionID != nil:
		out.SelectedSessionID = ptr(out.Editing.SessionID.String())
	case session != nil:
		out.SelectedSessionID = ptr(session.String())
	case todaySession != nil:
		out.SelectedSessionID = ptr(todaySession.String())
	}
	return out, nil
}

// HomeworkInput: dữ liệu form giao / sửa bài.
type HomeworkInput struct {
	SessionID   *uuid.UUID        `json:"session_id"`
	DueAt       string            `json:"due_at"` // YYYY-MM-DDTHH:MM (giờ Việt Nam)
	ClassNote   string            `json:"class_note"`
	YoutubeURL  string            `json:"youtube_url"`
	QuizizzURL  string            `json:"quizizz_url"`
	AudioFileID *uuid.UUID        `json:"audio_file_id"` // file nghe vừa tải lên (UploadAudio); trống = giữ file cũ
	Categories  []string          `json:"categories"`
	Items       map[string]string `json:"items"`
}

func validURL(v string) bool {
	u, err := url.ParseRequestURI(v)
	return err == nil && (u.Scheme == "http" || u.Scheme == "https") && u.Host != ""
}

func parseDueAt(v string) (time.Time, bool) {
	v = strings.TrimSpace(v)
	for _, layout := range []string{"2006-01-02T15:04", "2006-01-02T15:04:05", "2006-01-02 15:04"} {
		if t, err := time.ParseInLocation(layout, v, vnZone); err == nil {
			return t, true
		}
	}
	if t, err := time.Parse(time.RFC3339, v); err == nil {
		return t, true
	}
	return time.Time{}, false
}

type homeworkData struct {
	session     *sessionInfo
	title       string
	description string
	items       map[string]string
	dueAt       time.Time
}

// validateHomework: kiểm + chuẩn hóa form (validatedHomework). existing: bài đang sửa (hạng mục đã khóa giữ lại).
func (s *Service) validateHomework(ctx context.Context, q *teacherdb.Queries, classID uuid.UUID, in HomeworkInput, existing *teacherdb.GetHomeworkRow) (*homeworkData, error) {
	in.ClassNote, in.YoutubeURL, in.QuizizzURL = strings.TrimSpace(in.ClassNote), strings.TrimSpace(in.YoutubeURL), strings.TrimSpace(in.QuizizzURL)
	switch {
	case tooLong(in.ClassNote, 2000):
		return nil, iam.Invalid("Ghi chú nhắc nhở không được vượt quá 2000 ký tự.")
	case in.YoutubeURL != "" && !validURL(in.YoutubeURL):
		return nil, iam.Invalid("Link YouTube không hợp lệ.")
	case tooLong(in.YoutubeURL, 500):
		return nil, iam.Invalid("Link YouTube không được vượt quá 500 ký tự.")
	case in.QuizizzURL != "" && !validURL(in.QuizizzURL):
		return nil, iam.Invalid("Link Quizizz không hợp lệ.")
	case tooLong(in.QuizizzURL, 500):
		return nil, iam.Invalid("Link Quizizz không được vượt quá 500 ký tự.")
	}
	picked := map[string]bool{}
	for _, k := range in.Categories {
		if !validCategory(k) {
			return nil, iam.Invalid("Hạng mục bài tập không hợp lệ.")
		}
		picked[k] = true
	}
	for k, v := range in.Items {
		if tooLong(strings.TrimSpace(v), 2000) {
			return nil, iam.Invalid(`Yêu cầu hạng mục "` + categoryLabel(k) + `" không được vượt quá 2000 ký tự.`)
		}
	}
	var se *sessionInfo
	if in.SessionID != nil {
		r, err := q.GetSession(ctx, *in.SessionID)
		if isNoRows(err) || (err == nil && r.ClassID != classID) {
			return nil, iam.Invalid("Buổi học không thuộc lớp này.")
		}
		if err != nil {
			return nil, err
		}
		se = &r
	}
	var old map[string]string
	if existing != nil {
		old = parseItems(existing.Items)
		locked, err := s.lockedTypes(ctx, q, classID, []hwLite{{existing.ID, old, existing.CreatedAt}})
		if err != nil {
			return nil, err
		}
		// Hạng mục đã có học sinh nộp bài không được bỏ khi sửa.
		for _, k := range locked[existing.ID] {
			picked[k] = true
		}
	}
	if len(picked) == 0 {
		return nil, iam.Invalid("Cần giao ít nhất 1 hạng mục.")
	}
	items := map[string]string{}
	for _, c := range Categories {
		if !picked[c.Key] {
			continue
		}
		text, ok := in.Items[c.Key]
		if !ok {
			text = old[c.Key]
		}
		text = strings.TrimSpace(text)
		if text == "" {
			return nil, iam.Invalid(`Nhập chi tiết yêu cầu cho hạng mục "` + c.Label + `".`)
		}
		items[c.Key] = text
	}
	if se == nil {
		return nil, iam.Invalid("Vui lòng chọn buổi học.")
	}
	if strings.TrimSpace(in.DueAt) == "" {
		return nil, iam.Invalid("Vui lòng chọn hạn nộp.")
	}
	due, ok := parseDueAt(in.DueAt)
	if !ok {
		return nil, iam.Invalid("Hạn nộp không hợp lệ.")
	}
	keys := itemKeys(items)
	labels := make([]string, len(keys))
	lines := make([]string, len(keys))
	for i, k := range keys {
		labels[i] = categoryLabel(k)
		lines[i] = categoryLabel(k) + ": " + items[k]
	}
	return &homeworkData{session: se, items: items, dueAt: due,
		title:       "Bài tập buổi " + se.Date.Format("02/01") + ": " + strings.Join(labels, ", "),
		description: strings.Join(lines, "\n")}, nil
}

// attachAudio: gắn file nghe vừa tải lên (của chính người này, chưa gắn bài nào) vào bài tập.
func (s *Service) attachAudio(ctx context.Context, tx pgx.Tx, a *iam.Access, fileID, homeworkID uuid.UUID) error {
	if s.files == nil {
		return iam.Invalid("Kho file chưa được cấu hình.")
	}
	f, err := s.files.Get(ctx, fileID)
	if err != nil || f.SubjectType != audioSubject || f.UploadedBy == nil || *f.UploadedBy != a.UserID {
		return iam.Invalid("File nghe không hợp lệ hoặc đã bị xoá — hãy tải lại file.")
	}
	if f.SubjectID != nil {
		if *f.SubjectID == homeworkID {
			return nil
		}
		return iam.Invalid("File nghe không hợp lệ hoặc đã bị xoá — hãy tải lại file.")
	}
	_, err = s.files.Attach(ctx, tx, fileID, audioSubject, homeworkID)
	return err
}

func itemsJSON(items map[string]string) []byte {
	b, _ := json.Marshal(items)
	return b
}

// SaveHomework: giao bài mới (id nil) hoặc sửa bài của lớp.
func (s *Service) SaveHomework(ctx context.Context, a *iam.Access, classID uuid.UUID, id *uuid.UUID, in HomeworkInput) (uuid.UUID, string, error) {
	c, err := s.classFor(ctx, a, classID)
	if err != nil {
		return uuid.Nil, "", err
	}
	var hid uuid.UUID
	msg := "Đã giao bài tập về nhà cho lớp!"
	err = s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		var existing *teacherdb.GetHomeworkRow
		if id != nil {
			r, err := q.GetHomework(ctx, teacherdb.GetHomeworkParams{ID: *id, ClassID: classID})
			if isNoRows(err) {
				return ErrHomeworkNotFound
			}
			if err != nil {
				return err
			}
			existing = &r
		}
		d, err := s.validateHomework(ctx, q, classID, in, existing)
		if err != nil {
			return err
		}
		dueDate := dateOf(d.dueAt)
		entry := audit.Entry{ActorID: &a.UserID, Module: audit.ModuleStudents, SubjectType: "homework", BranchID: &c.BranchID,
			SubjectLabel: d.title + " · " + c.Name}
		if existing == nil {
			hid, err = q.InsertHomework(ctx, teacherdb.InsertHomeworkParams{ClassID: classID, SessionID: &d.session.ID, TeacherID: &a.UserID,
				Title: d.title, Description: optText(d.description), ClassNote: optText(in.ClassNote), YoutubeUrl: optText(in.YoutubeURL),
				QuizizzUrl: optText(in.QuizizzURL), Items: itemsJSON(d.items), DueDate: &dueDate, DueAt: &d.dueAt})
			if err != nil {
				return err
			}
			entry.Event, entry.Action, entry.Description = audit.EventCreated, "homework.create", "Giao bài tập về nhà lớp "+c.Name
		} else {
			hid = existing.ID
			if in.AudioFileID != nil && existing.AudioFileID != nil && *existing.AudioFileID != *in.AudioFileID && s.files != nil {
				// Thay file nghe: file cũ xóa mềm (giữ 30 ngày).
				if _, err := s.files.Delete(ctx, tx, *existing.AudioFileID, &a.UserID, audit.Entry{Module: audit.ModuleStudents,
					SubjectType: "homework", SubjectID: hid.String(), Description: "Thay file nghe bài tập về nhà"}); err != nil && err != files.ErrNotFound {
					return err
				}
			}
			if err := q.UpdateHomework(ctx, teacherdb.UpdateHomeworkParams{ID: hid, SessionID: &d.session.ID, Title: d.title,
				Description: optText(d.description), ClassNote: optText(in.ClassNote), YoutubeUrl: optText(in.YoutubeURL),
				QuizizzUrl: optText(in.QuizizzURL), AudioFileID: in.AudioFileID, Items: itemsJSON(d.items), DueDate: &dueDate, DueAt: &d.dueAt}); err != nil {
				return err
			}
			entry.Event, entry.Action, entry.Description = audit.EventUpdated, "homework.update", "Cập nhật bài tập về nhà lớp "+c.Name
			msg = "Đã cập nhật bài tập về nhà!"
		}
		if in.AudioFileID != nil {
			if err := s.attachAudio(ctx, tx, a, *in.AudioFileID, hid); err != nil {
				return err
			}
			if existing == nil {
				if err := q.SetHomeworkAudio(ctx, teacherdb.SetHomeworkAudioParams{ID: hid, AudioFileID: in.AudioFileID}); err != nil {
					return err
				}
			}
		}
		entry.SubjectID = hid.String()
		return audit.Record(ctx, tx, entry)
	})
	if err != nil {
		return uuid.Nil, "", err
	}
	s.log.Info("homework saved", "class_id", classID, "homework_id", hid, "by", a.UserID)
	return hid, msg, nil
}

// DeleteHomework: xóa (mềm) bài chưa có học sinh nộp.
func (s *Service) DeleteHomework(ctx context.Context, a *iam.Access, classID, id uuid.UUID) error {
	c, err := s.classFor(ctx, a, classID)
	if err != nil {
		return err
	}
	return s.tx(ctx, func(tx pgx.Tx, q *teacherdb.Queries) error {
		r, err := q.GetHomework(ctx, teacherdb.GetHomeworkParams{ID: id, ClassID: classID})
		if isNoRows(err) {
			return ErrHomeworkNotFound
		}
		if err != nil {
			return err
		}
		locked, err := s.lockedTypes(ctx, q, classID, []hwLite{{r.ID, parseItems(r.Items), r.CreatedAt}})
		if err != nil {
			return err
		}
		if len(locked[r.ID]) > 0 {
			return &iam.Error{Status: 409, Code: "conflict", Message: "Bài tập đã có học sinh nộp bài, không xóa được."}
		}
		if err := q.SoftDeleteHomework(ctx, id); err != nil {
			return err
		}
		return audit.Record(ctx, tx, audit.Entry{ActorID: &a.UserID, Module: audit.ModuleStudents, Event: audit.EventDeleted,
			Action: "homework.delete", SubjectType: "homework", SubjectID: id.String(), SubjectLabel: r.Title + " · " + c.Name,
			BranchID: &c.BranchID, Description: "Xóa bài tập về nhà lớp " + c.Name})
	})
}

// UploadedAudio: file nghe vừa tải lên (gửi audio_file_id khi lưu bài).
type UploadedAudio struct {
	ID   uuid.UUID `json:"id"`
	Name string    `json:"name"`
	URL  string    `json:"url"`
}

// UploadAudio: tải file nghe cho bài tập của lớp (chưa gắn bài; gắn khi lưu). up đã qua Check(AudioPolicy).
func (s *Service) UploadAudio(ctx context.Context, a *iam.Access, classID uuid.UUID, up *filestore.Upload) (*UploadedAudio, error) {
	c, err := s.classFor(ctx, a, classID)
	if err != nil {
		return nil, err
	}
	if s.files == nil {
		return nil, iam.Invalid("Kho file chưa được cấu hình.")
	}
	f, err := s.files.Save(ctx, nil, up, files.SaveInput{
		Owner: files.Owner{Module: "teacher", SubjectType: audioSubject, BranchID: &c.BranchID},
		By:    &a.UserID,
		Audit: audit.Entry{Module: audit.ModuleStudents, Description: "Tải file nghe bài tập về nhà lớp " + c.Name},
	})
	if err != nil {
		return nil, err
	}
	return &UploadedAudio{ID: f.ID, Name: f.Name, URL: f.URL}, nil
}

// readAudio: file nghe mở được bởi người giao bài / giáo viên của lớp / người làm thay; học viên qua cổng học viên.
func (s *Service) readAudio(ctx context.Context, a *iam.Access, f files.File) (string, error) {
	if f.SubjectID == nil {
		if f.UploadedBy != nil && *f.UploadedBy == a.UserID {
			return "", nil
		}
		return "", iam.ErrForbidden
	}
	classID, err := s.q.HomeworkAudioOwner(ctx, &f.ID)
	if err == nil {
		if c, err := s.q.GetClass(ctx, classID); err == nil {
			if isAssigned(a.UserID, c, nil) {
				return "", nil
			}
			if ok, err := s.canActOnBehalf(ctx, a, c.ID); err != nil {
				return "", err
			} else if ok {
				return "", nil
			}
		}
	} else if !isNoRows(err) {
		return "", err
	}
	if s.portalReader != nil {
		if ok, err := s.portalReader(ctx, a, f); err != nil || ok {
			return "", err
		}
	}
	return "", iam.ErrForbidden
}
