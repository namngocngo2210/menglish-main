// Package geo tìm tọa độ theo địa chỉ cho bản đồ cài đặt chấm công chi nhánh (port App\Support\AddressSearch).
//
// Ví dụ: Search(ctx, "23 ngõ 209 Đội Cấn, Ba Đình") trả các điểm {id, label, lat, lng} và Matched = "Đội Cấn, Ba Đình".
// Nominatim (OpenStreetMap) trước, không thấy hoặc lỗi thì Photon (komoot, cũng dữ liệu OSM, tìm gần đúng tốt hơn).
// Địa chỉ Việt Nam đủ số nhà / ngõ / ngách thường không có trên OSM nên thử lại bỏ dần phần đó (Matched = câu đã khớp).
// Gọi từ máy chủ vì CSP của trình duyệt chỉ cho connect-src 'self'; gửi User-Agent riêng theo chính sách Nominatim;
// kết quả lưu Redis 30 ngày. Mọi dịch vụ đều lỗi → ErrUnavailable (khác với "không thấy" = danh sách rỗng).
package geo

import (
	"context"
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	nominatimURL = "https://nominatim.openstreetmap.org/search"
	photonURL    = "https://photon.komoot.io/api/"
	vietnamBBox  = "102.1,8.2,109.6,23.4" // khung Việt Nam cho Photon: minLon, minLat, maxLon, maxLat
	limit        = 5
	cacheTTL     = 30 * 24 * time.Hour
)

var ErrUnavailable = errors.New("geo: không kết nối được dịch vụ tìm địa chỉ")

type Place struct {
	ID    string  `json:"id"`
	Label string  `json:"label"`
	Lat   float64 `json:"lat"`
	Lng   float64 `json:"lng"`
}

type Result struct {
	Results []Place `json:"results"`
	Matched *string `json:"matched"`
}

type Searcher struct {
	rdb       *redis.Client
	log       *slog.Logger
	client    *http.Client
	userAgent string
	referer   string
	// URL dịch vụ, thay được trong test.
	NominatimURL, PhotonURL string
}

// NewSearcher: publicURL là địa chỉ hệ thống (vd. https://beta.meducation.vn), gửi kèm User-Agent / Referer.
func NewSearcher(rdb *redis.Client, log *slog.Logger, publicURL string) *Searcher {
	ua := "menglish/1.0"
	if publicURL != "" {
		ua += " (+" + publicURL + ")"
	}
	return &Searcher{
		rdb: rdb, log: log, client: &http.Client{Timeout: 8 * time.Second},
		userAgent: ua, referer: publicURL, NominatimURL: nominatimURL, PhotonURL: photonURL,
	}
}

var spaces = regexp.MustCompile(`\s+`)

func (s *Searcher) Search(ctx context.Context, query string) (Result, error) {
	query = strings.TrimSpace(spaces.ReplaceAllString(query, " "))
	if query == "" {
		return Result{Results: []Place{}}, nil
	}
	sum := md5.Sum([]byte(strings.ToLower(query)))
	key := "address-search:" + hex.EncodeToString(sum[:])
	if data, err := s.rdb.Get(ctx, key).Bytes(); err == nil {
		var cached Result
		if json.Unmarshal(data, &cached) == nil {
			return cached, nil
		}
	}

	reached := false
	found := Result{Results: []Place{}}
	for i, variant := range Variants(query) {
		results := s.nominatim(ctx, variant, &reached)
		if len(results) == 0 {
			results = s.photon(ctx, variant, &reached)
		}
		if len(results) > 0 {
			found.Results = results
			if i > 0 {
				found.Matched = &variant
			}
			break
		}
	}
	if !reached {
		return Result{}, ErrUnavailable
	}
	if data, err := json.Marshal(found); err == nil {
		if err := s.rdb.Set(ctx, key, data, cacheTTL).Err(); err != nil {
			s.log.Warn("address search: cache write failed", "err", err)
		}
	}
	return found, nil
}

var alleyRe = regexp.MustCompile(`(?i)^(?:(?:số|so|sn|nhà|no\.?)\s*)?\d+[a-z]?(?:/\d+[a-z]?)*\s*|^(?:ngõ|ngo|ngách|ngach|hẻm|hem|kiệt|kiet|tổ|to)\s*\d+[a-z]?(?:/\d+[a-z]?)*\s*`)

// Variants: câu tìm gốc rồi các bản rút gọn: bỏ số nhà / ngõ / ngách / hẻm / kiệt ở đầu, bỏ dần phần trước dấu phẩy.
//
//	"Số 23 ngõ 209 Đội Cấn, Ba Đình, Hà Nội" → "Đội Cấn, Ba Đình, Hà Nội" → "Ba Đình, Hà Nội"
func Variants(query string) []string {
	variants := []string{query}
	var street []string
	for _, p := range strings.Split(query, ",") {
		if p = strings.TrimSpace(p); p != "" {
			street = append(street, p)
		}
	}
	// Bỏ số nhà / ngõ ở đầu; phần đầu chỉ có số nhà ("Số 12, ngõ 34 …") thì bỏ hẳn và xét tiếp phần sau.
	for len(street) > 0 {
		first := street[0]
		for {
			prev := first
			if loc := alleyRe.FindStringIndex(first); loc != nil {
				first = strings.TrimSpace(first[:loc[0]] + first[loc[1]:])
			}
			if first == prev || first == "" {
				break
			}
		}
		if first != "" {
			street[0] = first
			break
		}
		street = street[1:]
	}
	if len(street) > 0 {
		variants = append(variants, strings.Join(street, ", "))
	}
	// Còn ≥ 3 phần (đường, phường, quận…) thì bỏ phần đầu thêm 1 lần.
	if len(street) >= 3 {
		variants = append(variants, strings.Join(street[1:], ", "))
	}
	out := variants[:0]
	seen := map[string]bool{}
	for _, v := range variants {
		if !seen[v] {
			seen[v] = true
			out = append(out, v)
		}
	}
	return out
}

func (s *Searcher) nominatim(ctx context.Context, q string, reached *bool) []Place {
	var rows []struct {
		PlaceID     json.Number `json:"place_id"`
		Lat         string      `json:"lat"`
		Lon         string      `json:"lon"`
		DisplayName string      `json:"display_name"`
	}
	params := url.Values{"q": {q}, "format": {"jsonv2"}, "limit": {strconv.Itoa(limit)}, "countrycodes": {"vn"}, "accept-language": {"vi"}}
	if !s.get(ctx, s.NominatimURL, params, &rows) {
		return nil
	}
	*reached = true
	out := []Place{}
	for _, r := range rows {
		lat, err1 := strconv.ParseFloat(r.Lat, 64)
		lng, err2 := strconv.ParseFloat(r.Lon, 64)
		if err1 != nil || err2 != nil || r.DisplayName == "" {
			continue
		}
		id := r.PlaceID.String()
		if id == "" {
			id = md5hex(r.DisplayName)
		}
		out = append(out, Place{ID: "n" + id, Label: r.DisplayName, Lat: lat, Lng: lng})
	}
	return out
}

func (s *Searcher) photon(ctx context.Context, q string, reached *bool) []Place {
	var body struct {
		Features []struct {
			Geometry struct {
				Coordinates []float64 `json:"coordinates"`
			} `json:"geometry"`
			Properties map[string]any `json:"properties"`
		} `json:"features"`
	}
	params := url.Values{"q": {q}, "limit": {strconv.Itoa(limit)}, "lang": {"default"}, "bbox": {vietnamBBox}}
	if !s.get(ctx, s.PhotonURL, params, &body) || body.Features == nil {
		return nil
	}
	*reached = true
	out := []Place{}
	for _, f := range body.Features {
		p := f.Properties
		str := func(k string) string {
			switch v := p[k].(type) {
			case string:
				return v
			case float64:
				return strconv.FormatFloat(v, 'f', -1, 64)
			}
			return ""
		}
		if cc := str("countrycode"); cc != "" && !strings.EqualFold(cc, "VN") {
			continue
		}
		if len(f.Geometry.Coordinates) < 2 {
			continue
		}
		var parts []string
		seen := map[string]bool{}
		for _, part := range []string{str("name"), strings.TrimSpace(str("housenumber") + " " + str("street")), str("locality"), str("district"), str("city"), str("county"), str("state"), str("country")} {
			if part != "" && !seen[part] {
				seen[part] = true
				parts = append(parts, part)
			}
		}
		label := strings.Join(parts, ", ")
		if label == "" {
			continue
		}
		id := str("osm_id")
		if id == "" {
			id = md5hex(label)
		}
		out = append(out, Place{ID: "p" + str("osm_type") + id, Label: label, Lat: f.Geometry.Coordinates[1], Lng: f.Geometry.Coordinates[0]})
	}
	return out
}

func (s *Searcher) get(ctx context.Context, base string, params url.Values, dst any) bool {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, base+"?"+params.Encode(), nil)
	if err != nil {
		return false
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", s.userAgent)
	if s.referer != "" {
		req.Header.Set("Referer", s.referer)
	}
	resp, err := s.client.Do(req)
	if err != nil {
		s.log.Warn("address search: service unreachable", "url", base, "err", err)
		return false
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		s.log.Warn("address search: service error", "url", base, "status", resp.StatusCode)
		return false
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(dst); err != nil {
		s.log.Warn("address search: bad response", "url", base, "err", fmt.Sprint(err))
		return false
	}
	return true
}

func md5hex(s string) string {
	sum := md5.Sum([]byte(s))
	return hex.EncodeToString(sum[:])
}
