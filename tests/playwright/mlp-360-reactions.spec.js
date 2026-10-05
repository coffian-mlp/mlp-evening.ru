const { test, expect, request } = require('@playwright/test');
const { BASE, localFixture, login, post, NEW } = require('./mlp-360-helpers');

test('MLP-360: реальные toggle/count/users, история, unknown/guest/CSRF отказ', async () => {
  test.skip(!BASE, 'MLP_BASE_URL required');
  const fixture = localFixture();
  const ctx = await request.newContext();
  const guest = await request.newContext();
  try {
    const csrf = await login(ctx, fixture);
    const denied = await post(guest, '', { action:'toggle_reaction', message_id:fixture.messageId, reaction:'skull' });
    expect(denied.success).toBeFalsy();
    const noCsrf = await (await ctx.post(BASE + '/api.php', {
      form: { action:'toggle_reaction', message_id:fixture.messageId, reaction:'skull' },
    })).json();
    expect(noCsrf.success).toBeFalsy();
    const unknown = await post(ctx, csrf, { action:'toggle_reaction', message_id:fixture.messageId, reaction:'banana' });
    expect(unknown.success).toBeFalsy();

    for (const reaction of Object.keys(NEW)) {
      const on = await post(ctx, csrf, { action:'toggle_reaction', message_id:fixture.messageId, reaction });
      expect(on.success).toBeTruthy();
      expect(on.data.action).toBe('added');
      expect(on.data.reactions[reaction].count).toBe(1);
      expect(on.data.reactions[reaction].users).toContain('MLP360 Test');
      const history = await post(ctx, csrf, { action:'get_messages', limit:100 });
      const message = history.data.messages.find(m => Number(m.id) === fixture.messageId);
      expect(message.reactions[reaction].count).toBe(1);
      expect(message.my_reactions).toContain(reaction);
      const off = await post(ctx, csrf, { action:'toggle_reaction', message_id:fixture.messageId, reaction });
      expect(off.success).toBeTruthy();
      expect(off.data.action).toBe('removed');
      expect(off.data.reactions[reaction]).toBeUndefined();
    }
  } finally {
    const html = await (await ctx.get(BASE + '/')).text();
    const csrf = html.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];
    if (csrf) {
      const history = await post(ctx, csrf, { action:'get_messages', limit:100 });
      const own = history.data?.messages.find(m => Number(m.id) === fixture.messageId);
      for (const reaction of own?.my_reactions || []) {
        await post(ctx, csrf, { action:'toggle_reaction', message_id:fixture.messageId, reaction });
      }
    }
    await ctx.dispose();
    await guest.dispose();
  }
});
