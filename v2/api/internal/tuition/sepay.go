package tuition

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"math"
	"net/url"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/namngocngo2210/menglish-main/v2/api/internal/audit"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/iam"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/notify/mail"
	"github.com/namngocngo2210/menglish-main/v2/api/internal/tuition/tuitiondb"
)

// Webhook SePay tự gạch nợ học phí (SepayWebhookController::handleWebhook): xác thực HMAC-SHA256 / API key, ghi giao
// dịch một lần theo mã SePay, chỉ gạch nợ tiền vào đúng tài khoản ngân hàng đã cấu hình, khớp học viên theo nội dung
// chuyển khoản (nội dung CK gợi ý của khoản học phí → mã học viên → mã khách CRM), chống ghi nhận 2 lần với phiếu tay,
// lập phiếu thu đã duyệt + cấp HĐĐT, báo người đối soát. Khác Laravel: ghi giao dịch và gạch nợ trong cùng một giao
// dịch DB nên lỗi giữa chừng không để lại giao dịch "pending" mồ côi — SePay gửi lại thì xử lý lại từ đầu.

const (
	sepayDefaultURL = "https://dungthu.meducation.vn/hook/sepay-gateway/v1/add-payment"
	// sepayDuplicateWindowDays: ± ngày tìm phiếu chuyển khoản tay cùng số tiền (tuition.sepay_duplicate_window_days).
	sepayDuplicateWindowDays = 3
	secretAD                 = "finance.payment_providers.secret_key"
	apiKeyAD                 = "finance.payment_providers.api_key"
)

// SepaySettings: cờ bật webhook và khóa HMAC ban đầu từ env (SEPAY_WEBHOOK_ENABLED, SEPAY_WEBHOOK_SECRET).
type SepaySettings struct {
	WebhookEnabled bool
	WebhookSecret  string
}

// WithSepay gắn cấu hình env của webhook SePay (cmd/menglish).
func (s *Service) WithSepay(x SepaySettings) *Service { s.sepay = x; return s }

// WebhookResult: phản hồi JSON cho SePay (giữ nguyên dạng {success, message, ...} của Laravel).
type WebhookResult struct {
	Status int
	Body   map[string]any
}

func hookReply(status int, success bool, msg string, extra ...any) WebhookResult {
	b := map[string]any{"success": success, "message": msg}
	for i := 0; i+1 < len(extra); i += 2 {
		b[extra[i].(string)] = extra[i+1]
	}
	return WebhookResult{Status: status, Body: b}
}

// sepayConfig: cấu hình đang dùng; chưa có thì tạo (khóa HMAC từ env, không có thì khóa ngẫu nhiên và để tắt).
func (s *Service) sepayConfig(ctx context.Context) (tuitiondb.FinancePaymentProvider, error) {
	c, err := s.q.GetPaymentProvider(ctx)
	if err == nil || !isNoRows(err) {
		return c, err
	}
	secret := s.sepay.WebhookSecret
	active := secret != ""
	if secret == "" {
		b := make([]byte, 32)
		_, _ = rand.Read(b)
		secret = hex.EncodeToString(b)
	}
	if err := s.q.InsertPaymentProvider(ctx, tuitiondb.InsertPaymentProviderParams{
		WebhookUrl: ptr(sepayDefaultURL), SecretKeyEnc: s.box.Seal([]byte(secret), []byte(secretAD)), IsActive: active,
	}); err != nil {
		return c, err
	}
	return s.q.GetPaymentProvider(ctx)
}

func (s *Service) openSecret(enc []byte, ad string) string {
	if len(enc) == 0 || s.box == nil {
		return ""
	}
	b, err := s.box.Open(enc, []byte(ad))
	if err != nil {
		s.log.Error("sepay: không giải mã được khóa", "err", err)
		return ""
	}
	return string(b)
}

// WebhookRequest: phần request cần cho xác thực và đọc dữ liệu.
type WebhookRequest struct {
	Body        []byte
	ContentType string
	Header      func(string) string
}

// authenticate: HMAC-SHA256 (X-SePay-Signature / X-Signature, chấp nhận tiền tố "sha256=") hoặc API key (X-API-Key /
// Authorization: Bearer|Apikey). Phương thức khác không bao giờ được bỏ qua xác thực.
func (s *Service) authenticate(c tuitiondb.FinancePaymentProvider, req WebhookRequest) *WebhookResult {
	switch c.AuthMethod {
	case "hmac_sha256":
		secret := s.openSecret(c.SecretKeyEnc, secretAD)
		if secret == "" {
			s.log.Error("sepay: HMAC bật nhưng chưa có khóa bí mật")
			r := hookReply(503, false, "Webhook authentication is not configured.")
			return &r
		}
		sig := strings.TrimSpace(firstNonEmpty(req.Header("X-SePay-Signature"), req.Header("X-Signature")))
		if sig == "" {
			r := hookReply(401, false, "Thiếu chữ ký xác thực webhook.")
			return &r
		}
		if len(sig) > 7 && strings.EqualFold(sig[:7], "sha256=") {
			sig = sig[7:]
		}
		mac := hmac.New(sha256.New, []byte(secret))
		mac.Write(req.Body)
		if !hmac.Equal([]byte(hex.EncodeToString(mac.Sum(nil))), []byte(sig)) {
			s.log.Warn("sepay: chữ ký HMAC không hợp lệ")
			r := hookReply(401, false, "Chữ ký xác thực HMAC-SHA256 không hợp lệ!")
			return &r
		}
	case "api_key":
		expected := s.openSecret(c.ApiKeyEnc, apiKeyAD)
		got := req.Header("X-API-Key")
		if got == "" {
			got = regexp.MustCompile(`(?i)\A(?:Bearer|Apikey)\s+`).ReplaceAllString(strings.TrimSpace(req.Header("Authorization")), "")
		}
		if expected == "" || got == "" || subtle.ConstantTimeCompare([]byte(expected), []byte(got)) != 1 {
			r := hookReply(401, false, "API key không hợp lệ.")
			return &r
		}
	default:
		s.log.Error("sepay: phương thức xác thực không được hỗ trợ", "auth_method", c.AuthMethod)
		r := hookReply(503, false, "Webhook authentication is not configured.")
		return &r
	}
	return nil
}

func firstNonEmpty(v ...string) string {
	for _, x := range v {
		if strings.TrimSpace(x) != "" {
			return x
		}
	}
	return ""
}

// payload: dữ liệu webhook (JSON, không có thì form), chấp nhận cả tên trường camelCase lẫn snake_case.
type payload map[string]any

func parsePayload(req WebhookRequest) payload {
	p := payload{}
	d := json.NewDecoder(bytes.NewReader(req.Body))
	d.UseNumber()
	if err := d.Decode(&p); err == nil && len(p) > 0 {
		return p
	}
	if vals, err := url.ParseQuery(string(req.Body)); err == nil {
		for k, v := range vals {
			if len(v) > 0 {
				p[k] = v[0]
			}
		}
	}
	return p
}

func (p payload) str(keys ...string) *string {
	for _, k := range keys {
		switch v := p[k].(type) {
		case string:
			return &v
		case json.Number:
			s := v.String()
			return &s
		case float64:
			s := strconv.FormatFloat(v, 'f', -1, 64)
			return &s
		case bool:
			s := strconv.FormatBool(v)
			return &s
		}
	}
	return nil
}

func (p payload) amount(keys ...string) float64 {
	if v := p.str(keys...); v != nil {
		f, _ := strconv.ParseFloat(strings.TrimSpace(*v), 64)
		return f
	}
	return 0
}

// parseTxnDate: "2026-10-17 14:02:37" (giờ Việt Nam) hoặc RFC 3339; không đọc được thì lấy giờ hiện tại.
func (s *Service) parseTxnDate(v *string) time.Time {
	if v != nil && strings.TrimSpace(*v) != "" {
		t := strings.TrimSpace(*v)
		for _, layout := range []string{time.DateTime, "2006-01-02T15:04:05", time.DateOnly} {
			if x, err := time.ParseInLocation(layout, t, vnZone); err == nil {
				return x
			}
		}
		if x, err := time.Parse(time.RFC3339, t); err == nil {
			return x
		}
	}
	return s.now()
}

var (
	reHVNumeric = regexp.MustCompile(`(?i)^HV-?\d{5,}`)
	reHVULID    = regexp.MustCompile(`(?i)^HV-?[0-9A-HJKMNP-TV-Z]{26}`)
	reHS        = regexp.MustCompile(`(?i)^HS\d+`)
	reHVAny     = regexp.MustCompile(`(?i)^HV-?[A-Z0-9]{6,}`)
	reLeadCode  = regexp.MustCompile(`(?i)(KH-?[A-Z0-9]{6,}|CRM-?[A-Z0-9]{6,})`)
	reNonAlnum  = regexp.MustCompile(`[^A-Za-z0-9]`)
)

func isAlnum(b byte) bool {
	return b >= '0' && b <= '9' || b >= 'a' && b <= 'z' || b >= 'A' && b <= 'Z'
}
func isWord(b byte) bool { return isAlnum(b) || b == '_' }

// studentCodeCandidates: ứng viên mã học viên trong nội dung CK theo thứ tự ưu tiên — HV-NNNNN (số) → HV-<ULID> (kể cả
// khi dính liền chữ phía sau) → HS<số> → HV-xxxxxx bất kỳ; lấy cả vị trí chồng lấn (tên viết liền có thể chứa "HV").
func studentCodeCandidates(content string) []string {
	var out []string
	add := func(s string) {
		s = strings.ToUpper(s)
		if !slices.Contains(out, s) {
			out = append(out, s)
		}
	}
	for i := 0; i < len(content); i++ {
		if i > 0 && isWord(content[i-1]) {
			continue
		}
		if m := reHVNumeric.FindString(content[i:]); m != "" && (i+len(m) == len(content) || !isAlnum(content[i+len(m)])) {
			add(m)
		}
	}
	for _, re := range []*regexp.Regexp{reHVULID, reHS, reHVAny} {
		for i := 0; i < len(content); i++ {
			if m := re.FindString(content[i:]); m != "" {
				add(m)
			}
		}
	}
	return out
}

// normalizePhone: chỉ chữ số, 84… → 0….
func normalizePhone(p string) string {
	var b strings.Builder
	for _, r := range p {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	d := b.String()
	if strings.HasPrefix(d, "84") && len(d) >= 10 {
		d = "0" + d[2:]
	}
	return d
}

type sepayMatch struct {
	tuition *uuid.UUID
	student *tuitiondb.StudentByCodeRow
}

// matchPayment: khoản học phí / học viên theo nội dung CK.
func (s *Service) matchPayment(ctx context.Context, q *tuitiondb.Queries, content string) (sepayMatch, error) {
	var m sepayMatch
	if content == "" {
		return m, nil
	}
	clean := strings.ToUpper(reNonAlnum.ReplaceAllString(content, ""))
	if t, err := q.TuitionByMemo(ctx, clean); err == nil {
		m.tuition = &t.ID
		return m, nil
	} else if !isNoRows(err) {
		return m, err
	}
	for _, c := range studentCodeCandidates(content) {
		st, err := q.StudentByCode(ctx, tuitiondb.StudentByCodeParams{Code: c, Compact: strings.ReplaceAll(c, "-", "")})
		if isNoRows(err) {
			continue
		}
		if err != nil {
			return m, err
		}
		m.student = &st
		if id, err := q.StudentPayTuition(ctx, tuitiondb.StudentPayTuitionParams{StudentID: st.ID}); err == nil {
			m.tuition = &id
		} else if !isNoRows(err) {
			return m, err
		}
		return m, nil
	}
	if code := reLeadCode.FindString(content); code != "" {
		code = strings.ToUpper(code)
		lead, err := q.LeadByCode(ctx, tuitiondb.LeadByCodeParams{Code: code, Compact: strings.ReplaceAll(code, "-", "")})
		if isNoRows(err) {
			return m, nil
		}
		if err != nil {
			return m, err
		}
		var st tuitiondb.StudentByCodeRow
		if lead.ConvertedStudentID != nil {
			x, err := q.StudentBrief(ctx, *lead.ConvertedStudentID)
			if err != nil && !isNoRows(err) {
				return m, err
			}
			st = tuitiondb.StudentByCodeRow(x)
		}
		if st.ID == uuid.Nil {
			if p := normalizePhone(lead.Phone); p != "" {
				x, err := q.StudentByPhone(ctx, p)
				if err != nil && !isNoRows(err) {
					return m, err
				}
				st = tuitiondb.StudentByCodeRow(x)
			}
		}
		if st.ID != uuid.Nil {
			m.student = &st
			if id, err := q.StudentPayTuition(ctx, tuitiondb.StudentPayTuitionParams{StudentID: st.ID, DebtOnly: true}); err == nil {
				m.tuition = &id
			} else if !isNoRows(err) {
				return m, err
			}
		}
	}
	return m, nil
}

// HandleWebhook xử lý một webhook SePay; trả phản hồi JSON giữ đúng mã HTTP / thông điệp của Laravel.
func (s *Service) HandleWebhook(ctx context.Context, req WebhookRequest) (WebhookResult, error) {
	if !s.sepay.WebhookEnabled {
		return hookReply(503, false, "SePay webhook hiện đang tắt trên hệ thống."), nil
	}
	cfg, err := s.sepayConfig(ctx)
	if err != nil {
		return WebhookResult{}, err
	}
	if !cfg.IsActive {
		return hookReply(403, false, "SePay Webhook is currently disabled in system settings."), nil
	}
	if r := s.authenticate(cfg, req); r != nil {
		return *r, nil
	}
	p := parsePayload(req)
	sepayID := strings.TrimSpace(textOf(p.str("id", "transaction_id")))
	if sepayID == "" {
		return hookReply(422, false, "Thiếu mã giao dịch SePay."), nil
	}
	if len(sepayID) > 100 {
		return hookReply(422, false, "Mã giao dịch SePay không hợp lệ."), nil
	}
	transferType := strings.ToLower(nonEmpty(textOf(p.str("transferType", "transfer_type")), "in"))
	amountF := p.amount("transferAmount", "transfer_amount", "amount")
	amount := int64(math.Round(amountF))
	content := strings.TrimSpace(textOf(p.str("content", "description")))
	ref := optText(p.str("referenceCode", "reference_code"))
	account := optText(p.str("accountNumber", "account_number"))
	sub := optText(p.str("subAccount", "sub_account"))
	when := s.parseTxnDate(p.str("transactionDate"))
	raw, _ := json.Marshal(p)

	var out WebhookResult
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		bt, err := q.InsertBankTransaction(ctx, tuitiondb.InsertBankTransactionParams{
			ProviderTxnID: sepayID, Gateway: optText(p.str("gateway", "bank")), TransactionDate: &when, AccountNumber: limitPtr(account, 50),
			SubAccount: limitPtr(sub, 100), TransferType: limitText(transferType, 20), TransferAmount: amount,
			Accumulated: int64(math.Round(p.amount("accumulated"))), Content: optText(&content), ReferenceCode: limitPtr(ref, 100), RawPayload: raw,
		})
		if isNoRows(err) {
			existing, err := q.BankTransactionByProviderID(ctx, sepayID)
			if err != nil {
				return err
			}
			out = hookReply(200, true, "Giao dịch SePay đã được tiếp nhận trước đó (Duplicate).", "transaction_id", existing.ID)
			return nil
		}
		if err != nil {
			return err
		}
		set := func(status, msg string, student, tuition, receipt *uuid.UUID) error {
			return q.SetBankTransactionResult(ctx, tuitiondb.SetBankTransactionResultParams{ID: bt.ID, Status: status,
				MatchedStudentID: student, MatchedTuitionID: tuition, MatchedReceiptID: receipt, ResponseMessage: &msg})
		}
		if transferType != "in" || amountF <= 0 {
			out = hookReply(200, true, "Giao dịch tiền ra được ghi nhận nhưng không xử lý công nợ.")
			return set("ignored", "Bỏ qua giao dịch tiền ra hoặc số tiền <= 0", nil, nil, nil)
		}
		// Chỉ gạch nợ tiền vào đúng tài khoản ngân hàng của trung tâm (chống webhook giả / tài khoản lạ).
		ok, err := s.configuredAccount(ctx, q, textOf(account), textOf(sub))
		if err != nil {
			return err
		}
		accLabel := nonEmpty(textOf(account), "(trống)")
		if !ok {
			if err := set("rejected_account", "Tài khoản nhận "+accLabel+" không thuộc tài khoản ngân hàng đã cấu hình; không tự động gạch nợ.", nil, nil, nil); err != nil {
				return err
			}
			if err := s.notifyReconcilers(ctx, tx, nil, "sepay_review", "SePay: Giao dịch vào tài khoản lạ ("+money(amount)+")",
				"Nhận webhook cho tài khoản "+accLabel+" không có trong danh sách tài khoản ngân hàng đã cấu hình. ND: '"+content+
					"'. Giao dịch KHÔNG được gạch nợ tự động, vui lòng kiểm tra.", nil); err != nil {
				return err
			}
			out = hookReply(200, false, "Tài khoản nhận tiền không thuộc cấu hình của hệ thống; giao dịch được lưu để kiểm tra.", "transaction_id", bt.ID)
			return nil
		}
		m, err := s.matchPayment(ctx, q, content)
		if err != nil {
			return err
		}
		if m.tuition == nil {
			if err := set("unmatched", "Không tìm thấy học viên hoặc hợp đồng công nợ tương ứng với nội dung: '"+content+"'", nil, nil, nil); err != nil {
				return err
			}
			if err := s.notifyReconcilers(ctx, tx, nil, "sepay_review", "SePay: Giao dịch cần đối soát ("+money(amount)+")",
				"Nhận "+money(amount)+" vào tài khoản "+textOf(account)+" nhưng chưa tự động khớp học viên. ND: '"+content+
					"'. Vui lòng kiểm tra đối soát thủ công.", nil); err != nil {
				return err
			}
			out = hookReply(200, true, "Giao dịch đã được lưu trữ an toàn nhưng chưa tìm thấy mã học viên để gạch nợ tự động.", "transaction_id", bt.ID)
			return nil
		}
		return s.applySepay(ctx, tx, q, bt, m, sepayID, ref, amount, when, content, set, &out)
	})
	if err != nil {
		return WebhookResult{}, err
	}
	s.log.Info("sepay webhook", "sepay_id", sepayID, "status", out.Status, "message", out.Body["message"])
	return out, nil
}

func limitPtr(s *string, n int) *string {
	if s == nil {
		return nil
	}
	return ptr(limitText(*s, n))
}

func (s *Service) configuredAccount(ctx context.Context, q *tuitiondb.Queries, numbers ...string) (bool, error) {
	var wanted []string
	for _, n := range numbers {
		if v := strings.ToUpper(reNonAlnum.ReplaceAllString(n, "")); v != "" {
			wanted = append(wanted, v)
		}
	}
	if len(wanted) == 0 {
		return false, nil
	}
	have, err := q.ConfiguredAccountNumbers(ctx)
	if err != nil {
		return false, err
	}
	for _, h := range have {
		if h != "" && slices.Contains(wanted, h) {
			return true, nil
		}
	}
	return false, nil
}

// applySepay: gạch nợ khoản học phí đã khớp — khóa công nợ, chống trùng phiếu tay, ghi đúng phần nợ còn lại (phần thừa
// báo đối soát tay), cấp HĐĐT, báo phụ huynh / người đối soát / email xác thực thanh toán.
func (s *Service) applySepay(ctx context.Context, tx pgx.Tx, q *tuitiondb.Queries, bt tuitiondb.FinanceBankTransaction, m sepayMatch,
	sepayID string, ref *string, amount int64, when time.Time, content string,
	set func(status, msg string, student, tuition, receipt *uuid.UUID) error, out *WebhookResult) error {
	t, err := q.LockTuition(ctx, *m.tuition)
	if err != nil {
		return err
	}
	var st tuitiondb.StudentByCodeRow
	if m.student != nil {
		st = *m.student
	} else {
		x, err := q.StudentBrief(ctx, t.StudentID)
		if err != nil {
			return err
		}
		st = tuitiondb.StudentByCodeRow(x)
	}
	name := nonEmpty(st.FullName, "học viên")
	branch := t.BranchID
	if branch == nil {
		branch = st.BranchID
	}
	if t.DebtAmount <= 0 {
		if err := set("overpaid", "Hợp đồng của "+name+" đã thanh toán đủ; khoản "+money(amount)+" không được tự động ghi nhận. ND: '"+content+"'",
			nil, &t.ID, nil); err != nil {
			return err
		}
		if err := s.notifyReconcilers(ctx, tx, branch, "sepay_review", "SePay: Thu thêm khi đã hết nợ ("+money(amount)+")",
			"Học viên "+name+" đã hết công nợ nhưng nhận thêm "+money(amount)+". ND: '"+content+"'. Vui lòng đối soát thủ công (hoàn tiền hoặc thu trước kỳ sau).", nil); err != nil {
			return err
		}
		*out = hookReply(200, true, "Hợp đồng đã thanh toán đủ trước đó; giao dịch được lưu chờ đối soát thủ công.", "transaction_id", bt.ID)
		return nil
	}

	// Đã có phiếu thu tay cho cùng giao dịch → không ghi nhận lần 2.
	refs := []string{}
	for _, x := range []*string{&sepayID, ref} {
		if n := normalizeReference(x); n != "" && !slices.Contains(refs, n) {
			refs = append(refs, n)
		}
	}
	manual, exact := tuitiondb.FinanceReceipt{}, false
	if len(refs) > 0 {
		if manual, err = q.ReceiptByReferences(ctx, refs); err == nil {
			exact = true
		} else if !isNoRows(err) {
			return err
		}
	}
	if !exact {
		day := dateOf(when)
		manual, err = q.ManualReceiptInWindow(ctx, tuitiondb.ManualReceiptInWindowParams{TuitionID: &t.ID, Amount: amount,
			FromDay: day.AddDate(0, 0, -sepayDuplicateWindowDays), ToDay: day.AddDate(0, 0, sepayDuplicateWindowDays)})
		if err != nil && !isNoRows(err) {
			return err
		}
	}
	if manual.ID != uuid.Nil {
		status, msg := "needs_review", "Có phiếu thu tay "+manual.Code+" cùng số tiền, cùng học viên gần ngày giao dịch; chờ kế toán đối soát, chưa gạch nợ tự động."
		var rid *uuid.UUID
		if exact {
			status, msg, rid = "duplicate_manual", "Giao dịch đã được ghi nhận bằng phiếu thu tay "+manual.Code+"; không tạo phiếu lần 2.", &manual.ID
		}
		if err := set(status, msg, &st.ID, &t.ID, rid); err != nil {
			return err
		}
		if err := s.notifyReconcilers(ctx, tx, branch, "sepay_review", "SePay: Giao dịch có thể trùng phiếu thu tay ("+money(amount)+")",
			"Học viên "+name+" đã có phiếu thu tay "+manual.Code+" cho khoản tương ứng. Giao dịch SePay #"+sepayID+" KHÔNG được gạch nợ tự động. ND: '"+content+"'.", nil); err != nil {
			return err
		}
		*out = hookReply(200, true, "Giao dịch đã có phiếu thu tay tương ứng; không ghi nhận 2 lần.", "transaction_id", bt.ID)
		return nil
	}

	applied := min(amount, t.DebtAmount)
	invoice, err := s.consumeInvoiceNumber(ctx, q, branch)
	if err != nil {
		return err
	}
	code, err := s.newReceiptCode(ctx, q)
	if err != nil {
		return err
	}
	now := s.now()
	day := dateOf(when)
	r, err := q.CreateReceipt(ctx, tuitiondb.CreateReceiptParams{
		Code: code, Source: "sepay", InvoiceNumber: &invoice, TuitionID: &t.ID, StudentID: &t.StudentID, Amount: applied, TuitionAmount: applied,
		PaymentMethod: "transfer", TransactionCode: ptr(sepayID), PaymentDate: &day, CollectedItems: []byte("[]"), Status: StatusApproved,
		Notes:      ptr(limitText(fmt.Sprintf("Thanh toán tự động thành công qua SePay Webhook (ID: %s). Nội dung CK: %s", sepayID, content), 1000)),
		ApprovedAt: &now,
	})
	if err != nil {
		return err
	}
	t, err = s.recalculateDebt(ctx, q, t.ID)
	if err != nil {
		return err
	}
	if err := s.syncStock(ctx, tx, q, r, branch, nil); err != nil {
		return err
	}
	if err := s.recordReceipt(ctx, tx, nil, r, st.FullName, "tuition.receipt.sepay", "SePay tự gạch nợ, cấp hóa đơn "+invoice, branch); err != nil {
		return err
	}
	over := amount - applied
	overNote := ""
	if over > 0 {
		overNote = " Khách chuyển thừa " + money(over) + " — cần đối soát thủ công."
	}
	if err := set("matched", fmt.Sprintf("Đã gạch nợ thành công %s cho học viên %s (%s). Xuất HĐĐT số %s.%s", money(applied), st.FullName, st.Code, invoice, overNote),
		&st.ID, &t.ID, &r.ID); err != nil {
		return err
	}
	// Báo phụ huynh đã nhận tiền (Cổng Học viên + email; không có email → người phụ trách gửi Zalo).
	if err := s.confirmPayment(ctx, tx, r); err != nil {
		return err
	}
	if err := s.notifyReconcilers(ctx, tx, branch, "sepay_payment", "SePay: Khớp thanh toán "+money(applied),
		fmt.Sprintf("Học viên %s (%s) đã thanh toán thành công qua SePay. Phiếu thu: %s. HĐĐT: %s.", st.FullName, st.Code, r.Code, invoice),
		map[string]any{"receipt_id": r.ID, "student_id": st.ID}); err != nil {
		return err
	}
	if over > 0 {
		if err := s.notifyReconcilers(ctx, tx, branch, "sepay_review", "SePay: Khách chuyển thừa "+money(over),
			fmt.Sprintf("Khoản chuyển của học viên %s (%s) vượt số tiền còn nợ %s. Phần thừa chưa ghi nhận vào phiếu thu — vui lòng đối soát thủ công. ND: '%s'",
				st.FullName, st.Code, money(over), content), nil); err != nil {
			return err
		}
	}
	if err := s.notifyTransaction(ctx, tx, r, st.FullName); err != nil {
		return err
	}
	if err := s.mailSepayMatched(ctx, tx, branch, st, r, invoice, applied, content); err != nil {
		return err
	}
	*out = hookReply(200, true, "Khớp thanh toán thành công cho học viên "+st.FullName+"!", "receipt_number", r.Code, "invoice_number", invoice,
		"remaining_debt", t.DebtAmount)
	return nil
}

// reconcilers: người quản lý tài khoản ngân hàng (mặc định Admin) + Học vụ / Quản lý cơ sở của chi nhánh hợp đồng.
func reconcilers(ctx context.Context, db tuitiondb.DBTX, branch *uuid.UUID) ([]uuid.UUID, error) {
	ids, err := iam.UserIDsWithPermission(ctx, db, "bank_account.manage", nil)
	if err != nil {
		return nil, err
	}
	if branch != nil {
		for _, role := range []string{iam.RoleAcademicStaff, iam.RoleManager} {
			x, err := iam.UserIDsWithRole(ctx, db, role, branch)
			if err != nil {
				return nil, err
			}
			for _, id := range x {
				if !slices.Contains(ids, id) {
					ids = append(ids, id)
				}
			}
		}
	}
	return ids, nil
}

func (s *Service) notifyReconcilers(ctx context.Context, tx pgx.Tx, branch *uuid.UUID, typ, title, body string, data map[string]any) error {
	ids, err := reconcilers(ctx, tx, branch)
	if err != nil {
		return err
	}
	_, err = notify.Send(ctx, tx, ids, notify.Notification{Type: typ, Title: title, Body: body, Link: "/tuition/sepay", Data: data})
	return err
}

// mailSepayMatched: thư "[SePay Khớp Lệnh] Xuất hóa đơn" cho Admin + Học vụ / Quản lý cơ sở của chi nhánh.
func (s *Service) mailSepayMatched(ctx context.Context, tx pgx.Tx, branch *uuid.UUID, st tuitiondb.StudentByCodeRow, r tuitiondb.FinanceReceipt,
	invoice string, applied int64, content string) error {
	ids, err := iam.AdminIDs(ctx, tx)
	if err != nil {
		return err
	}
	if branch != nil {
		for _, role := range []string{iam.RoleAcademicStaff, iam.RoleManager} {
			x, err := iam.UserIDsWithRole(ctx, tx, role, branch)
			if err != nil {
				return err
			}
			ids = append(ids, x...)
		}
	}
	if len(ids) == 0 {
		return nil
	}
	emails, err := s.q.WithTx(tx).UserEmails(ctx, ids)
	if err != nil {
		return err
	}
	subject := fmt.Sprintf("[SePay Khớp Lệnh] Xuất hóa đơn #%s - Học viên %s", invoice, st.FullName)
	body := "Kính gửi Quản trị viên & Bộ phận Học vụ / Kế toán cơ sở,\n" +
		"Cổng thanh toán tự động SePay vừa ghi nhận và khớp thành công giao dịch học phí:\n" +
		fmt.Sprintf("• Học viên: %s (%s)\n• Số tiền khớp lệnh: %s\n• Mã phiếu thu: %s\n• Mã hóa đơn điện tử: %s\n• Nội dung chuyển khoản: %s\n",
			st.FullName, st.Code, money(applied), r.Code, invoice, content) +
		"Đề nghị Học vụ phụ trách và Kế toán tiến hành kiểm tra xuất hóa đơn và bàn giao học liệu cho học viên."
	for _, to := range emails {
		_, err := mail.Enqueue(ctx, tx, mail.Message{To: to, Subject: subject, Template: mail.TemplateNotice, Kind: "sepay_matched",
			Data:    mail.Notice{Title: "MENGLISH - XÁC THỰC THANH TOÁN SEPAY", Body: body, ActionURL: "/tuition/history?search=" + r.Code, ActionText: "Xem Phiếu Thu & Hóa Đơn"},
			RefType: "tuition_receipt", RefID: r.ID.String()})
		if err != nil {
			s.log.Warn("sepay: không xếp được thư xác thực thanh toán", "to", to, "err", err)
		}
	}
	return nil
}

// ───────────── Giao dịch gần đây / cấu hình ─────────────

var bankTxnStatusLabels = map[string]string{
	"pending": "Chờ xử lý", "ignored": "Bỏ qua", "rejected_account": "Tài khoản lạ", "matched": "Đã khớp",
	"duplicate_manual": "Trùng phiếu tay", "needs_review": "Cần đối soát", "overpaid": "Thu thừa", "unmatched": "Chưa khớp",
	"manual_matched": "Đã khớp (phiếu tay)",
}

type BankTxn struct {
	ID               uuid.UUID  `json:"id"`
	SepayID          string     `json:"sepay_id"`
	Date             string     `json:"date"`
	TransactionDate  *time.Time `json:"transaction_date"`
	Gateway          *string    `json:"gateway"`
	AccountNumber    *string    `json:"account_number"`
	SubAccount       *string    `json:"sub_account"`
	TransferType     string     `json:"transfer_type"`
	Amount           int64      `json:"amount"`
	Accumulated      int64      `json:"accumulated"`
	Content          *string    `json:"content"`
	ReferenceCode    *string    `json:"reference_code"`
	Status           string     `json:"status"`
	StatusLabel      string     `json:"status_label"`
	ResponseMessage  *string    `json:"response_message"`
	MatchedStudentID *uuid.UUID `json:"matched_student_id"`
	MatchedTuitionID *uuid.UUID `json:"matched_tuition_id"`
	MatchedReceiptID *uuid.UUID `json:"matched_receipt_id"`
	CreatedAt        time.Time  `json:"created_at"`
}

func bankTxnOf(t tuitiondb.FinanceBankTransaction) BankTxn {
	at := t.CreatedAt
	if t.TransactionDate != nil {
		at = *t.TransactionDate
	}
	label := bankTxnStatusLabels[t.Status]
	if label == "" {
		label = t.Status
	}
	return BankTxn{ID: t.ID, SepayID: t.ProviderTxnID, Date: at.In(vnZone).Format("02/01/2006 15:04"), TransactionDate: t.TransactionDate,
		Gateway: t.Gateway, AccountNumber: t.AccountNumber, SubAccount: t.SubAccount, TransferType: t.TransferType, Amount: t.TransferAmount,
		Accumulated: t.Accumulated, Content: t.Content, ReferenceCode: t.ReferenceCode, Status: t.Status, StatusLabel: label,
		ResponseMessage: t.ResponseMessage, MatchedStudentID: t.MatchedStudentID, MatchedTuitionID: t.MatchedTuitionID,
		MatchedReceiptID: t.MatchedReceiptID, CreatedAt: t.CreatedAt}
}

// RecentTransactions: n giao dịch SePay gần nhất (getRecentTransactions); webhook tắt → nil, false.
func (s *Service) RecentTransactions(ctx context.Context, n int32) ([]BankTxn, bool, error) {
	if !s.sepay.WebhookEnabled {
		return nil, false, nil
	}
	rows, err := s.q.RecentBankTransactions(ctx, n)
	if err != nil {
		return nil, true, err
	}
	out := make([]BankTxn, 0, len(rows))
	for _, r := range rows {
		out = append(out, bankTxnOf(r))
	}
	return out, true, nil
}

type SepayConfigView struct {
	WebhookName     string `json:"webhook_name"`
	WebhookURL      string `json:"webhook_url"`
	TransactionType string `json:"transaction_type"`
	DataFormat      string `json:"data_format"`
	AuthMethod      string `json:"auth_method"`
	// Khóa không gửi xuống trình duyệt (ai mở được trang là giả được webhook thanh toán): chỉ báo đã đặt.
	HasSecret       bool   `json:"has_secret"`
	HasAPIKey       bool   `json:"has_api_key"`
	IsActive        bool   `json:"is_active"`
	AutoRetry       bool   `json:"auto_retry"`
	CurrentEndpoint string `json:"current_endpoint"`
	SavedHost       string `json:"saved_host"`
	CurrentHost     string `json:"current_host"`
	DifferentHost   bool   `json:"different_host"`
}

type SepayPage struct {
	Enabled      bool             `json:"enabled"`
	Config       *SepayConfigView `json:"config"`
	Transactions []BankTxn        `json:"transactions"`
}

// SepayPage: tab "Webhook SePay" + "Nhật ký giao dịch SePay" (SystemConfigController::bankAccounts).
func (s *Service) SepayPage(ctx context.Context, scheme, host string) (*SepayPage, error) {
	out := &SepayPage{Enabled: s.sepay.WebhookEnabled, Transactions: []BankTxn{}}
	if !out.Enabled {
		return out, nil
	}
	c, err := s.sepayConfig(ctx)
	if err != nil {
		return nil, err
	}
	endpoint := scheme + "://" + host + "/hook/sepay-gateway/v1/add-payment"
	v := &SepayConfigView{WebhookName: c.WebhookName, WebhookURL: textOf(c.WebhookUrl), TransactionType: c.TransactionType, DataFormat: c.DataFormat,
		AuthMethod: c.AuthMethod, HasSecret: len(c.SecretKeyEnc) > 0, HasAPIKey: len(c.ApiKeyEnc) > 0, IsActive: c.IsActive, AutoRetry: c.AutoRetry,
		CurrentEndpoint: endpoint, CurrentHost: hostOnly(host)}
	if u, err := url.Parse(v.WebhookURL); err == nil {
		v.SavedHost = u.Hostname()
	}
	v.DifferentHost = v.SavedHost != "" && v.SavedHost != v.CurrentHost && v.CurrentHost != "127.0.0.1" && v.CurrentHost != "localhost"
	out.Config = v
	if out.Transactions, _, err = s.RecentTransactions(ctx, 15); err != nil {
		return nil, err
	}
	return out, nil
}

func hostOnly(h string) string {
	if u, err := url.Parse("//" + h); err == nil && u.Hostname() != "" {
		return u.Hostname()
	}
	return h
}

// SepayInput: form cấu hình webhook. Khóa để trống = giữ khóa cũ.
type SepayInput struct {
	WebhookName     string  `json:"webhook_name"`
	WebhookURL      string  `json:"webhook_url"`
	TransactionType string  `json:"transaction_type"`
	DataFormat      string  `json:"data_format"`
	AuthMethod      string  `json:"auth_method"`
	SecretKey       *string `json:"secret_key"`
	APIKey          *string `json:"api_key"`
	IsActive        *bool   `json:"is_active"`
	AutoRetry       *bool   `json:"auto_retry"`
}

// UpdateSepayConfig lưu cấu hình kết nối SePay (updateSepayConfig).
func (s *Service) UpdateSepayConfig(ctx context.Context, actor *iam.Access, in SepayInput) (*Saved, error) {
	c, err := s.sepayConfig(ctx)
	if err != nil {
		return nil, err
	}
	in.WebhookName, in.WebhookURL = strings.TrimSpace(in.WebhookName), strings.TrimSpace(in.WebhookURL)
	in.SecretKey, in.APIKey = optText(in.SecretKey), optText(in.APIKey)
	u, uerr := url.Parse(in.WebhookURL)
	switch {
	case in.WebhookName == "":
		return nil, iam.Invalid("Vui lòng nhập tên webhook.")
	case tooLong(&in.WebhookName, 255):
		return nil, iam.Invalid("Trường tên webhook không được vượt quá 255 ký tự.")
	case in.WebhookURL == "":
		return nil, iam.Invalid("Vui lòng nhập URL webhook.")
	case uerr != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "":
		return nil, iam.Invalid("Trường URL webhook không phải là một URL hợp lệ.")
	case tooLong(&in.WebhookURL, 500):
		return nil, iam.Invalid("Trường URL webhook không được vượt quá 500 ký tự.")
	case !slices.Contains([]string{"in", "out", "all"}, in.TransactionType):
		return nil, iam.Invalid("Giá trị đã chọn trong trường loại giao dịch không hợp lệ.")
	case !slices.Contains([]string{"json", "form", "urlencoded"}, in.DataFormat):
		return nil, iam.Invalid("Giá trị đã chọn trong trường định dạng dữ liệu không hợp lệ.")
	case !slices.Contains([]string{"hmac_sha256", "api_key"}, in.AuthMethod):
		return nil, iam.Invalid("Giá trị đã chọn trong trường phương thức xác thực không hợp lệ.")
	case in.SecretKey == nil && len(c.SecretKeyEnc) == 0:
		return nil, iam.Invalid("Vui lòng nhập Secret Key.")
	case tooLong(in.SecretKey, 255):
		return nil, iam.Invalid("Trường Secret Key không được vượt quá 255 ký tự.")
	case tooLong(in.APIKey, 255):
		return nil, iam.Invalid("Trường API Key không được vượt quá 255 ký tự.")
	}
	secret, apiKey := c.SecretKeyEnc, c.ApiKeyEnc
	if in.SecretKey != nil {
		secret = s.box.Seal([]byte(*in.SecretKey), []byte(secretAD))
	}
	if in.APIKey != nil {
		apiKey = s.box.Seal([]byte(*in.APIKey), []byte(apiKeyAD))
	}
	active, retry := true, true
	if in.IsActive != nil {
		active = *in.IsActive
	}
	if in.AutoRetry != nil {
		retry = *in.AutoRetry
	}
	err = s.inTx(ctx, func(tx pgx.Tx, q *tuitiondb.Queries) error {
		x, err := q.UpdatePaymentProvider(ctx, tuitiondb.UpdatePaymentProviderParams{WebhookName: in.WebhookName, WebhookUrl: &in.WebhookURL,
			TransactionType: in.TransactionType, DataFormat: in.DataFormat, AuthMethod: in.AuthMethod, SecretKeyEnc: secret, ApiKeyEnc: apiKey,
			IsActive: active, AutoRetry: retry, UpdatedBy: &actor.UserID})
		if err != nil {
			return err
		}
		// Không ghi khóa vào nhật ký, chỉ ghi đã đổi hay chưa.
		return audit.Record(ctx, tx, audit.Entry{
			ActorID: &actor.UserID, Module: audit.ModuleTuition, Event: audit.EventUpdated, Action: "tuition.sepay_config.update",
			SubjectType: "sepay_config", SubjectID: "sepay", SubjectLabel: x.WebhookName, Description: "Cập nhật cấu hình kết nối SePay Gateway.",
			Properties: map[string]any{"webhook_url": x.WebhookUrl, "transaction_type": x.TransactionType, "data_format": x.DataFormat,
				"auth_method": x.AuthMethod, "is_active": x.IsActive, "auto_retry": x.AutoRetry, "secret_changed": in.SecretKey != nil,
				"api_key_changed": in.APIKey != nil},
		})
	})
	if err != nil {
		return nil, err
	}
	s.log.Info("sepay config updated", "active", active, "auth_method", in.AuthMethod, "by", actor.UserID)
	return &Saved{Status: "saved", Message: "Đã lưu cấu hình kết nối SePay Gateway thành công!"}, nil
}
