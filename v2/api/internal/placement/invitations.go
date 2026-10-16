package placement

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"time"

	"github.com/google/uuid"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/placement/placementdb"
)

// Link mời làm bài (thay URL theo mã đề + URL ký của Laravel): token ngẫu nhiên 32 byte, DB chỉ giữ SHA-256,
// có hạn dùng và thu hồi được. Ẩn đề thì mọi link của đề ngừng hoạt động.
const (
	// LeadLinkTTLDays: link riêng của khách CRM (Laravel PlacementPortalLinkService::LINK_TTL_DAYS).
	LeadLinkTTLDays = 7
	// PublicLinkTTLDays: link phát hành chung mặc định.
	PublicLinkTTLDays = 30
	maxLinkTTLDays    = 90
	// submitGrace: cộng thêm sau hạn link để thí sinh kịp nộp bài đang làm (cộng cả thời gian làm bài).
	submitGrace = 60 * time.Minute
	// PublicPathPrefix: đường dẫn trang làm bài trên web.
	PublicPathPrefix = "/lam-bai/"
)

func newToken() (string, []byte) {
	b := make([]byte, 32)
	_, _ = rand.Read(b)
	tok := base64.RawURLEncoding.EncodeToString(b)
	return tok, hashToken(tok)
}

func hashToken(tok string) []byte {
	h := sha256.Sum256([]byte(tok))
	return h[:]
}

// InvitationView: link mời trên màn đề.
type InvitationView struct {
	ID              uuid.UUID  `json:"id"`
	TestID          uuid.UUID  `json:"test_id"`
	Kind            string     `json:"kind"`
	LeadID          *uuid.UUID `json:"lead_id"`
	LeadName        string     `json:"lead_name,omitempty"`
	CandidateName   string     `json:"candidate_name"`
	CandidatePhone  string     `json:"candidate_phone"`
	Note            string     `json:"note"`
	ExpiresAt       time.Time  `json:"expires_at"`
	MaxSubmissions  *int32     `json:"max_submissions"`
	SubmissionCount int32      `json:"submission_count"`
	OpenedAt        *time.Time `json:"opened_at"`
	RevokedAt       *time.Time `json:"revoked_at"`
	CreatedByName   string     `json:"created_by_name,omitempty"`
	CreatedAt       time.Time  `json:"created_at"`
	// active | expired | revoked | used_up
	Status string `json:"status"`
}

func invitationStatus(i placementdb.PlacementInvitation, now time.Time) string {
	switch {
	case i.RevokedAt != nil:
		return "revoked"
	case !now.Before(i.ExpiresAt):
		return "expired"
	case i.MaxSubmissions != nil && i.SubmissionCount >= *i.MaxSubmissions:
		return "used_up"
	}
	return "active"
}

func (s *Service) toInvitationView(i placementdb.PlacementInvitation, createdBy *string) InvitationView {
	return InvitationView{
		ID: i.ID, TestID: i.TestID, Kind: i.Kind, LeadID: i.LeadID, CandidateName: deref(i.CandidateName),
		CandidatePhone: deref(i.CandidatePhone), Note: deref(i.Note), ExpiresAt: i.ExpiresAt, MaxSubmissions: i.MaxSubmissions,
		SubmissionCount: i.SubmissionCount, OpenedAt: i.OpenedAt, RevokedAt: i.RevokedAt, CreatedByName: deref(createdBy),
		CreatedAt: i.CreatedAt, Status: invitationStatus(i, s.now()),
	}
}

// InvitationInput: tạo link mời. kind "lead" cần lead_id (khách trong phạm vi CRM của người tạo).
type InvitationInput struct {
	Kind           string     `json:"kind"`
	LeadID         *uuid.UUID `json:"lead_id"`
	ExpiresInDays  *int       `json:"expires_in_days"`
	MaxSubmissions *int       `json:"max_submissions"`
	Note           string     `json:"note"`
}

// IssuedInvitation: link vừa tạo; Token chỉ trả về một lần.
type IssuedInvitation struct {
	Invitation InvitationView `json:"invitation"`
	Token      string         `json:"token"`
	Path       string         `json:"path"`
}

// CreateInvitation tạo link làm bài cho đề (đề phải đang hoạt động).
func (s *Service) CreateInvitation(ctx context.Context, a *iam.Access, testID uuid.UUID, in InvitationInput) (*IssuedInvitation, error) {
	t, err := s.q.GetTest(ctx, testID)
	if err != nil {
		return nil, notFound(err, errTestNotFound)
	}
	if !t.IsActive {
		return nil, errInvalid("Đề đang ẩn — bấm Kích hoạt trước khi tạo link làm bài.")
	}
	if in.Kind == "" {
		in.Kind = "public"
	}
	days := PublicLinkTTLDays
	if in.Kind == "lead" {
		days = LeadLinkTTLDays
	}
	switch {
	case in.Kind != "public" && in.Kind != "lead":
		return nil, errInvalid("Loại link không hợp lệ.")
	case in.ExpiresInDays != nil && (*in.ExpiresInDays < 1 || *in.ExpiresInDays > maxLinkTTLDays):
		return nil, errInvalid(fmt.Sprintf("Hạn dùng link từ 1 đến %d ngày.", maxLinkTTLDays))
	case in.MaxSubmissions != nil && (*in.MaxSubmissions < 1 || *in.MaxSubmissions > 1000):
		return nil, errInvalid("Số lần nộp tối đa từ 1 đến 1000.")
	case runeLen(in.Note) > 500:
		return nil, errInvalid("Ghi chú không được vượt quá 500 ký tự.")
	}
	if in.ExpiresInDays != nil {
		days = *in.ExpiresInDays
	}
	p := placementdb.CreateInvitationParams{
		TestID: testID, Kind: in.Kind, Note: strOrNil(in.Note), ExpiresAt: s.now().Add(time.Duration(days) * 24 * time.Hour),
		CreatedBy: &a.UserID,
	}
	if in.MaxSubmissions != nil {
		p.MaxSubmissions = ptr(int32(*in.MaxSubmissions))
	}
	if in.Kind == "lead" {
		if !a.CanAny("entrance_test.send", "placement_test.send") {
			return nil, iam.ErrForbidden
		}
		if in.LeadID == nil {
			return nil, errInvalid("Vui lòng chọn khách hàng.")
		}
		lead, err := s.leads.Get(ctx, *in.LeadID)
		if err != nil {
			return nil, err
		}
		ok, err := s.leadVisible(ctx, a, *in.LeadID)
		if err != nil {
			return nil, err
		}
		if lead == nil || !ok {
			return nil, errInvalid("Không tìm thấy khách hàng trong phạm vi bạn phụ trách.")
		}
		p.LeadID = &lead.ID
		p.CandidateName, p.CandidatePhone, p.CandidateEmail = strOrNil(truncate(lead.Name, 255)), strOrNil(truncate(lead.Phone, 20)), strOrNil(truncate(lead.Email, 255))
	} else if !a.CanAny("placement_test.distribute", "placement_test.send") {
		return nil, iam.ErrForbidden
	}
	tok, hash := newToken()
	p.TokenHash = hash
	inv, err := s.q.CreateInvitation(ctx, p)
	if err != nil {
		return nil, err
	}
	s.log.Info("placement: tạo link làm bài", "invitation", inv.ID, "test", testID, "kind", inv.Kind, "by", a.UserID)
	view := s.toInvitationView(inv, nil)
	return &IssuedInvitation{Invitation: view, Token: tok, Path: PublicPathPrefix + tok}, nil
}

// Distribute: phát hành đề — kích hoạt đề và tạo link làm bài chung để gửi cho khách (dán vào CRM / Zalo).
func (s *Service) Distribute(ctx context.Context, a *iam.Access, testID uuid.UUID) (*IssuedInvitation, string, error) {
	t, err := s.q.SetTestActive(ctx, placementdb.SetTestActiveParams{ID: testID, IsActive: true})
	if err != nil {
		return nil, "", notFound(err, errTestNotFound)
	}
	issued, err := s.CreateInvitation(ctx, a, testID, InvitationInput{Kind: "public"})
	if err != nil {
		return nil, "", err
	}
	// TODO(notify): AdminNotification::notifyUser(người phát hành, 'info', "Phát hành đề test đầu vào: {title}",
	// "Đề [{code}] {title} đã được phát hành. Link làm bài: {link}")
	return issued, fmt.Sprintf("Đã phát hành đề [%s] %s.", t.Code, t.Title), nil
}

// ListInvitations: link mời của đề (mới nhất trước).
func (s *Service) ListInvitations(ctx context.Context, testID uuid.UUID) ([]InvitationView, error) {
	if _, err := s.q.GetTest(ctx, testID); err != nil {
		return nil, notFound(err, errTestNotFound)
	}
	rows, err := s.q.ListInvitations(ctx, testID)
	if err != nil {
		return nil, err
	}
	var leadIDs []uuid.UUID
	for _, r := range rows {
		if r.LeadID != nil {
			leadIDs = append(leadIDs, *r.LeadID)
		}
	}
	leads := map[uuid.UUID]Lead{}
	if len(leadIDs) > 0 {
		if leads, err = s.leads.Lookup(ctx, leadIDs); err != nil {
			return nil, err
		}
	}
	out := make([]InvitationView, 0, len(rows))
	for _, r := range rows {
		v := s.toInvitationView(placementdb.PlacementInvitation{
			ID: r.ID, TestID: r.TestID, Kind: r.Kind, LeadID: r.LeadID, CandidateName: r.CandidateName,
			CandidatePhone: r.CandidatePhone, Note: r.Note, ExpiresAt: r.ExpiresAt, MaxSubmissions: r.MaxSubmissions,
			SubmissionCount: r.SubmissionCount, OpenedAt: r.OpenedAt, RevokedAt: r.RevokedAt, CreatedAt: r.CreatedAt,
		}, r.CreatedByName)
		if r.LeadID != nil {
			v.LeadName = leads[*r.LeadID].Name
		}
		out = append(out, v)
	}
	return out, nil
}

// RevokeInvitation: thu hồi link (link ngừng hoạt động ngay).
func (s *Service) RevokeInvitation(ctx context.Context, a *iam.Access, id uuid.UUID) (*InvitationView, error) {
	inv, err := s.q.GetInvitation(ctx, id)
	if err != nil {
		return nil, notFound(err, errNotFound)
	}
	if inv.RevokedAt != nil {
		return nil, conflict("already_revoked", "Link này đã bị thu hồi.")
	}
	if inv.Kind == "lead" && !a.CanAny("entrance_test.send", "placement_test.send") ||
		inv.Kind == "public" && !a.CanAny("placement_test.distribute", "placement_test.send") {
		return nil, iam.ErrForbidden
	}
	inv, err = s.q.RevokeInvitation(ctx, placementdb.RevokeInvitationParams{ID: id, RevokedBy: &a.UserID})
	if err != nil {
		return nil, notFound(err, conflict("already_revoked", "Link này đã bị thu hồi."))
	}
	s.log.Info("placement: thu hồi link làm bài", "invitation", id, "by", a.UserID)
	v := s.toInvitationView(inv, nil)
	return &v, nil
}

// LeadLinkFor: link riêng còn hạn mới nhất của khách cho đề — CRM dùng để gửi kèm xác nhận lịch test
// (Laravel AppointmentConfirmation). Không có thì tạo mới.
func (s *Service) LeadLinkFor(ctx context.Context, a *iam.Access, testID, leadID uuid.UUID) (*IssuedInvitation, error) {
	return s.CreateInvitation(ctx, a, testID, InvitationInput{Kind: "lead", LeadID: &leadID})
}
