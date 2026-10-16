# Deploy bằng Docker (production + staging trên cùng máy chủ)

```
Internet ─▶ Caddy (80/443, tự cấp HTTPS) ─┬─▶ menglish-production: app + scheduler + db   portal.meducation.vn
           docker/proxy/                  └─▶ menglish-staging:    app + scheduler + db   dungthu.meducation.vn
```

- `docker-compose.yml`: dùng chung cho 2 môi trường; mỗi môi trường là một project Docker riêng (`menglish-<env>`), có DB, volume (`db`, `storage`, `uploads`) và scheduler riêng.
- `.env.production`, `.env.staging`: cấu hình từng môi trường (mẫu: `.env.docker.example`, không commit).
- `docker/proxy/`: Caddy reverse proxy; domain đặt trong `docker/proxy/.env` (mẫu `.env.example`).
- `docker/menglish.sh <production|staging> <lệnh>`: mọi thao tác vận hành.
- App không mở cổng ra ngoài; DB chỉ mở `127.0.0.1:3306` (production) / `127.0.0.1:3307` (staging).

## Lệnh thường dùng

```bash
docker/menglish.sh production deploy              # build image từ code hiện tại, khởi động, migrate, cache
docker/menglish.sh staging deploy
docker/menglish.sh production ps
docker/menglish.sh production logs -f app scheduler
docker/menglish.sh production artisan tinker
docker/menglish.sh production backup-db           # → backups/production-<thời gian>.sql.gz
docker/menglish.sh production backup              # DB + file upload (public/uploads, storage/app), xóa bản > 14 ngày
```

`deploy <ref>` build image từ đúng commit / nhánh (qua `git archive`, không đổi thư mục làm việc), vd. `docker/menglish.sh staging deploy origin/feature-x`; `version` cho biết commit đang chạy. Không truyền ref thì build từ thư mục hiện tại.

`deploy` giữ nguyên trạng thái scheduler: đang dừng thì vẫn dừng.

## Deploy tự động (GitHub Actions)

`.github/workflows/deploy-server.yml`:
- Push / merge vào `main` → chạy test → deploy **production**.
- Actions → *Deploy server* → *Run workflow*: chọn **staging** (mọi nhánh) hoặc production (chỉ `main`).

CI SSH vào máy chủ bằng khóa riêng (`~/.ssh/ci/deploy_key` trên máy chủ). Trong `~/.ssh/authorized_keys` khóa này bị ép chạy `docker/ci-deploy.sh` (forced command), chỉ nhận `"<production|staging> <sha>"`; production chỉ nhận commit đã nằm trên `main`. Mỗi lúc chỉ một deploy (flock).

Cấu hình trên GitHub (Settings → Secrets and variables → Actions):

| Loại | Tên | Giá trị |
|---|---|---|
| Secret | `DEPLOY_SSH_KEY` | nội dung `~/.ssh/ci/deploy_key` trên máy chủ |
| Secret | `DEPLOY_KNOWN_HOSTS` | `ssh-keyscan -p 24700 103.72.96.61` |
| Variable | `DEPLOY_HOST` | `103.72.96.61` |
| Variable | `DEPLOY_PORT` | `24700` |
| Variable | `DEPLOY_USER` | `menglish` |

Thu hồi quyền deploy của CI: xóa dòng `github-actions-deploy@menglish` trong `~/.ssh/authorized_keys`.

## Sao lưu

Cron của user `menglish` trên máy chủ (`crontab -l`): 02:00 production, 02:30 staging chạy `docker/menglish.sh <env> backup` → `backups/` (quyền 700), log ở `backups/cron.log`. Giữ 14 ngày (`BACKUP_KEEP_DAYS`).

Bản sao lưu nằm cùng máy chủ: hỏng ổ / mất máy là mất cả bản sao lưu — nên chép định kỳ ra nơi khác (Google Drive, S3...).

Khôi phục DB: `docker/menglish.sh production import-db backups/production-<thời gian>.sql.gz`. Khôi phục file: `docker/menglish.sh production exec -T app tar xzf - -C /var/www/html < backups/production-<thời gian>-files.tar.gz`.

## Chuyển từ hosting sang

1. **APP_KEY**: chép `APP_KEY` từ `.env` trên hosting vào `.env.<env>` tương ứng (dữ liệu mã hóa phụ thuộc key).
2. **Import DB** (export từ phpMyAdmin / mysqldump): DB hiện tại bị xóa và thay bằng file, sau đó tự migrate:
   ```bash
   docker/menglish.sh production import-db dump.sql      # hoặc dump.sql.gz
   ```
3. **File upload**: chép `public_html/uploads/` và `<APP_DIR>/storage/app/` từ hosting:
   ```bash
   docker/menglish.sh production cp ./hosting-uploads/. app:/var/www/html/public/uploads/
   docker/menglish.sh production cp ./hosting-storage-app/. app:/var/www/html/storage/app/
   docker/menglish.sh production exec -u root app chown -R www-data:www-data /var/www/html/public/uploads /var/www/html/storage
   ```
4. **DNS (Cloudflare)**: bản ghi A `portal` / `dungthu` → IP máy chủ. SSL/TLS mode **Full (strict)**. Caddy tự lấy chứng chỉ Let's Encrypt khi DNS đã trỏ về (xem `docker compose -f docker/proxy/docker-compose.yml logs -f`).
5. **Tắt cron `schedule:run` trên hosting**, rồi bật scheduler ở đây: `docker/menglish.sh production start scheduler`.

**Chỉ một nơi chạy scheduler cho mỗi môi trường.** `import-db` tự dừng scheduler; `deploy` bật lại — trong lúc hosting còn chạy cron, sau `deploy` hãy `docker/menglish.sh <env> stop scheduler`, nếu không nhắc nợ / SLA / Zalo sẽ gửi trùng.
