# Telegram announcement editor

MLP-375 uses a dedicated polling bot and an isolated minute worker. It does not reuse the authentication bot token or execute within the realtime chat worker.

## Configuration

Create the bot through BotFather. Add it to the announcement channel as administrator with permission to post messages. The owner must start a private conversation with the bot. Store these values only in the production `.env`:

```
TELEGRAM_ANNOUNCEMENTS_TOKEN=<dedicated bot credential>
TELEGRAM_ANNOUNCEMENTS_OWNER_ID=<trusted numeric Telegram user ID>
TELEGRAM_ANNOUNCEMENTS_CHANNEL=@mlp_evening
```

The owner ID must be independently verified, for example against the owner's existing Telegram login binding. Never select the first sender of `/start` as administrator. Secrets must not be included in Git or shell history.

Apply `2026_10_08_telegram_announcements.sql`, then deploy the code. Check readiness:

```
php telegram_announcements_setup.php --check
php telegram_announcements_setup.php --enable EVENT_ID FIRST_NUMBER FIRST_DATE
```

The checks verify the bot identity, absence of an existing webhook, channel posting permission and the owner's private conversation. Activation is explicit and disabled by default. FIRST_DATE is a Moscow calendar date (YYYY-MM-DD); earlier occurrences are excluded, allowing an already prepared manual announcement to remain untouched. `--disable` stops processing without deleting state. Install the following cron entry for the site user after checks succeed:

```
* * * * * /usr/bin/php /var/www/fastuser/data/www/mlp-evening.ru/cron_announcements.php >/dev/null 2>&1
```

## Operation

Wednesday 19:00 Europe/Moscow starts draft generation for the next weekly playlist event. Text and image phases run separately; each post is delivered privately as a photo caption with approval controls. Main publication is two calendar days before the event at 19:00 Moscow; reminder publication is three hours before its start. For Saturday events these are Thursday 19:00 and Saturday 16:00. Delayed main approval remains valid only before the reminder time; delayed reminder approval only before the event starts. Pending posts expire thereafter.

The number is allocated once per occurrence. Main captions use `#N`; reminders use `Notificatio` and Roman numerals. Photo captions are plain text, at most 1024 UTF-16 units. Facts use the bound immutable playlist, adjoining night event and a bounded nondeleted public-chat window from the previous event. Persona and community memory inform style; chat messages are treated as untrusted evidence, not instructions.

Approve each post independently. Reply to its preview for text edits. The regeneration buttons select text, image or both, immediately revoke approval and ask for feedback; replying `заново` requests a new variant without additional feedback. Every replacement needs approval. Event or playlist changes invalidate pending approval; published posts are not silently edited or reposted.

Commands:

- `/start`, `/help`: editing instructions.
- `/status`: active revision IDs and scheduled delivery times.
- `/retry ID`: create a fresh revision after generation failure or ambiguous private-preview delivery. It cannot resend an ambiguous channel publication.
- `/sent ID MESSAGE_ID`: manually record an ambiguous channel publication after checking that it actually exists.

Outbound sends checkpoint their state before HTTP. A network-ambiguous result or process interruption enters `uncertain` and is never automatically resent. Inspect the channel/private chat before reconciliation. Known rejections retry with a five-minute delay, at most three times. Generation retries after ten minutes, at most three times. No approval means no channel publication.

`site_options.announcements_heartbeat` records a completed worker iteration. Existing chat heartbeat remains independent. The existing image generation quota is advisory across processes; generated output uses the configured Lyra illustration style and provider.
