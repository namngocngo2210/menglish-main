#!/bin/bash
set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# ==============================================================================
# DEPLOY CONFIGURATION
# Cấu hình qua biến môi trường hoặc file .env.deploy (không commit lên git)
# ==============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/.env.deploy" ]; then
    # shellcheck disable=SC1091
    source "$SCRIPT_DIR/.env.deploy"
fi

# SSH Server (Ví dụ: user@your-vps-ip hoặc SSH host alias)
SERVER="${DEPLOY_SERVER:-}"
TARGET="${1:-portal}"

if [ "$TARGET" = "dungthu" ] || [ "$TARGET" = "staging" ]; then
    TARGET_DOMAIN="${STAGING_DOMAIN:-dungthu.example.com}"
    REMOTE_PATH="${STAGING_REMOTE_PATH:-/var/www/dungthu.example.com/public_html}"
else
    TARGET_DOMAIN="${PORTAL_DOMAIN:-portal.example.com}"
    REMOTE_PATH="${PORTAL_REMOTE_PATH:-/var/www/portal.example.com/public_html}"
fi

PHP_BIN="${DEPLOY_PHP_BIN:-php}"
WEB_USER="${DEPLOY_WEB_USER:-www-data:www-data}"
RELOAD_CMD="${DEPLOY_RELOAD_CMD:-touch .htaccess}"
BRANCH="${DEPLOY_BRANCH:-main}"

# Kiểm tra nếu chưa cấu hình server
if [ -z "$SERVER" ]; then
    echo -e "${RED}❌ Lỗi: Chưa cấu hình máy chủ deploy (DEPLOY_SERVER)!${NC}"
    echo -e "${YELLOW}👉 Vui lòng sao chép .env.deploy.example thành .env.deploy và điền thông tin máy chủ VPS:${NC}"
    echo -e "   cp .env.deploy.example .env.deploy\n"
    exit 1
fi

echo -e "${CYAN}==========================================${NC}"
echo -e "${YELLOW}🚀 Deploying MENGLISH Admin to VPS (${TARGET_DOMAIN})${NC}"
echo -e "${CYAN}   Remote Server: ${SERVER}${NC}"
echo -e "${CYAN}   Target Domain: ${TARGET_DOMAIN}${NC}"
echo -e "${CYAN}   Remote Path:   ${REMOTE_PATH}${NC}"
echo -e "${CYAN}   Branch:        ${BRANCH}${NC}"
echo -e "${CYAN}==========================================${NC}"

# 1. Check local uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
    echo -e "\n${YELLOW}[1/5] Git commit local changes...${NC}"
    git add -A
    read -p "Nhập commit message (để trống để dùng auto-msg): " MSG
    if [ -z "$MSG" ]; then
        MSG="deploy: auto update $(date '+%Y-%m-%d %H:%M:%S')"
    fi
    git commit -m "$MSG"
else
    echo -e "\n${GREEN}[1/5] Working tree clean, no local changes to commit.${NC}"
fi

# 2. Build assets locally
echo -e "\n${YELLOW}[2/5] Building assets locally (Vite)...${NC}"
npm run build
if [ -n "$(git status --porcelain public/build/)" ]; then
    git add public/build/
    git commit -m "build: compile production assets $(date '+%Y-%m-%d %H:%M:%S')" || true
fi

# 3. Push to GitHub
echo -e "\n${YELLOW}[3/5] Pushing to GitHub (origin/${BRANCH})...${NC}"
git push origin "$BRANCH"
echo -e "${GREEN}✓ Pushed to GitHub successfully!${NC}"

# 4. Deploy on server
echo -e "\n${YELLOW}[4/5] Pulling and running tasks on VPS...${NC}"
ssh -o BatchMode=yes "$SERVER" bash -c "'
    set -e
    cd $REMOTE_PATH

    echo \"--> Pulling latest code from GitHub...\"
    git pull origin $BRANCH

    echo \"--> Running migrations...\"
    $PHP_BIN artisan migrate --force

    echo \"--> Optimizing cache...\"
    $PHP_BIN artisan optimize:clear
    $PHP_BIN artisan config:cache
    $PHP_BIN artisan route:cache
    $PHP_BIN artisan view:cache

    echo \"--> Setting correct file permissions...\"
    if [ -n \"$WEB_USER\" ]; then
        chown -R $WEB_USER . 2>/dev/null || true
    fi
    chmod -R 775 storage bootstrap/cache

    echo \"--> Reloading PHP workers...\"
    $RELOAD_CMD
'"

# 5. Verification
echo -e "\n${YELLOW}[5/5] Verifying live status...${NC}"
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://${TARGET_DOMAIN}/login || echo "000")
if [ "$HTTP_STATUS" = "200" ] || [ "$HTTP_STATUS" = "302" ]; then
    echo -e "${GREEN}✓ Site is live! HTTP status: $HTTP_STATUS${NC}"
else
    echo -e "${YELLOW}⚠️ Site responded with HTTP: $HTTP_STATUS${NC}"
fi

echo -e "\n${GREEN}==========================================${NC}"
echo -e "${GREEN}🎉 Deploy finished successfully!${NC}"
echo -e "🌐 Website: https://${TARGET_DOMAIN}"
echo -e "${GREEN}==========================================${NC}"
