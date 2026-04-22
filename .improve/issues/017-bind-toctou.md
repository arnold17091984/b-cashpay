---
id: "017"
title: "handleBind TOCTOU allowed concurrent double-bind"
severity: high
category: bug
status: resolved
file: "api/src/Services/TelegramCommandHandler.php"
line: 147-193
round_found: 2
round_resolved: 2
---

## Description
`handleBind()` did `SELECT` → check-in-PHP → `UPDATE` in separate roundtrips. A race between two Telegram users holding the same leaked token could both pass the consumed_at check and both overwrite `admin_users.telegram_user_id`.

## Fix
Atomic single `UPDATE telegram_bind_tokens SET consumed_at=NOW() WHERE token=? AND consumed_at IS NULL AND expires_at > NOW()`; only the caller with `rowCount() === 1` proceeds. Same pattern as `consumePendingIntent` (fix #002).
