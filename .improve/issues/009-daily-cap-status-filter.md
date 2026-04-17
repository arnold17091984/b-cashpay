---
id: "009"
title: "rollingDailyAmount counted cancelled/expired links against Telegram daily cap"
severity: medium
category: bug
status: resolved
file: "api/src/Services/TelegramCommandHandler.php"
line: 442-452
round_found: 1
round_resolved: 1
---
## Description
Cancelled or expired links still counted toward the 24h rolling amount cap, penalising operators who corrected bad links and opening a cap-DoS vector if auto-cancel flapped.

## Fix
SUM now filters out `status IN ('cancelled', 'expired')`.
