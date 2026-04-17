---
id: "005"
title: "Hiragana input was silently accepted by mb_convert_kana('KVC')"
severity: medium
category: quality
status: resolved
file: "api/src/Controllers/PaymentPageController.php"
line: 159-168
round_found: 1
round_resolved: 1
---
## Description
Kana regex ran AFTER hiragana→katakana conversion, so hiragana input passed silently despite the UI hint saying カタカナで入力.

## Fix
Regex now runs BEFORE conversion and explicitly allows both katakana and hiragana. Policy documented inline. If hiragana should be rejected, flip the pre-convert check.
