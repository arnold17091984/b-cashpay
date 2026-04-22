# Round 1 Summary

- 開始時刻: 2026-04-18
- 所要時間: ~25分（単一ラウンドで10件修正）
- 発見 issue 数: 15 (critical 2 / high 6 / medium 5 / low 0 / 技術負債 2 は次回へ)
- 修正 issue 数: **10**
- スキップ issue 数: 5 (見送り・技術負債)
- リファクタ成功数: 0 (Refactor phase は本ラウンドでは実施せず、fix 優先)
- リファクタ revert 数: 0
- テスト結果: PHP syntax OK / deploy smoke OK / rate-limit 動作確認済み / CORS 動作確認済み

## 本ラウンドで解消

| ID | 重要度 | 件名 |
|----|--------|------|
| 001 | critical | template submit に rate limit なし |
| 002 | critical | consumePendingIntent が non-atomic |
| 003 | high | finalise で reference 浪費レース |
| 004 | high | amount 絶対上限なし |
| 005 | medium | hiragana policy が不明確 |
| 006 | medium | submit guard が link_type に依存 |
| 007 | high | 入金日の strtotime fallback で guard が無効化 |
| 008 | high | CORS が `*` で開放 |
| 009 | medium | 日次キャップが cancel/expired も計上 |
| 015 | medium | awaiting_input の cancel 不可・template のカスケードなし |

## 見送り（次ラウンド）

| ID | 重要度 | 件名 | 理由 |
|----|--------|------|------|
| 010 | medium | admin と service で reference 生成ロジックが二重 | リファクタ規模大、fix 優先 |
| 011 | medium | awaiting_input ページで bank 情報がフォーム前に露出 | 仕様判断要（UX意図） |
| 012 | medium | nonce が group chat で他人に見える | ドキュメント化で済ませる選択肢あり |
| 013 | medium | PRESET_AMOUNTS_JSON_RAW の将来的 XSS リスク | 現状は int only で安全、予防コード入れるかは好み |
| 014 | medium | 静的アセットサーバの path traversal guard が脆弱 | 現状の `is_file` で安全だが realpath に置換可 |

## 主な改善インパクト

- **攻撃面縮小**: template URL DoS + CORS ワイルドカード + 過去入金の誤マッチ再発が一気に塞がる
- **冪等性強化**: Telegram 確認ボタンの二重発火 / awaiting_input の二重 submit の両方が atomic UPDATE で防止
- **運用改善**: awaiting_input を誤発行した場合もキャンセル可能、template を取り下げると全子リンクも無効化

## 次ラウンドの候補

1. Issue 010（reference 生成ロジック一本化）
2. Issue 011（awaiting_input で bank 情報の表示タイミング見直し）
3. 残リファクタ: `S1192`(文字列リテラル重複) / `S1142`(複数 return) の緩和 — 機能影響なし、可読性のみ
