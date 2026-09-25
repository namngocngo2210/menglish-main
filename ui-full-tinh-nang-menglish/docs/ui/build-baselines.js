#!/usr/bin/env node
/**
 * Sinh ảnh baseline (golden) từ mockup code.html, render bằng bộ token chuẩn.
 *
 * Mục đích: ảnh chuẩn cho visual regression test (tests/Visual), không dùng screen.png
 * vì screen.png của một số màn chụp từ bản lỗi (thiếu token, rơi font serif).
 *
 * Cách dùng:
 *   node docs/ui/build-baselines.js <thư-mục-mockup> <thư-mục-output> [filter]
 *   VD: node docs/ui/build-baselines.js ../ui-full-tinh-nang-menglish tests/Visual/baselines crm-ui-mockup
 *
 * Yêu cầu devDependencies: tailwindcss@3.4 @tailwindcss/forms @tailwindcss/container-queries
 *   playwright-core @fontsource/be-vietnam-pro @fontsource/jetbrains-mono @fontsource/material-symbols-outlined
 * Env: CHROMIUM_PATH (mặc định dùng Chromium của Playwright).
 */
const fs = require('fs');
const path = require('path');
const os = require('os');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright-core');

const [, , MOCKUP_ROOT, OUT_DIR, FILTER = ''] = process.argv;
if (!MOCKUP_ROOT || !OUT_DIR) {
  console.error('Usage: build-baselines.js <mockupRoot> <outDir> [filter]');
  process.exit(1);
}

const VIEWPORT = { width: 1440, height: 900 }; // chuẩn desktop theo DESIGN.md

/**
 * Patch có chủ đích cho markup mockup trước khi render baseline.
 * Mỗi patch phải có lý do và được ghi vào docs/system-design.md mục 15.4.
 */
const PATCHES = {
  // Sidebar dùng token riêng `sidebar` (#111a2b) thay vì `inverse-surface` (token này ở màn khác là màu toast/tooltip #2a303d)
  'crm-ui-mockup/app-shell-layout': [['w-[240px] bg-inverse-surface', 'w-sidebar-width bg-sidebar']],
};
const CANON = require(path.resolve(__dirname, 'tailwind.config.js'));
const WORK = fs.mkdtempSync(path.join(os.tmpdir(), 'mockup-'));
const FONTSOURCE = path.dirname(require.resolve('@fontsource/be-vietnam-pro/package.json', { paths: [process.cwd()] })).replace(/be-vietnam-pro$/, '');

const walk = (d) =>
  fs.readdirSync(d, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? walk(path.join(d, e.name)) : e.name === 'code.html' ? [path.join(d, e.name)] : []);

function writeFontsCss() {
  const imports = [
    ...[300, 400, 500, 600, 700, 800].map((w) => `@import url('file://${FONTSOURCE}be-vietnam-pro/${w}.css');`),
    ...[400, 500, 700].map((w) => `@import url('file://${FONTSOURCE}jetbrains-mono/${w}.css');`),
    `@import url('file://${FONTSOURCE}material-symbols-outlined/400.css');`,
    `.material-symbols-outlined{font-family:'Material Symbols Outlined';font-weight:normal;font-style:normal;font-size:24px;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;white-space:nowrap;direction:ltr;font-feature-settings:'liga';-webkit-font-smoothing:antialiased}`,
  ];
  fs.writeFileSync(path.join(WORK, 'fonts.css'), imports.join('\n'));
}

function buildCss(htmlFile, outCss) {
  const cfgFile = path.join(WORK, 'tw.config.js');
  fs.writeFileSync(
    cfgFile,
    `const base=require(${JSON.stringify(path.resolve(__dirname, 'tailwind.config.js'))});` +
      `module.exports={...base,content:[${JSON.stringify(htmlFile)}]};`,
  );
  const input = path.join(WORK, 'in.css');
  fs.writeFileSync(input, '@tailwind base;@tailwind components;@tailwind utilities;');
  execFileSync('npx', ['tailwindcss', '-c', cfgFile, '-i', input, '-o', outCss], { stdio: 'ignore' });
}

(async () => {
  writeFontsCss();
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--no-sandbox'] });
  const files = walk(MOCKUP_ROOT).filter((f) => f.includes(FILTER));

  for (const file of files) {
    const rel = path.relative(MOCKUP_ROOT, path.dirname(file));
    const slug = rel.replace(/[\\/]/g, '__');
    let src = fs.readFileSync(file, 'utf8')
      .replace(/<script[^>]*cdn\.tailwindcss[^>]*><\/script>/g, '')
      .replace(/<script[^>]*>\s*tailwind\.config[\s\S]*?<\/script>/, '');
    for (const [from, to] of PATCHES[rel.replace(/\\/g, '/')] || []) src = src.split(from).join(to);
    const htmlFile = path.join(WORK, `${slug}.html`);
    fs.writeFileSync(htmlFile, src);
    buildCss(htmlFile, path.join(WORK, `${slug}.css`));
    fs.writeFileSync(htmlFile, src.replace('</head>', `<link rel="stylesheet" href="fonts.css"><link rel="stylesheet" href="${slug}.css"></head>`));

    const page = await browser.newPage({ viewport: VIEWPORT });
    await page.route('**/*', (r) => (r.request().url().startsWith('file://') ? r.continue() : r.abort()));
    await page.goto(`file://${htmlFile}`);
    await page.evaluate(() => document.fonts.ready);
    await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    await page.screenshot({ path: path.join(OUT_DIR, `${slug}.png`), fullPage: true });
    await page.close();
    console.log('baseline', slug);
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
