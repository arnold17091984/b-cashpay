---
id: "022"
title: "Rate-limit /tmp files silently bypass limits on disk-full"
severity: medium
category: design
status: open
file: "api/public/index.php"
line: 247-270
round_found: 2
---

## Description
`applyRateLimit()` uses `@file_put_contents(…, LOCK_EX)`. The `@` swallows `ENOSPC` and `EACCES`, meaning a disk-full or permissions failure silently drops the count persistence and the next request sees `count=0` — limits are NOT enforced. Also no cleanup of stale bucket files, and a small race window between concurrent FPM workers can undercount.

## Fix
Deferred. Options (pick later):
- Remove the `@`, fail closed (429) on write failure.
- Swap the /tmp file backend for APCu (already required by composer install on this host) or Redis.
- Add a daily find-and-delete sweep for `/tmp/bcashpay_rl_*.json` older than the longest window (say 1 hour).
