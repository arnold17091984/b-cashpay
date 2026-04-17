# Improvement Summary

## 実行概要
- 総ラウンド数: 2
- 終了理由: 優先度の高い fix を完了、残件は次ラウンド候補として保留
- ベースライン: PHP syntax check 全パス、本番 E2E 動作確認済み

## 成果
- 発見 issue 数（累計）: **23**
- 修正 issue 数: **16** (critical 2 / high 10 / medium 4)
- 残存 issue 数: **7** (medium 5 / 選択的見送り 2)
- リファクタリング成功: 0 (本セッションは fix に集中)
- リファクタリング revert: 0

## 解消した issue (時系列)

### Round 1 (10件)
| ID | 重要度 | 件名 |
|----|--------|------|
| 001 | critical | template submit に rate limit なし |
| 002 | critical | consumePendingIntent が non-atomic |
| 003 | high | finaliseAwaitingInput の reference 浪費レース |
| 004 | high | 金額絶対上限なし |
| 005 | medium | hiragana policy が不明確 |
| 006 | medium | submit guard が link_type に依存 |
| 007 | high | 入金日 strtotime fallback で guard 無効化 |
| 008 | high | CORS 全公開 |
| 009 | medium | 日次キャップが cancel/expired 計上 |
| 015 | medium | awaiting_input の cancel 不可・template カスケードなし |

### Round 2 (6件)
| ID | 重要度 | 件名 |
|----|--------|------|
| 016 | high | Telegram webhook body size 無制限 |
| 017 | high | /bind TOCTOU (double-bind 攻撃) |
| 018 | high | TELEGRAM_ALLOWED_CHAT_IDS 未実装 |
| 019 | high | admin session cookie に Secure なし |
| 021 | medium | セキュリティヘッダ欠如 (HSTS/CSP/Referrer/Frame) |
| 023 | medium | テンプレート子リンクが deactivated 銀行でも spawn |

## 残存 Issue

| ID | 重要度 | 件名 | 理由 |
|----|--------|------|------|
| 010 | medium | admin と service で reference 生成の重複実装 | リファクタ規模が大きく、現状は整合的に動作 |
| 011 | medium | awaiting_input ページで bank 情報が submit 前に表示 | 仕様判断が必要（UX 意図） |
| 012 | medium | pending intent の nonce が群 chat で可視 | 許可 chat 内のみ有効・ドキュメント化で対処可 |
| 013 | medium | PRESET_AMOUNTS_JSON_RAW の将来的 XSS リスク | 現状は int 限定で安全、防御的コード追加は将来 |
| 014 | medium | 静的アセットの path traversal ガードが脆弱 | 現状の `is_file` で安全、`realpath` に置換すると堅牢 |
| 020 | medium | scraper_tasks / telegram_updates のデータ肥大化 | 運用バッチ（cron + index）追加が必要 |
| 022 | medium | /tmp rate-limit ファイルが disk-full で silent bypass | Redis/APCu 切替が王道 |

## ラウンド別推移

| Round | 発見 | 修正 | Refactor | テスト |
|-------|------|------|----------|--------|
| 1     | 15   | 10   | 0        | pass   |
| 2     | 8    | 6    | 0        | pass   |

## 主な改善インパクト

### セキュリティ
- **多層のレート制限**: POST /p/{token}/submit に IP+token と IP+all-templates の2段階
- **atomic state transitions**: bind, pending intent, awaiting_input 全て同パターンの atomic UPDATE で TOCTOU 排除
- **セキュリティヘッダ完備**: HSTS, CSP, Referrer-Policy, X-Frame-Options, X-Content-Type-Options
- **CORS 制限**: b-pay.ink / admin.b-pay.ink のみ、wildcard 撤廃
- **session cookie**: HttpOnly + Secure + SameSite=Lax
- **Telegram 多層防御**: secret token + kill switch + body size + chat_id allowlist + user_id binding
- **webhook DoS 対策**: 1MB body cap

### 正当性
- **LionExpressPay で発見した同種バグ (過去入金誤マッチ) を B-Pay 側でも予防**: 
  - Stage-3 fallback 削除
  - 入金日の created_at filter
  - strtotime fallback 廃止
- **金額絶対上限**: 9,999,999 JPY、DB 列型と整合
- **deactivated 銀行**: 子リンク spawn 時に必ず active 確認

### 運用
- **awaiting_input / template の cancel 動作**: 子リンクカスケード含め正しく動く
- **Telegram 日次キャップ**: cancel/expired を計上しないので flapping 耐性あり
- **hiragana policy**: 明示的に accept（UI ヒントと整合）

## Deploy 履歴

全 fix は本番 `https://b-pay.ink` / `https://admin.b-pay.ink` にデプロイ済み + E2E smoke 動作確認。
