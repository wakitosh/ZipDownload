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

 ### Mirador integration (Download dialog)

 When an Item page uses Mirador and the Mirador Download dialog is opened, this module automatically inserts a "Terms of use" link into the dialog action bar (left side) if a Terms URL is configured at the site level.

 - Prerequisites:
	 - The page includes the ZipDownload `downloadPanel` block (i.e., `.download-panel` is present).
	 - Site setting “Terms link URL (optional)” (`zipdownload_download_terms_url`) is set.
 - Behavior:
	 - The link label follows the current locale (EN: "Terms of use", JA: 「利用条件」).
	 - The link is inserted on the left side of the Mirador dialog actions with a 16px left margin and underline, while Mirador’s buttons remain aligned on the right.
	 - The dialog is detected via MutationObserver; duplicate insertion is avoided.


 ### Site settings (ZipDownload section)

 The Download/Export panel texts and links are configured per site from the admin UI (Appearance > Sites > [Your Site] > Settings). Keys (underscored):

 - `zipdownload_download_panel_title`
 - `zipdownload_download_terms_url`
 - `zipdownload_download_terms_label`
 - `zipdownload_export_block_title`
 - `zipdownload_export_icon_iiif_url`
 - `zipdownload_export_icon_jsonld_url`


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

このモジュールは、ZipStream-PHP を用いてアイテムに紐づく選択メディアをストリーミングで ZIP 配信します。IIIF を優先し、必要に応じてローカルのオリジナルファイル、さらに大きめサムネイルへとフォールバックします。進捗はトークンごとの JSON で管理され、クライアントからポーリングできます。大容量の書き出しでもディスクに一時ZIPを作成せずに配信できます。

### 機能

- ZipStream-PHP によるオンデマンドZIP配信（モジュール内 vendor に同梱）
- IIIF 画像を優先し、なければローカル原本、最後に大サムネイル
- ダウンロードごとのトークンと一時JSONによるサーバーサイド進捗
- 管理UIから調整できる保守的なサーバー制限（メモリ/IO負荷を抑制）。512M/1G/10G のような人にやさしい単位で設定可

### エンドポイント

- POST /zip-download/item/:id — アイテム :id のZIP配信を開始（POST: `media_ids`, `progress_token`, 任意で `estimated_total_bytes`/`estimated_file_count`）
- GET /zip-download/status?token=TOKEN — トークンの進捗JSONを取得
- POST/GET /zip-download/estimate — `media_ids` に対する `total_bytes` / `total_files` の概算
- POST /zip-download/cancel — 実行中ZIPのキャンセル（`progress_token` 必須）

注: サイト配下のルート `/s/:site-slug/zip-download/...` にも対応しています。

### 使い方

1. `/zip-download/estimate` に `media_ids`（csv または配列）を渡して概算サイズ/件数を取得
2. ランダムな `progress_token` を生成し、`/zip-download/item/:id` に `media_ids` と `progress_token` をPOSTして配信を開始
3. `/zip-download/status?token=TOKEN` をポーリングして `status`, `bytes_sent`, `total_bytes`, `started_at` を取得

### サーバー側の制限と調整

既定値は以下の通り（`ZipController`の定数、管理画面で上書き可能）：

- `MAX_CONCURRENT_DOWNLOADS_GLOBAL` = 1
- `MAX_BYTES_PER_DOWNLOAD` = 3GB
- `MAX_TOTAL_ACTIVE_BYTES` = 6GB
- `MAX_FILES_PER_DOWNLOAD` = 1000
- `PROGRESS_TOKEN_TTL` = 7200 秒

管理画面の ZipDownload 設定から変更できます。バイト単位は `512M`、`1G`、`10G` のような表記に対応（K/KB, M/MB, G/GB, T/TB）。コードレベルで既定を変える場合は `modules/ZipDownload/src/Controller/ZipController.php` を編集してください。

### リソースページブロック（UI連携）

管理画面（外観 > サイト > [サイト] > Pages > Configure resource pages）から、以下のブロックをアイテムページに配置できます。

- `exportLinks` — アイテムのエクスポートリンク（IIIF Manifest、JSON‑LDなど）を表示。レンダリングはアクティブなテーマに委ねつつ、設定はサイト単位
- `downloadPanel` — メディア選択と ZIP ダウンロード UI。上記エンドポイントと連携

テーマは以下のテンプレートを提供できます：

- `view/common/resource-page-blocks/export-links.phtml`
- `view/common/resource-page-blocks/download-panel.phtml`

テーマ `config/theme.ini` の `resource_page_blocks` に標準配置を定義することも可能です。サイトごとに管理画面で上書きできます。

### Mirador 連携（ダウンロードダイアログ）

アイテムページで Mirador を使用し、Mirador の Download ダイアログを開いたとき、サイト設定で「Terms link URL (optional)」が設定されていれば、ダイアログ下部のアクション行の左側に「利用条件」リンクを自動挿入します。

- 前提条件:
	- ページに ZipDownload の `downloadPanel` ブロック（`.download-panel`）が含まれていること
	- サイト設定で `zipdownload_download_terms_url`（Terms link URL）が設定されていること
- 挙動:
	- ラベルはロケールに応じて自動切替（EN: "Terms of use" / JA: 「利用条件」）
	- Mirador の右側ボタン群はそのまま、左側に下線付きのリンクを 16px の左マージンで挿入
	- MutationObserver によりダイアログ生成を検出し、同一ダイアログへの重複挿入を防止

### サイト設定（ZipDownload セクション）

ダウンロード/エクスポートの見出しやリンクはサイトごとに設定します（外観 > サイト > [サイト] > Settings）。アンダースコアのキー：

- `zipdownload_download_panel_title`
- `zipdownload_download_terms_url`
- `zipdownload_download_terms_label`
- `zipdownload_export_block_title`
- `zipdownload_export_icon_iiif_url`
- `zipdownload_export_icon_jsonld_url`

アイコンURL未設定時は既定アイコンを使用し、24×24px で表示します。

### 注意事項

- 進捗は `sys_get_temp_dir()` 配下の一時ファイルで保持します。マルチインスタンスでは共有ストア（Redis/DB等）への置換や分散セマフォを検討してください。
- `estimateAction` はメタデータのファイルサイズ、ローカルFSサイズ、IIIFのHEAD要求（短いタイムアウト）を順に試みます。
- ストリーミングのため `zlib.output_compression` を無効化します。PHPの出力バッファやリバースプロキシのバッファ設定にご注意ください。

### ダウンロードログ（管理）

各ダウンロードのログを `zipdownload_log` テーブルに保存します（初回に自動作成）。

- 参照: 管理 > 左ナビ「ZipDownload Logs」または `/admin/zip-download/logs`
- 機能:
	- 画面での閲覧（ページネーション、フィルタ）
	- エクスポート `/admin/zip-download/logs/export`（CSV/TSV、Excel向けCSVあり）
	- クリア `/admin/zip-download/logs/clear`（管理者のみ、恒久削除）
- 主な列:
	- `started_at`, `finished_at`, `duration_ms`, `status`
	- `item_id`, `item_title`, `media_ids`, `media_count`
	- `bytes_total`, `bytes_sent`
	- `client_ip`, `user_id`, `user_email`, `user_agent`, `site_slug`
	- `progress_token`, `error_message`, `slot_index`

プライバシー/保管:
- ログにはクライアントIPやユーザー識別子が含まれる場合があります。ポリシーに応じて保管期間の短縮やエクスポート制限をご検討ください。不要になったログは「Clear」で削除できます。
- 進捗ファイル（JSON）はTTLで自動清掃されます。DBのログは手動で削除するまで残ります。

### セキュリティ

- 指定アイテムのメディアのみを含めます。
- 設定したサイズ/件数/同時実行の上限を超える要求は 413/429 で拒否します。

### トラブルシュート

- 拒否された場合はログを確認し、上限設定の調整やリクエストの分割をご検討ください。
- Webサーバ/プロキシのタイムアウトに注意してください。長時間のストリーミングは短いタイムアウトで中断されることがあります。

### 開発のヒント

- マルチサーバ環境では、進捗ストアを中央集約（Redis/DB）に置き換え、同時ダウンロード制限に分散セマフォを使うことを検討してください。
- IIIFへの負荷が高い場合は、内部画像プロキシの導入を検討してください。

### ライセンス

ホストする Omeka S と同一のライセンスに従います。
