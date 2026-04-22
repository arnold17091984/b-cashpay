---
id: "016"
title: "Telegram webhook read unbounded body into memory"
severity: high
category: security
status: resolved
file: "api/src/Controllers/TelegramWebhookController.php"
line: 69
round_found: 2
round_resolved: 2
---

## Description
`file_get_contents('php://input')` was called with no size limit after only the secret-token and kill-switch checks. A crafted oversized payload could exhaust PHP memory before `json_decode` ran.

## Fix
Content-Length ceiling check + `stream_get_contents($fp, 1_048_576)` limit. Over-sized requests return 200 `oversize` without decoding.
