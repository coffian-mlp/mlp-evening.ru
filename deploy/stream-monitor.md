# Stream supervision and private Lyra notifications

## Scope

Monitoring applies only to the self-hosted HLS source and events explicitly marked for supervision. The most recently started overlapping event owns the single stream path. An initial stream that never appeared does not create a midstream incident. An observation loop must see 60 seconds of advancing HLS before arming. It detects a missing source, unavailable HLS, or a media playlist that stops advancing for 30 seconds. A whole-server outage requires an independent external observer.

## User setup

1. Link Telegram using the existing social-login control in the site profile.
2. Select **Подключить уведомления Лиры**, open the bot and press **Start** using that same Telegram account.
3. A random single-use link expires after 10 minutes. Only its hash is stored. The private sender ID must match the current social binding and current bot credentials.
4. Disconnecting notifications preserves social login. Unlinking Telegram, withdrawing account consent, deleting the account, or replacing bot credentials revokes private access. Account erasure removes contact IDs and anonymizes stream responsibility records.

Under **Расписание событий → Контроль эфира**, select a default streamer. Each event can override it and independently enable supervision. Telegram readiness is shown next to each user. The profile must explicitly connect the bot; existing announcement-owner configuration is not silently imported as user consent.

## Incident lifecycle

- Poll the local media API and HLS every 10 seconds, independent of chat clients and announcement generation.
- After 45 seconds of sustained loss: one chat warning, streamer mention and generic system-origin interaction buttons.
- At 3 minutes: one private Telegram warning if the incident remains unanswered.
- At 10 minutes: website and channel pause notices if it remains unanswered and the signal remains unavailable.
- **Вижу, исправляю** suppresses public escalation; after 10 more minutes without recovery, one private follow-up is allowed.
- **Остановил намеренно** suppresses escalation until recovery or event end. It does not complete a playlist or change viewing records.
- 30 seconds of sustained advancing HLS closes the incident. Recovery is posted to chat only if the initial chat warning was delivered, and to the channel only if its pause notice was confirmed.
- Short reconnects do not close the incident. The source, active event and responsibility are checked again before delivery and before accepting actions. Old Telegram buttons cannot reopen a closed incident.

Messages use the configured persona and memory through a bounded LLM call, without conversational chat history. A model failure falls back to a deterministic message. Cause and recovery time are never inferred. Outbound intent and completion are durable; ambiguous Telegram delivery is recorded as **Доставка не подтверждена**, with no automatic replay. Known failures retry at most three times while the incident remains relevant. The event dashboard displays recent incident and delivery results without exposing tokens or chat identifiers.

## Services and deployment

Apply `migrations/2026_10_27_stream_monitor.sql` before switching application code. It adds nullable event responsibility, an opt-in monitor flag, generic system-origin interactions, private notification contacts, stream observations, incidents and a delivery outbox. It does not remove existing content or modify playlist history.

Install these service units from `deploy/media/` into `/etc/systemd/system/`:

```sh
systemctl daemon-reload
systemctl enable --now mlp-stream-monitor.service mlp-lyra-bot.service
```

The services run as `fastuser` with 128 MiB memory limits each. The private bot uses Telegram long polling with a 20-second server timeout; it does not generate announcement images or publish scheduled posts. Set the site option `lyra_bot_polling_external=1` after confirming the private bot service is active. Announcement generation/publication continue in the existing minute cron. Both the compatibility poller and the dedicated service use one advisory lock and the existing durable per-bot update cursor.

On future code deployments, restart these two application workers after the migration/code update. Do not restart or stop MediaMTX to test notifications on production. Diagnostics:

```sh
systemctl status mlp-stream-monitor mlp-lyra-bot
journalctl -u mlp-stream-monitor -u mlp-lyra-bot --since '-10 minutes'
```

The event dashboard exposes worker heartbeats in UTC. Before production rollout, verify existing source/event choices without switching the public player. Local fixtures and simulated Telegram/HLS transports must never run against production.
