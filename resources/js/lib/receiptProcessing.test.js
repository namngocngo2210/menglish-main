import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as Vue from 'vue';
import { parse } from '@vue/compiler-sfc';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Render the actual button templates with controlled form state; no browser or extra test dependency.
function templateRender(path) {
    const { descriptor } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
    return new Function('Vue', compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
}
const pageRender = templateRender('../Pages/Tuition/ReceiptForm.vue');
const summaryRender = templateRender('../Pages/Tuition/Partials/ReceiptSummary.vue');
const wrapper = { setup: (_, { slots }) => () => Vue.h('div', [slots.default?.(), slots.footer?.()]) };
const button = { setup: (_, { attrs, slots }) => () => Vue.h('button', attrs, slots.default?.()) };

async function buttons({ processing, modal = false, summary = false, valid = true }) {
    const app = Vue.createSSRApp({
        setup: () => ({
            modal, processing, ctx: { processing }, editing: null,
            pageTitle: 'Lập phiếu thu', nextReceiptNumber: 'PT-1', action: '/receipts', formId: 'receiptForm',
            submitLabel: 'Lưu & Gửi duyệt', cancelUrl: '/tuition', recentRejection: null, canRequestCancel: false,
            layout: 'page', q: null, money: String, formatMoney: String, route: () => '/tuition',
            form: { state: {}, calc: {}, totalAmount: 1000000, isValidReceipt: valid, submitBlockers: [] },
        }),
        render: summary ? summaryRender : pageRender,
    });
    for (const name of ['UiForm', 'UiModalFrame', 'UiPageHeader', 'UiBadge', 'UiCode', 'Link']) app.component(name, wrapper);
    app.component('UiButton', button);
    app.component('ReceiptFields', { render: () => null });
    const html = await renderToString(app);
    return [...html.matchAll(/<button\b[^>]*name="submit_action"[^>]*>/g)].map(([tag]) => ({
        draft: tag.includes('value="draft"'), disabled: /\sdisabled(?:[\s=>])/.test(tag),
    }));
}

for (const [name, location] of [['modal footer', { modal: true }], ['mobile', {}], ['desktop summary', { summary: true }]]) {
    test(`${name}: draft and submit disable during processing and re-enable afterwards`, async () => {
        for (const processing of [false, true, false]) {
            const result = await buttons({ ...location, processing });
            assert.equal(result.length, 2);
            assert.deepEqual(result.map((item) => item.disabled), [processing, processing]);
        }
    });
    test(`${name}: invalid receipt still allows draft only when idle`, async () => {
        const idle = await buttons({ ...location, processing: false, valid: false });
        assert.equal(idle.find((item) => item.draft).disabled, false);
        assert.equal(idle.find((item) => !item.draft).disabled, true);
        const busy = await buttons({ ...location, processing: true, valid: false });
        assert.ok(busy.every((item) => item.disabled));
    });
}
