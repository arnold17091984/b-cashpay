---
id: "002"
title: "consumePendingIntent was not atomic — duplicate Telegram confirmations could double-issue"
severity: critical
category: bug
status: resolved
file: "api/src/Services/TelegramCommandHandler.php"
line: 457-485
round_found: 1
round_resolved: 1
---
## Description
SELECT-then-UPDATE on telegram_pending_intents allowed retried callback_query deliveries to both pass the "consumed_at IS NULL" check.

## Fix
Replaced with a single atomic UPDATE joining admin_users, filtered on nonce + consumed_at IS NULL + expires_at > NOW + telegram_user_id. Only the caller seeing rowCount()==1 proceeds.
