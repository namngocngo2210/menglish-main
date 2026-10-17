package filestore

import (
	"archive/zip"
	"bytes"
	"io"
	"strings"
	"unicode/utf8"

	"github.com/richardlehane/mscfb"
)

// Nhận loại file theo NỘI DUNG (không tin đuôi / Content-Type trình duyệt gửi), như SafeUploadService +
// MediaManagerService::safeExtension của Laravel. Chỉ nhận các loại liệt kê dưới đây; HTML, SVG, script, file chạy
// được đều không có trong danh sách nên luôn bị từ chối.

// Kind: loại file nhận ra được.
type Kind struct {
	Ext  string // đuôi chuẩn: jpg, png, pdf, docx…
	Mime string
}

// Mime của từng đuôi chuẩn.
var mimeOf = map[string]string{
	"jpg": "image/jpeg", "png": "image/png", "gif": "image/gif", "webp": "image/webp",
	"pdf": "application/pdf", "txt": "text/plain; charset=utf-8",
	"doc": "application/msword", "xls": "application/vnd.ms-excel", "ppt": "application/vnd.ms-powerpoint",
	"docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
	"xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
	"pptx": "application/vnd.openxmlformats-officedocument.presentationml.presentation",
	"mp3":  "audio/mpeg", "wav": "audio/wav", "ogg": "audio/ogg", "aac": "audio/aac", "m4a": "audio/mp4",
	"webm": "video/webm", "mp4": "video/mp4", "mov": "video/quicktime", "m4v": "video/x-m4v", "3gp": "video/3gpp",
}

// MimeOf: Content-Type của đuôi chuẩn (không biết thì application/octet-stream).
func MimeOf(ext string) string {
	if m, ok := mimeOf[ext]; ok {
		return m
	}
	return "application/octet-stream"
}

// InlineExt: loại mở thẳng trên trình duyệt (ảnh, PDF, âm thanh, video); tài liệu văn phòng / txt luôn tải về.
func InlineExt(ext string) bool {
	switch ext {
	case "jpg", "png", "gif", "webp", "pdf", "mp3", "wav", "ogg", "aac", "m4a", "webm", "mp4", "mov", "m4v", "3gp":
		return true
	}
	return false
}

// Detect nhận loại file: head là phần đầu file (tối đa vài KB), ra / size để đọc cấu trúc zip / OLE của tài liệu
// văn phòng. Không nhận ra thì Ext rỗng.
func Detect(head []byte, ra io.ReaderAt, size int64) Kind {
	k := func(ext string) Kind { return Kind{ext, MimeOf(ext)} }
	switch {
	case len(head) < 4:
		return Kind{}
	case bytes.HasPrefix(head, []byte{0xFF, 0xD8, 0xFF}):
		return k("jpg")
	case bytes.HasPrefix(head, []byte("\x89PNG\r\n\x1a\n")):
		return k("png")
	case bytes.HasPrefix(head, []byte("GIF87a")), bytes.HasPrefix(head, []byte("GIF89a")):
		return k("gif")
	case len(head) >= 12 && bytes.HasPrefix(head, []byte("RIFF")) && string(head[8:12]) == "WEBP":
		return k("webp")
	case len(head) >= 12 && bytes.HasPrefix(head, []byte("RIFF")) && string(head[8:12]) == "WAVE":
		return k("wav")
	case bytes.HasPrefix(head, []byte("%PDF-")):
		return k("pdf")
	case bytes.HasPrefix(head, []byte("PK\x03\x04")):
		return k(officeZip(ra, size))
	case bytes.HasPrefix(head, []byte{0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1}):
		return k(officeOLE(ra, size))
	case bytes.HasPrefix(head, []byte("ID3")):
		return k("mp3")
	case bytes.HasPrefix(head, []byte("OggS")):
		return k("ogg")
	case bytes.HasPrefix(head, []byte{0x1A, 0x45, 0xDF, 0xA3}):
		if bytes.Contains(head[:min(len(head), 64)], []byte("webm")) {
			return k("webm")
		}
		return Kind{}
	case len(head) >= 12 && string(head[4:8]) == "ftyp":
		return k(isoBrand(string(head[8:12])))
	case head[0] == 0xFF && head[1]&0xF6 == 0xF0:
		return k("aac") // ADTS
	case head[0] == 0xFF && head[1]&0xE0 == 0xE0 && (head[1]>>1)&3 != 0:
		return k("mp3") // khung MPEG audio không có thẻ ID3
	}
	if plainText(head) {
		return k("txt")
	}
	return Kind{}
}

// isoBrand: mp4 / m4a / mov / 3gp theo brand của hộp ftyp.
func isoBrand(brand string) string {
	switch {
	case brand == "M4A " || brand == "M4B ":
		return "m4a"
	case brand == "qt  ":
		return "mov"
	case brand == "M4V " || brand == "M4VH" || brand == "M4VP":
		return "m4v"
	case strings.HasPrefix(brand, "3gp") || strings.HasPrefix(brand, "3g2"):
		return "3gp"
	}
	return "mp4"
}

// officeZip: docx / xlsx / pptx theo file bên trong; zip thường (không nhận) trả "".
func officeZip(ra io.ReaderAt, size int64) string {
	if ra == nil {
		return ""
	}
	z, err := zip.NewReader(ra, size)
	if err != nil {
		return ""
	}
	var ct bool
	kind := ""
	for _, f := range z.File {
		switch {
		case f.Name == "[Content_Types].xml":
			ct = true
		case strings.HasPrefix(f.Name, "word/document") && strings.HasSuffix(f.Name, ".xml"):
			kind = "docx"
		case f.Name == "xl/workbook.xml":
			kind = "xlsx"
		case f.Name == "ppt/presentation.xml":
			kind = "pptx"
		}
	}
	if !ct {
		return ""
	}
	return kind
}

// officeOLE: doc / xls / ppt (định dạng Office cũ) theo luồng bên trong file OLE.
func officeOLE(ra io.ReaderAt, size int64) (kind string) {
	if ra == nil {
		return ""
	}
	defer func() {
		if recover() != nil {
			kind = ""
		}
	}()
	r, err := mscfb.New(io.NewSectionReader(ra, 0, size))
	if err != nil {
		return ""
	}
	for n := 0; n < 1000; n++ {
		f, err := r.Next()
		if err != nil {
			return ""
		}
		switch f.Name {
		case "WordDocument":
			return "doc"
		case "Workbook", "Book":
			return "xls"
		case "PowerPoint Document":
			return "ppt"
		}
	}
	return ""
}

// plainText: văn bản UTF-8 không có byte 0 và không giống HTML / SVG / script (trình duyệt có thể chạy).
func plainText(head []byte) bool {
	if bytes.IndexByte(head, 0) >= 0 {
		return false
	}
	// Có thể cắt giữa một ký tự nhiều byte ở cuối phần đầu file.
	h := head
	for i := 0; i < 3 && len(h) > 0 && !utf8.Valid(h); i++ {
		h = h[:len(h)-1]
	}
	if !utf8.Valid(h) {
		return false
	}
	lower := bytes.ToLower(head)
	for _, m := range []string{"<html", "<!doctype", "<script", "<svg", "<?php", "<?xml", "<iframe", "<body", "<head"} {
		if bytes.Contains(lower, []byte(m)) {
			return false
		}
	}
	return true
}
