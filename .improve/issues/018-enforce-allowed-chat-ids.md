---
id: "018"
title: "TELEGRAM_ALLOWED_CHAT_IDS configured but never enforced"
severity: high
category: security
status: resolved
file: "api/src/Controllers/TelegramWebhookController.php"
line: 94-99
round_found: 2
round_resolved: 2
---

## Description
`config('telegram.allowed_chat_ids')` was parsed from env but no code ever consulted it. If the bot was accidentally added to another group, all commands in that group would execute against the bot (bind attempts, new-link staging).

## Fix
`handle()` now checks chat_id against the allowlist immediately after idempotency/metadata extraction; non-allowed chats silently drop with a 200. Empty allowlist keeps the previous any-chat-allowed behaviour.
