---
id: "023"
title: "createChildFromTemplate accepted deactivated bank accounts"
severity: medium
category: bug
status: resolved
file: "api/src/Services/PaymentLinkService.php"
line: 296-336
round_found: 2
round_resolved: 2
---

## Description
The template's `bank_account_id` was copied to each child at spawn time with no re-check of `bank_accounts.is_active`. If an operator deactivated the bank after issuing the template, children would display a dead account and the scraper (which filters `is_active=1`) would never poll for the deposit — creating a permanently un-payable link.

## Fix
`createChildFromTemplate()` now runs a `SELECT id FROM bank_accounts WHERE id = ? AND is_active = 1 LIMIT 1` pre-check inside the transaction and throws `RuntimeException` when the bank is inactive. `PaymentPageController::submit()` catches the exception and re-renders the form with the human-readable message.
