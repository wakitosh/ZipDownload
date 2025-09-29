 # ZipDownload Module for Omeka S

 This module streams ZIP archives of selected media for an item using ZipStream-PHP and an IIIF-first strategy. It is intended for large exports where building a whole archive on-disk is undesirable.

 ## Features

 - Streams ZIP files on-demand using ZipStream-PHP (bundled in module vendor).
 - Prefers IIIF-rendered images if available, then local original files, then large thumbnails.
 - Provides server-side progress via a per-download token and temp-file JSON records.
 - Conservative server-side limits to avoid overloading memory/IO-heavy services, configurable from the module settings (admin UI). Human-friendly byte sizes like 512M/1G/10G are accepted.

 ## Endpoints

 - POST /zip-download/item/:id — start streaming a ZIP for item `:id` (POST `media_ids`, `progress_token`, optional `estimated_total_bytes`/`estimated_file_count`).
 - GET /zip-download/status?token=TOKEN — read progress JSON for token.
 - POST/GET /zip-download/estimate — best-effort estimate of `total_bytes` and `total_files` for given `media_ids`.
 - POST /zip-download/cancel — cancel a running ZIP build (`progress_token` required).

Notes:
- Site-scoped routes such as `/s/:site-slug/zip-download/...` are also available for Omeka S site contexts.

 ## Usage

 ### Client flow

 1. Call `/zip-download/estimate` with `media_ids` (csv or array) to get `total_bytes` and `total_files`.
 2. Generate a `progress_token` (random string) and POST to `/zip-download/item/:id` with `media_ids=...` and `progress_token=TOKEN` to start streaming.
 3. Poll `/zip-download/status?token=TOKEN` to get `status`, `bytes_sent`, `total_bytes`, and `started_at`.

 ### curl examples

 ```bash
 # Estimate
 curl -X POST "http://your-site/zip-download/estimate" -d "media_ids=1,2,3"

 # Start streaming (client should send token and handle binary response)
 curl -X POST "http://your-site/zip-download/item/123" -d "media_ids=1,2,3" -d "progress_token=tok123" -o download.zip

 # Poll status
 curl "http://your-site/zip-download/status?token=tok123"
 ```

 ## Server-side limits and tuning

 The module sets conservative defaults to avoid overloading typical production instances (config in `ZipController`, overridable via admin settings):

 - `MAX_CONCURRENT_DOWNLOADS_GLOBAL` = 1
 - `MAX_BYTES_PER_DOWNLOAD` = 3GB
 - `MAX_TOTAL_ACTIVE_BYTES` = 6GB
 - `MAX_FILES_PER_DOWNLOAD` = 1000
 - `PROGRESS_TOKEN_TTL` = 7200 (seconds)

 You can adjust these from the ZipDownload module settings page. For byte limits, you may enter human-friendly values like `512M`, `1G`, or `10G` (K/KB, M/MB, G/GB, T/TB are supported). If you prefer code-level defaults, you may also edit `modules/ZipDownload/src/Controller/ZipController.php`.

 ## Resource page blocks (UI integration)

 This module exposes standard “resource page block layouts” that you can place on Item pages from the admin UI (Appearance > Sites > [Your Site] > Pages > Configure resource pages):

 - `exportLinks` — Shows helpful export links for an Item (e.g., IIIF Manifest, JSON-LD). Rendering is handled by the active theme, but configuration is site-level.
 - `downloadPanel` — Shows the client UI for selecting media and starting a ZIP download. It talks to the endpoints listed above.

 Themes can provide the corresponding partials to render these blocks. The included example theme uses the following templates:

 - `view/common/resource-page-blocks/export-links.phtml`
 - `view/common/resource-page-blocks/download-panel.phtml`

 You may also define default placements in a theme’s `config/theme.ini` under `resource_page_blocks` (for example, add both blocks to the Item “right” region). Admins can override placements per site.

 ### Site settings (ZipDownload section)

 The Download/Export panel texts and links are configured per site from the admin UI (Appearance > Sites > [Your Site] > Settings). Keys (underscored):

 - `zipdownload_download_panel_title`
 - `zipdownload_download_terms_url`
 - `zipdownload_download_terms_label`
 - `zipdownload_export_block_title`
 - `zipdownload_export_icon_iiif_url`
 - `zipdownload_export_icon_jsonld_url`
 - `zipdownload_export_manifest_property`

 If icon URLs are left blank, the module falls back to default icons and displays them at 24×24px.

 ## Notes and caveats

 - The module uses filesystem temp files (`sys_get_temp_dir()`) for progress state. On multi-instance deployments, consider replacing this with a shared store (Redis, DB) for consistent global limits.
 - `estimateAction` tries metadata file_size, local filesystem sizes, then IIIF HEAD requests (short timeouts) to improve accuracy.
 - The module disables `zlib.output_compression` for streaming; ensure PHP output buffering and any reverse-proxy buffering are configured appropriately.

 ## Download logs (admin)

 ZipDownload optionally records structured logs for each download session into a module-managed table (`zipdownload_log`). The table is created automatically on first bootstrap.

 - Access: Admin > (left nav) ZipDownload Logs, or `/admin/zip-download/logs`.
 - Actions:
	 - Browse logs in the UI with pagination and filters (status, date range, etc., when available).
	 - Export logs as CSV via `/admin/zip-download/logs/export`.
	 - Clear all logs via `/admin/zip-download/logs/clear` (admin-only, permanent).
 - Columns stored (subset):
	 - `started_at`, `finished_at`, `duration_ms`, `status`
	 - `item_id`, `item_title`, `media_ids`, `media_count`
	 - `bytes_total`, `bytes_sent`, `slot_index`
	 - `client_ip`, `user_id`, `user_email`, `user_agent`, `site_slug`
	 - `progress_token`, `error_message`

 Privacy/retention:
 - Logs may include client IP and user identifiers. If this is sensitive in your environment, consider reducing retention or disabling log exports. Use the Clear action to purge data as needed.
 - Progress files (JSON) are transient and cleaned up by TTL. Logs (DB) are persistent until cleared manually.

 ## Security

 - Only media belonging to the specified item id are included.
 - The module will reject requests exceeding configured size or file count limits with 413/429.

 ## Troubleshooting

 - If downloads are rejected, check logs and consider increasing limits or reducing requested media per ZIP.
 - Check webserver/proxy timeouts; long-running streams can be interrupted by short proxy timeouts.

 ## Development / Hints

 - For multi-server deployments, replace the temp-file progress store with a central store and implement distributed semaphores for concurrent-download limits.
 - Consider offloading heavy IIIF requests to an internal image proxy if Cantaloupe or other image servers are under load.

 ## License

 Same license as the host Omeka S installation.

---

## 日本語版（Japanese）

### 概要

ZipDownload は、ZipStream-PHP を用いてアイテムに紐づくメディアをストリーミングで ZIP するモジュールです。IIIF を優先し、必要に応じてローカルオリジナル／サムネイルにフォールバックします。進捗はトークン単位の JSON で管理し、クライアントからポーリング可能です。

### エンドポイント

- POST `/zip-download/item/:id` — ZIP のストリーミング開始（`media_ids`, `progress_token` 必須）
- GET `/zip-download/status?token=...` — 進捗 JSON を取得
- POST/GET `/zip-download/estimate` — サイズ／件数の概算
- POST `/zip-download/cancel` — 実行中 ZIP のキャンセル

サイト配下のルート（`/s/:site-slug/zip-download/...`）にも対応しています。

### サイト設定（ZipDownload セクション）

ダウンロード／エクスポートの見出し・リンク・アイコンは「サイト設定 > ZipDownload」で管理します（テーマ非依存）。主なキー：

- `zipdownload_download_panel_title`
- `zipdownload_download_terms_url`
- `zipdownload_download_terms_label`
- `zipdownload_export_block_title`
- `zipdownload_export_icon_iiif_url`
- `zipdownload_export_icon_jsonld_url`
- `zipdownload_export_manifest_property`

アイコン URL を未設定の場合は既定のアイコンを使用し、常に 24×24px で表示します。

### リソースページブロック（UI 連携）

- `exportLinks` — IIIF Manifest / JSON‑LD などのエクスポートリンクを表示
- `downloadPanel` — メディア選択と ZIP ダウンロード UI を表示

外観 > サイト > [対象サイト] > Pages > Configure resource pages から配置できます。

### サーバー側の制限と調整

同時実行数やサイズ／件数上限、トークン TTL などは管理画面のモジュール設定から調整可能です。`512M`, `1G`, `10G` などの人にやさしい単位を受け付けます。

### ダウンロードログ（管理者向け）

各ダウンロードの実行情報を DB テーブル（`zipdownload_log`）に記録します（初回ブート時に自動作成）。

- 参照: 管理画面の左ナビ「ZipDownload Logs」または `/admin/zip-download/logs`
- 機能: 画面での閲覧、CSV エクスポート（`/admin/zip-download/logs/export`）、全削除（`/admin/zip-download/logs/clear`）
- 主な項目: `started_at`, `finished_at`, `duration_ms`, `status`, `item_id`, `media_count`, `bytes_total/bytes_sent`, `client_ip`, `user_id/email`, `user_agent`, `site_slug`, `progress_token`, `error_message` など

注意（プライバシー／保管）:
- ログにはクライアント IP やユーザー情報が含まれることがあります。運用ポリシーに応じて保管期間を短くする／エクスポートを制限するなどをご検討ください。不要になったログは「Clear」で削除できます。
- 進捗 JSON は TTL で自動的にクリーンアップされますが、DB のログは明示的に削除するまで残ります。
