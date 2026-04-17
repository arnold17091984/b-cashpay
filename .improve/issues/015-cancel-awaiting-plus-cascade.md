---
id: "015"
title: "cancel() rejected awaiting_input + no template→children cascade"
severity: medium
category: bug
status: resolved
file: "admin/src/Controllers/PaymentLinkController.php"
line: 420-421
round_found: 1
round_resolved: 1
---
## Description
The cancel button only accepted status='pending'. Blank awaiting_input links could not be cancelled. Template cancellation did not cascade, so customers with already-spawned children kept a live page.

## Fix
Accept both 'pending' and 'awaiting_input' statuses. When cancelling a template, cascade UPDATE to all pending children.
