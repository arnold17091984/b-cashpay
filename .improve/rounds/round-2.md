# Round 2 Summary

- 開始時刻: 2026-04-18
- 所要時間: ~20分
- 発見 issue 数: 8 (high 4 / medium 4)
- 修正 issue 数: **6**
- 見送り issue 数: 2 (#020 テーブル肥大, #022 rate-limit /tmp) — 次ラウンド
- テスト結果: PHP syntax OK / deploy smoke OK / セキュリティヘッダ確認 / 金額上限の動作確認 / session Secure 確認

## 本ラウンドで解消

| ID | 重要度 | 件名 |
|----|--------|------|
| 016 | high | Telegram webhook body size 制限なし |
| 017 | high | /bind トークン TOCTOU (double-bind 攻撃) |
| 018 | high | TELEGRAM_ALLOWED_CHAT_IDS 設定されているが未参照 |
| 019 | high | admin session cookie に Secure flag なし |
| 021 | medium | セキュリティヘッダ欠如 (HSTS/CSP/Referrer-Policy/X-Frame) |
| 023 | medium | テンプレートの deactivated 銀行を spawn 時に検証せず |

## 見送り（次ラウンド）

| ID | 件名 | 理由 |
|----|------|------|
| 020 | scraper_tasks / telegram_updates のデータ肥大化 | 運用バッチ（cron + index）追加が必要。次ラウンドで DB マイグレーションと併せて |
| 022 | /tmp rate-limit ファイルの silent bypass | Redis/APCu への切替か日次クリーンアップが必要、機能追加レベル |

## 主な改善インパクト

- **攻撃面縮小**: webhook に DoS 耐性、CSP で XSS blast radius 制限、HSTS で downgrade 攻撃遮断、session 盗聴遮断
- **冪等性継続**: `/bind` も `consumePendingIntent` と同じ atomic UPDATE パターンに統一
- **誤設定防止**: 銀行を deactivate した瞬間から新しい子リンクは弾く、壊れた pending をユーザーに配らない
- **運用**: `TELEGRAM_ALLOWED_CHAT_IDS` env が初めて実効になり、bot を誤って別群に追加しても守れる

## Checkpoint

Deploy + 動作確認済み:
- `Set-Cookie: bcashpay_admin_session=…; secure; HttpOnly; SameSite=Lax`
- `Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options: DENY` 全て応答に付与
- `submit amount=99999999` → 200 + 「金額の上限を超えています」(9,999,999 絶対上限)
- `submit amount=5000` → 303 正常動作
