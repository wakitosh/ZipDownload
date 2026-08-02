# Changelog

## 0.3.13 (2026-08-03)

- Server: Estimate the size of IIIF-served media from pixel area instead of a flat 2 MB per file. The old guess was used whenever a per-file size was unavailable, which is the normal case for media served through an image server, and it was badly wrong for scanned material: an 880 page item was estimated at 1.76 GB against about 6.5 GB actually produced. Measured against production material the new estimate is within about 4%.
- Server: Calibrate bytes-per-pixel by fetching two real derivatives, for selections of three files or more. The size cannot simply be asked for — Cantaloupe generates derivatives on demand and streams them chunked, so it answers HEAD without a `Content-Length` and ignores `Range` — so a sample is the only way to measure. This brings the estimate to within about 1% on uniform material.
- Server: Report `files_done` / `files_total` in the progress status, so the client can project the final archive size from the work completed so far and keep the remaining time meaningful even when the up-front estimate is wrong.
- Server: Stop clamping `bytes_sent` just below `total_bytes`. The clamp froze reported progress whenever the estimate was too low, and it hid the very numbers needed to correct that estimate.
- Server: Share IIIF service-id resolution between the ZIP builder and the size estimator, so both resolve the same images. The estimator previously built its probe URL without stripping any `info.json` suffix, so every probe failed and every file fell back to the flat guess.
- Client: Project the total archive size from the server's per-file progress, and prefer it over the up-front estimate. A remaining time is now shown throughout the transfer instead of an open-ended "finishing up" that could last for gigabytes.
- Client: When no size projection is available yet, report file counts (`620/880 files`) rather than an indefinite message.

- Config: Raise the default `max_bytes_per_download` from 3 GB to 8 GB, and `max_total_active_bytes` from 6 GB to 8 GB. With the estimate corrected, items that genuinely exceed the limit are now refused up front where the understated estimate previously let them through — an 880 page volume of about 6.5 GB was passing a 3 GB limit. The total-active limit is raised to match, because a single global download slot means only one download is ever active and a lower total would make the per-download limit unreachable.

Note: these are defaults for installations that have never saved the module configuration. An installation with a stored value keeps it; change it under Modules → ZipDownload → Configure (the field accepts `8G`).

日本語サマリ:
- サーバー: IIIF経由で配信されるメディアのサイズ見積を、1ファイルあたり2MB固定から**ピクセル面積ベース**に変更しました。従来の固定値はファイルごとのサイズが取得できない場合に使われており、画像サーバー経由のメディアでは常にこれに該当していたため、スキャン資料では大きく外れていました（880ページのアイテムで見積1.76GBに対し実際は約6.5GB）。本番データで検証したところ、新方式の誤差は約4%です。
- サーバー: 3ファイル以上の選択時に、実際の派生画像を2枚取得してピクセルあたりのバイト数を較正します。Cantaloupeは派生画像をオンデマンド生成してchunkedで返すため、HEADに`Content-Length`を返さず`Range`も無視します。つまりサイズを問い合わせる手段がなく、実測するしかありません。これにより均質な資料では誤差約1%になります。
- サーバー: 進捗ステータスに `files_done` / `files_total` を追加しました。クライアントが処理済みファイル数から最終的なZIPサイズを射影できるため、初期見積が外れていても残り時間が意味を持ち続けます。
- サーバー: `bytes_sent` を `total_bytes` の直下で頭打ちにする処理を廃止しました。この頭打ちは見積が小さすぎる場合に進捗表示を凍結させ、さらに見積を補正するために必要な数値そのものを隠していました。
- サーバー: IIIFサービスIDの解決処理をZIP生成側と見積側で共通化し、双方が同じ画像を参照するようにしました。見積側は従来 `info.json` 接尾辞を除去せずにURLを組み立てていたため、全ての問い合わせが失敗し、全ファイルが固定値にフォールバックしていました。
- クライアント: サーバーのファイル単位の進捗から全体サイズを射影し、初期見積より優先して使用します。転送中は常に残り時間が表示されるようになり、数GBにわたって「まもなく完了」が続く状態は解消されます。
- クライアント: 射影がまだ得られない段階では、不定な文言ではなくファイル数（`620/880 ファイル`）を表示します。
- 設定: `max_bytes_per_download` の既定値を 3GB から **8GB** に、`max_total_active_bytes` を 6GB から **8GB** に引き上げました。見積が正確になったことで、実際に上限を超えるアイテムは事前に拒否されるようになります（従来は見積が過小だったため、約6.5GBの880ページ資料が3GB制限を通過していました）。同時実行スロットは全体で1つのため、同時アクティブ量の上限を低いままにすると1件あたりの上限に到達できなくなるので、こちらも同値に揃えています。

注意: これらはモジュール設定を一度も保存していないインストールに適用される既定値です。既に値が保存されている場合はその値が維持されるため、管理画面の モジュール → ZipDownload → 設定 で変更してください（`8G` の形式で入力できます）。

## 0.3.12 (2026-08-02)

- Client: Base the download-panel progress on the bytes the browser has actually received, read incrementally from the response stream, instead of the server-side `bytes_sent` counter. The server counter only tracks what PHP wrote to the output stream; with FastCGI/proxy buffering it reaches 100% while the browser is still receiving, so the ETA collapsed to a few seconds and the panel then appeared frozen for the rest of a multi-GB transfer. The reported time now covers ZIP building and transfer together.
- Client: Show transferred size, total size and current speed alongside the percentage, and repaint every 500 ms independently of the 1.2 s status poll, so the display keeps moving while the server is quiet.
- Client: Prefer `Content-Length`, then the actual archive size the server reports on completion, then the client-side estimate, when computing the remaining time.
- Client: Fold received chunks into `Blob` parts every 64 MB so multi-GB archives do not accumulate on the JS heap. Browsers without streaming response bodies fall back to the previous `Response.blob()` path.

日本語サマリ:
- クライアント: ダウンロードパネルの進捗を、サーバー側の `bytes_sent` ではなく、レスポンスをストリームで読み取って得た「ブラウザが実際に受信したバイト数」を基準に変更しました。サーバー側カウンターはPHPが出力ストリームに書いた量しか数えておらず、FastCGI/プロキシのバッファリングによりブラウザの受信中に100%へ到達してしまうため、残り時間が数秒まで落ちた後、数GB規模の転送が終わるまで画面が停止したように見えていました。表示される残り時間がZIP生成と転送の両方を含むようになります。
- クライアント: パーセンテージに加えて転送済みサイズ・全体サイズ・現在の速度を表示し、1.2秒間隔のステータスポーリングとは独立に500msごとに再描画するようにしました。サーバーからの応答がない間も表示が動き続けます。
- クライアント: 残り時間の計算に用いる全体サイズを、`Content-Length` → 完了時にサーバーが報告する実サイズ → クライアント側の推定値、の優先順で採用するようにしました。
- クライアント: 受信チャンクを64MBごとに `Blob` へ畳み込み、数GBのアーカイブがJSヒープに蓄積しないようにしました。ストリーミング非対応のブラウザーでは従来の `Response.blob()` 方式にフォールバックします。

## 0.3.11 (2026-07-24)

- Server: Make the ZIP streaming endpoint (`/zip-download/item/:id`) POST-only before any database or log work is performed. Crawlers had discovered the endpoint URL embedded in item-page data attributes and requested it without `media_ids`, producing many noisy “No media selected” failed log rows. Normal UI downloads already use POST and are unchanged.
- Server i18n: Add a localized JSON message for “Method not allowed”.

日本語サマリ:
- サーバー: ZIP配信エンドポイント（`/zip-download/item/:id`）を、DB処理やログ記録の前に POST のみに限定しました。クローラーがアイテムページ内の data 属性にあるエンドポイントURLを発見し、`media_ids` なしでアクセスしていたため、“No media selected” の失敗ログが大量に発生していました。通常UIのダウンロードは従来どおりPOSTを使うため挙動は変わりません。
- サーバー i18n: “Method not allowed” の日本語メッセージを追加しました。

## 0.3.10 (2026-02-13)

- Admin Logs UI: Switched pagination to navigation buttons (First / Previous / Next / Last) with Ajax partial updates.
- Admin Logs UI: Added direct page-jump form (page number + Go), handled via Ajax.
- Admin Logs UI: Added rows-per-page selector (10/25/50/100) with Ajax refresh while preserving active filters.

日本語サマリ:
- 管理ログ画面: ページャーをナビゲーションボタン方式（先頭／前へ／次へ／最後）に変更し、Ajax で部分更新するようにしました。
- 管理ログ画面: 指定ページへ直接移動できるページジャンプフォーム（ページ番号 + Go）を追加しました（Ajax対応）。
- 管理ログ画面: 1ページあたりの行数（10/25/50/100）を変更可能にし、現在のフィルタを維持したまま Ajax 再描画します。

## 0.3.9 (2025-11-06)

- Templates (export / download): Generate IIIF Manifest URLs via IiifServer helper and prefer CleanUrl identifiers when enabled.
	- Use the `iiifUrl` view helper to build `/iiif/{2|3}/{identifier}/manifest` when the item has a CleanUrl identifier.
	- If the helper returns a numeric-ID URL, rebuild the identifier segment using the CleanUrl property and normalize colons in the URL-encoded form.
	- Always output an absolute URL (scheme + host) to feed client JS reliably; fall back to absolute numeric-ID manifest when identifiers are unavailable/disabled.
- Behavior is unchanged on sites without CleanUrl identifiers; existing numeric-ID IIIF URLs continue to work.

日本語サマリ:
- テンプレート（エクスポート／ダウンロード）: IiifServer のヘルパーで IIIF マニフェスト URL を生成し、CleanUrl の識別子を優先して使用します。
	- `iiifUrl` ビューヘルパーで `/iiif/{2|3}/{identifier}/manifest` を構築（識別子がある場合）。
	- ヘルパーが数値IDのURLを返した際は、CleanUrl のプロパティから識別子を再構築し、URLエンコード内のコロンを正規化します。
	- クライアントJSのため常に絶対URLを出力。識別子が無い／無効な場合は数値IDの絶対URLにフォールバックします。
- CleanUrl を使っていないサイトでは挙動は従来どおり（数値IDの IIIF URL）。

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