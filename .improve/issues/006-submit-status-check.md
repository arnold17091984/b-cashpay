---
id: "006"
title: "submit() branched on link_type, which finaliseAwaitingInput mutates"
severity: medium
category: design
status: resolved
file: "api/src/Controllers/PaymentPageController.php"
line: 126-128
round_found: 1
round_resolved: 1
---
## Description
The "can this link accept a submit?" gate keyed on link_type. Since finaliseAwaitingInput changes link_type from 'awaiting_input' to 'single', re-submit behaviour depended on the mutation having propagated.

## Fix
Guard now keys on status (authoritative state field) in addition to link_type. Re-submits get the correct redirect regardless of mutation ordering.
