# Changelog

## 0.3.8 (2025-10-24)

- Server (i18n): Ensure site-level locale takes precedence for server JSON messages even when requests hit non-site routes.
	- Detect site context via route param `site-slug` when available; otherwise, parse Referer (`/s/:site-slug/...`) as a best-effort fallback.
	- Respect an explicit `site_locale` request parameter (sent by the client) before any server-side inference.
	- Use the detected site's `Settings\Site` locale for message selection; then fall back to translator delegated locale; finally to global settings.
	- Fixes an issue where English sites could still receive Japanese messages like “すべてのダウンロード枠が使用中です…” when the global locale was JA.
- Client: POST `/zip-download/item` now includes `site_locale` taken from the page context, ensuring robust i18n on servers without Referer.

日本語サマリ:
- サーバー（i18n）: サイト配下でないルートに来た場合でも、サイトのロケールを最優先で判定するように修正。
	- 可能ならルートの `site-slug` を使用し、なければ Referer（`/s/:site-slug/...`）から推定。
	- クライアントから `site_locale` を明示的に送信する場合は、それを最優先で尊重。
	- 検出したサイトの `Settings\Site` のロケールを使用し、次に翻訳器の委譲ロケール、最後にグローバル設定を参照。
	- グローバルが JA の環境で英語サイトにも日本語メッセージ（例「すべてのダウンロード枠が使用中です…」）が出る問題を解消。
- クライアント: `/zip-download/item` のPOSTにページのロケール `site_locale` を追加し、Referer が送信されない環境でも確実に正しい言語を使用。

## 0.3.7 (2025-10-21)

- Client (Mirador): Insert a "Terms of use / 利用条件" link into Mirador's Download dialog actions (left side) when available.
	- Only when a ZipDownload `.download-panel` is present and a site-level Terms link URL is configured.
	- Language switches automatically (JA: 「利用条件」 / EN: "Terms of use").
	- Uses MutationObserver to detect the dialog; avoids duplicate insertion per dialog instance.
	- Styling: left margin 16px, underline; buttons stay aligned to the right.

日本語サマリ:
- クライアント（Mirador）: Mirador のダウンロードダイアログのアクション行の左側に「利用条件 / Terms of use」リンクを自動挿入。
	- ZipDownload の `.download-panel` があり、サイト設定で Terms URL が設定されている場合のみ。
	- 言語は自動切替（JA:「利用条件」/ EN: "Terms of use"）。
	- ダイアログ生成を MutationObserver で検出し、同一ダイアログでの重複挿入を防止。
	- スタイル: 左マージン 16px、下線。右側のボタン配置は維持。

## 0.3.6 (2025-10-21)

- Server (i18n): Ensure English messages are used by default and translated to Japanese only when site locale is JA.
	- 429 No-slot-available: “All download slots are currently in use. Please wait a moment and try again.”
	- 429 Large concurrent loads: “The server is handling other large downloads. Please wait a moment and try again.”
	- Implementation consolidates message selection via `translateMessage()` to avoid hard-coded JA ternaries.

日本語サマリ:
- サーバー（i18n）: 既定を英語にし、サイトのロケールがJAのときのみ日本語メッセージを返すよう修正。
	- 429 枠不足: 「すべてのダウンロード枠が使用中です。少し待ってからもう一度お試しください。」
	- 429 大きな並列処理中: 「サーバーが他の大きなダウンロードを処理中です。少し待ってからもう一度お試しください。」
	- 実装は `translateMessage()` に統一し、JA直書きの三項分岐を排除。

## 0.3.5 (2025-10-01)

- Client: Default to same-origin-only for ZIP endpoints. Cross-origin fallback is disabled by default; can be enabled per panel via `data-zip-same-origin-only="0"`.
- Client: When same-origin `/zip-download/item` returns an error (e.g., 429 slot busy), stop and show the message instead of falling back to other origins.
- Client: Robust URL derivation for `/status` and `/cancel` even when the base URL has query strings or lives under a site-scoped path.
- Server: `/zip-download/status` now sends no-store/no-cache headers to prevent stale progress JSON being cached by browsers/proxies.
- Server: PHP 8 compatibility — add strict signature to `ZipDownloadProgressFilter::filter()` to eliminate deprecation warnings in responses.
- Logs: Ensure `item_title` is recorded for delayed/early error logs so labels consistently show "ID + title" across statuses.

日本語サマリ:
- クライアント: 既定で同一オリジンのみを使用。クロスオリジンへのフォールバックは無効（必要な場合は `data-zip-same-origin-only="0"` で有効化）。
- クライアント: 同一オリジンの `/zip-download/item` がエラー（例 429）を返した場合は、その時点で中止してメッセージを表示し、他オリジンへはフォールバックしない。
- クライアント: `/status` と `/cancel` の導出を堅牢化（クエリ付きURLやサイト配下のパスでも確実に派生）。
- サーバー: `/zip-download/status` に no-store/no-cache ヘッダを追加し、進捗JSONのキャッシュを防止。
- サーバー: PHP 8 互換 — `ZipDownloadProgressFilter::filter()` に厳密なシグネチャを付与し、レスポンスへの非推奨警告混入を解消。
- ログ: delayed/早期エラーのログにも `item_title` を記録し、全ステータスで「ID+タイトル」表記を統一。

## 0.3.4 (2025-10-01)

- Progress accuracy: `bytes_sent` is now updated using actual streamed bytes.
	- Implemented a lightweight PHP stream filter to count bytes while streaming local files into the ZIP and update progress meta in near real-time.
	- IIIF-added images now increment progress by their actual payload sizes instead of a per-file estimate.
	- Finalization keeps `canceled` status when applicable and avoids overriding with `done`; zero-file outputs are marked `rejected`.
- Logs: Fixed cases where done/canceled rows could retain estimated totals, leading to confusing entries like “6,000,000 / 108,000,000”.

日本語サマリ:
- 進捗精度: `bytes_sent` を実測バイトで更新するように変更。
	- ローカルファイルのZIP書き込みにストリームフィルタを挿入し、転送中に実測を逐次カウント。
	- IIIF 追加分も1ファイルあたりの概算ではなく、取得した実データのサイズで加算。
	- 終了処理でキャンセル状態を維持（`done` で上書きしない）。ファイルが一つも追加されない場合は `rejected`。
- ログ: done/canceled なのに見積もり値のまま残る（例「6,000,000 / 108,000,000」）不整合を解消。

## 0.3.3 (2025-09-30)

- Admin Logs: Added option to clear logs between two date/times (After ~ Before) in addition to existing "Up to now" and "Before date/time".
	- UI: New "Between date/times" mode with `after_datetime` and `before_datetime_range` inputs. Inputs are enabled/disabled based on selected mode.
	- Server: `clearAction` now accepts a date range and deletes records where `started_at` falls within the range. Existing status narrowing still applies.

日本語サマリ:
- 管理ログ: 既存の「今まで」「指定日時まで」に加えて、「開始～終了の範囲（After～Before）」での削除に対応。
	- 画面: 「Between date/times」モードを追加し、`after_datetime` と `before_datetime_range` を入力可能に（選択モードに応じて有効/無効を切替）。
	- サーバー: `clearAction` で開始時刻が範囲内のレコードを削除可能に。従来のステータス絞り込みも併用可。

## 0.3.2 (2025-09-30)

- Site Settings: Added bilingual titles for Download panel and Export block.
	- New keys: `zipdownload_download_panel_title_ja`, `zipdownload_download_panel_title_en`,
		`zipdownload_export_block_title_ja`, `zipdownload_export_block_title_en`.
	- Resolution order: localized JA/EN > fallback underscored key > legacy dotted key > theme > default.
- Templates: Updated module and foundation_tsukuba2025 theme templates to honor localized titles based on current locale.
- Admin form: Prefill and input filters updated to support the new keys.
- Client/UI polish: Download list rows align checkbox and label on a single line and center them vertically. Media IDs are no longer displayed (kept only in data attributes).
 - Removed setting: `zipdownload_export_manifest_property` and related override logic. IIIF Manifest link now always uses the internal `/iiif/{ver}/{item_id}/manifest` URL.

日本語サマリ:
- サイト設定: ダウンロード／エクスポート見出しに日英別フィールドを追加。
	- 新キー: `zipdownload_download_panel_title_ja`、`zipdownload_download_panel_title_en`、
		`zipdownload_export_block_title_ja`、`zipdownload_export_block_title_en`。
	- 優先順位: JA/ENローカライズ > 既存のアンダースコアキー > 旧ドット名 > テーマ > 既定値。
- テンプレート: モジュールおよび foundation_tsukuba2025 テーマでローカライズ見出しを参照。
- 管理UI: 新フィールドの初期値設定と入力フィルタを追加。
- UI調整: ダウンロードリストの各行でチェックボックスとラベルを横一列・上下中央に配置。メディアIDは非表示（data属性のみ保持）。
 - 設定削除: `zipdownload_export_manifest_property` を廃止し、上書きロジックを削除。IIIF Manifest は常に内部の `/iiif/{ver}/{item_id}/manifest` を使用。

## 0.3.1 (2025-09-30)

- Settings (site-level): Moved Download/Export panel texts/links from theme to Site Settings. New keys (underscored) are persisted and grouped under a visible “ZipDownload” heading:
	- `zipdownload_download_panel_title`
	- `zipdownload_download_terms_url`
	- `zipdownload_download_terms_label`
	- `zipdownload_export_block_title`
	- `zipdownload_export_icon_iiif_url`
	- `zipdownload_export_icon_jsonld_url`
	- `zipdownload_export_manifest_property`
- Admin: Added input filters so values save correctly across Omeka S v4; prefill existing values in the Site Settings form; added element_groups label “ZipDownload”.
- Templates: Prefer siteSetting (underscored) with backward-compatible fallback to legacy dotted keys; removed themeSetting fallbacks so configuration is theme-agnostic.
- Export icons: Provide default IIIF/JSON‑LD icon URLs when site settings are empty and fix icon size to 24×24 for consistent display across themes.
- Theme cleanup: Removed ZipDownload-related settings from `foundation_tsukuba2025` theme.ini (terms URL/label, panel title, export icons, manifest property, client-side ZIP toggle). Configure via Site Settings instead.
- Download logs: Added admin logs UI (browse/export/clear) and ensured logs table is auto-created; logs include status, bytes, counts, IP/user, user agent, and more.

日本語サマリ:
- サイト設定: ダウンロード/エクスポートの文言・リンク設定をテーマ設定から「サイト設定 > ZipDownload」に移動（保存可能、見出し付き）。
- テンプレート: サイト設定（アンダースコア）を優先し、旧ドット名を後方互換で参照。テーマ設定へのフォールバックは撤廃。
- アイコン: IIIF/JSON‑LD の既定アイコンURLを用意し、常に 24×24px 表示に統一。
- テーマ整理: foundation_tsukuba2025 の ZipDownload 関連テーマ設定を削除。以後はサイト設定から変更してください。
- ログ: 管理 UI を追加（閲覧／CSV エクスポート／全削除）。ログテーブルは自動作成し、ステータス・バイト数・件数・IP/ユーザー・User-Agent 等を記録。

## 0.3.0 (2025-09-29)

- Concurrency: Introduced OS-level slot locks (flock) to cap global concurrent ZIP builds and auto-release on crash. Avoids stale in-use blocks.
- Stale-state hygiene: Treat progress files as active only when recently updated; purge beyond TTL to prevent deadlocks.
- Progress/ETA UX: Seed `total_bytes`/`total_files` at start (from client estimate or quick local estimate) so ETA becomes meaningful early.
- Streaming: Switched local file addition to ZipStream's `addFileFromStream` in preparation for chunk-based progress updates.
- IIIF progress guard: Bounded approximate progress for IIIF-added entries with a small tail guard to avoid early 100% display.
- Cancel/finalize: Cancellation preserves the latest `bytes_sent`; final write respects meta's `bytes_sent` rather than resetting.
- i18n: Keep server JSON messages localized to JA when site locale is JA.

日本語サマリ:
- 同時実行: OSロックでダウンロード枠を管理し、異常終了でも自動解放。進行中扱いの古いファイルが枠を塞ぐ問題を抑制。
- 進捗/ETA: 開始時に合計バイト数/件数をシードし、早い段階からETAを安定表示。
- ストリーミング: ローカルファイルはストリーム追加に移行（今後のチャンク進捗に備え）。
- IIIF: 概算加算に上限（テールガード）を設け、初期段階で100%になる現象を回避。
- キャンセル/完了: キャンセル時/完了時に `bytes_sent` を尊重して最終値を保持。
- 日本語ローカライズ: サイトのロケールがJAのときはJSONメッセージを日本語に。

### Client JS

- Unified to a single `downloads.js`. Removed deprecated `downloads-lite.js` file and its references.
	- Prevents double-binding issues and simplifies maintenance.
	- Fallback partial continues to load only `downloads.js` with cache-busting via `assetUrl`.

## 0.2.4 (2025-09-26)

- i18n: Localize server messages to Japanese when the site locale is set to JA, without relying on ext/intl.
	- Localized 429 errors (download slots busy, total active bytes exceeded).
	- Localized 413 errors (requested size too large, too many files).
	- Localized common jsonError messages (e.g., "No media selected", "Missing token", "Token not found").
	- Added lightweight helpers: `currentLocaleIsJa()` and `translateMessage()`.
	- JSON schema unchanged (keys are stable: `error`, `retry_after`, etc.).

## 0.2.3 (2025-09-26)

- Endpoints: Add explicit `POST /zip-download/cancel` entry to docs and wire up site-scoped routes (`/s/:site-slug/zip-download/...`) so site context works consistently.
- Client UX: Clarify that the client can cancel in-flight ZIP builds and will not fall back to individual downloads on failure/cancel.
- Settings: Reiterate that server-side limits are configurable via the admin UI and accept human-friendly byte sizes (K/KB, M/MB, G/GB, T/TB).

## 0.2.2 (2025-09-25)

- Feature: Admin settings now accept human-friendly byte sizes for limits (e.g., 512M, 1G, 10G); also supports K/KB, M/MB, G/GB, T/TB.
- UX: Updated labels/placeholders in the settings form to indicate supported size suffixes.
- Dev: Fixed and normalized ConfigForm indentation/array alignment to satisfy linter.

## 0.2.1 (2025-09-25)

- Fix: Ensure client cancel reliably marks progress token as canceled and prevents accidental fallback to individual downloads.
- Fix: Preserve canceled state when progress files are written concurrently; use atomic write and locking to avoid races.
- Fix: Template and client JS updates to avoid stale cached JS causing fallback behavior (cache-busted asset path).

## 0.2.0 (2025-09-24)

- Rewrote ZipDownload streaming implementation to use ZipStream-PHP and stream archives without building a local temp ZIP.
- Added IIIF-first strategy: prefer IIIF-rendered images when available, fallback to local original, then large thumbnail.
- Added server-side progress tokens stored as temp JSON files and endpoints:
	- GET /zip-download/status?token=TOKEN
	- POST /zip-download/estimate
- Implemented conservative server-side limits to protect memory/IO-heavy services.
- Client-side theme JS added to generate progress token, POST with token, and poll status for ETA and progress UI.
- Added started_at to progress records so client can compute ETA from server-side start time.

## 0.1.0 (2025-09-16)

- Initial repository import.
- Streamed ZIP response with safe headers and cleanup.
- Local originals prioritized; IIIF full-resolution fallback; large thumbnail as last resort.
- IIIF v2/v3 parsing, info.json probing, candidate URL generation, retries.
- Added X-Zip-* debug headers and structured logging.

## 2025-09-24

- Minor fixes and cleanup: ensure progress writes include started_at; fix indentation and lint issues in controller.