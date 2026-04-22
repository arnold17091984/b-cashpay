---
id: "003"
title: "finaliseAwaitingInput allocated reference-number before claiming the row"
severity: high
category: bug
status: resolved
file: "api/src/Services/PaymentLinkService.php"
line: 348-383
round_found: 1
round_resolved: 1
---
## Description
Reference number was generated before the atomic UPDATE WHERE status='awaiting_input' guard. On a race, the loser burned a reference number that was never inserted.

## Fix
Two-step transaction: (1) claim the row by setting `locked_at` WHERE `locked_at IS NULL`, only winner sees rowCount()==1; (2) generate reference + fill real fields + promote to pending. Reference namespace no longer consumed on races.
