#!/usr/bin/env bash
# Build the sandbox CSS from the app's Tailwind tokens (run from repo root after `npm ci`).
set -euo pipefail
cd "$(dirname "$0")"
npx tailwindcss -c tailwind.config.js -i input.css -o assets/mockup.css --minify
