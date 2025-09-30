# Changelog

## 0.3.2 (2025-09-30)

- Site Settings: Added bilingual titles for Download panel and Export block.
	- New keys: `zipdownload_download_panel_title_ja`, `zipdownload_download_panel_title_en`,
		`zipdownload_export_block_title_ja`, `zipdownload_export_block_title_en`.
	- Resolution order: localized JA/EN > fallback underscored key > legacy dotted key > theme > default.
- Templates: Updated module and foundation_tsukuba2025 theme templates to honor localized titles based on current locale.
- Admin form: Prefill and input filters updated to support the new keys.
- Client/UI polish: Download list rows align checkbox and label on a single line and center them vertically. Media IDs are no longer displayed (kept only in data attributes).

日本語サマリ:
- サイト設定: ダウンロード／エクスポート見出しに日英別フィールドを追加。
	- 新キー: `zipdownload_download_panel_title_ja`、`zipdownload_download_panel_title_en`、
		`zipdownload_export_block_title_ja`、`zipdownload_export_block_title_en`。
	- 優先順位: JA/ENローカライズ > 既存のアンダースコアキー > 旧ドット名 > テーマ > 既定値。
- テンプレート: モジュールおよび foundation_tsukuba2025 テーマでローカライズ見出しを参照。
- 管理UI: 新フィールドの初期値設定と入力フィルタを追加。
- UI調整: ダウンロードリストの各行でチェックボックスとラベルを横一列・上下中央に配置。メディアIDは非表示（data属性のみ保持）。

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