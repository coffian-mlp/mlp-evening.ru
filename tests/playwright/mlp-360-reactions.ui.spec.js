const { test, expect } = require('@playwright/test');
const { BASE, localFixture, login, post, NEW, OLD } = require('./mlp-360-helpers');

// Fixture создаётся только CLI с Docker guard. SSE renderer проверяется
// детерминированной доставкой события; это не тест работоспособности транспорта.
test.use({ hasTouch: true });
for (const route of ['/', '/chat_popup.php']) {
  for (const width of [1280, 360]) {
    test(`MLP-360: ${route} ${width}px picker, labels, keyboard/touch, history/realtime`, async ({ page }, testInfo) => {
      test.skip(!BASE, 'MLP_BASE_URL required');
      const fixture = localFixture();
      await page.setViewportSize({ width, height: 800 });
      await page.addInitScript(() => {
        window.__mlp360Sources = [];
        window.EventSource = class {
          constructor() { window.__mlp360Sources.push(this); }
          addEventListener() {}
          close() {}
        };
      });
      const csrf = await login(page.request, fixture);
      const toggle = reaction => post(page.request, csrf, {
        action:'toggle_reaction', message_id:fixture.messageId, reaction,
      });
      try {
        await page.goto(BASE + route, { waitUntil:'domcontentloaded' });
        const message = page.locator(`.chat-message[data-id="${fixture.messageId}"]`);
        const legacy = page.locator(`.chat-message[data-id="${fixture.legacyId}"]`);
        await expect(message).toBeVisible({ timeout:10000 });
        await expect(message.locator('.add-reaction-btn')).toHaveCount(1);
        for (const [key, glyph] of Object.entries(OLD)) {
          await expect(legacy.locator(`.reaction-item[data-reaction="${key}"]`)).toContainText(glyph);
        }

        async function picker() {
          await message.scrollIntoViewIfNeeded();
          await message.hover();
          await message.locator('.add-reaction-btn').hover();
          const result = message.locator('.reaction-picker');
          await expect(result).toBeVisible();
          await expect(result).toHaveCSS('opacity', '1');
          await expect(result.locator('.reaction-picker-item')).toHaveCount(16);
          return result;
        }
        const first = await picker();
        const labels = { skull:'Череп', clown:'Клоун', hundred:'Сто процентов', poop:'Какашка' };
        for (const [key, glyph] of Object.entries(NEW)) {
          const button = first.locator(`[data-reaction="${key}"]`);
          await expect(button).toHaveText(glyph);
          await expect(button).toHaveAccessibleName(labels[key]);
          await expect(button).toHaveAttribute('title', labels[key]);
        }
        const geometry = await first.evaluate(el => {
          const rect = el.getBoundingClientRect();
          const items = [...el.querySelectorAll('.reaction-picker-item')].map(item => {
            const r = item.getBoundingClientRect();
            return { left:r.left, right:r.right, top:r.top, bottom:r.bottom };
          });
          return { left:rect.left, right:rect.right, top:rect.top, bottom:rect.bottom,
            width:window.innerWidth, height:window.innerHeight, items };
        });
        expect(geometry.left).toBeGreaterThanOrEqual(0);
        expect(geometry.right).toBeLessThanOrEqual(geometry.width);
        expect(geometry.top).toBeGreaterThanOrEqual(0);
        expect(geometry.bottom).toBeLessThanOrEqual(geometry.height);
        for (const r of geometry.items) {
          expect(r.left).toBeGreaterThanOrEqual(0);
          expect(r.right).toBeLessThanOrEqual(geometry.width);
        }
        await page.screenshot({ path:testInfo.outputPath(`picker-${width}.png`) });

        // Native button: Enter и Space с keyboard; tap для touch-capable context.
        let index = 0;
        for (const key of Object.keys(NEW)) {
          const current = await picker();
          const button = current.locator(`[data-reaction="${key}"]`);
          if (index < 2) {
            await button.focus();
            await button.press(index === 0 ? 'Enter' : 'Space');
          } else if (width === 360) {
            await button.tap();
          } else {
            await button.click();
          }
          const selected = message.locator(`.reaction-item[data-reaction="${key}"]`);
          await expect(selected).toContainText(NEW[key]);
          await expect(selected.locator('.reaction-count')).toHaveText('1');
          await expect(selected).toHaveClass(/active/);
          await page.reload({ waitUntil:'domcontentloaded' });
          await expect(selected).toContainText(NEW[key]);
          await selected.click();
          await expect(selected).toHaveCount(0);
          index++;
        }

        // Обработчик SSE publication/reaction_update: доставляем реальную API сводку.
        const on = await toggle('skull');
        expect(on.success).toBeTruthy();
        await page.evaluate(({ id, reactions }) => {
          const source = window.__mlp360Sources.find(s => typeof s.onmessage === 'function');
          if (!source) throw new Error('SSE consumer не установлен');
          source.onmessage({ data:JSON.stringify({ type:'reaction_update', id, reactions }) });
        }, { id:fixture.messageId, reactions:on.data.reactions });
        await expect(message.locator('.reaction-item[data-reaction="skull"]')).toContainText('💀');
        const off = await toggle('skull');
        expect(off.data.action).toBe('removed');
        await page.evaluate(({ id, reactions }) => {
          window.__mlp360Sources.find(s => typeof s.onmessage === 'function').onmessage({
            data:JSON.stringify({ type:'reaction_update', id, reactions }),
          });
        }, { id:fixture.messageId, reactions:off.data.reactions });
        await expect(message.locator('.reaction-item[data-reaction="skull"]')).toHaveCount(0);
      } finally {
        // Снять оставшиеся реакции собственной isolated fixture при падении.
        const history = await post(page.request, csrf, { action:'get_messages', limit:100 });
        const own = history.data?.messages.find(m => Number(m.id) === fixture.messageId);
        for (const key of own?.my_reactions || []) await toggle(key);
      }
    });
  }
}
