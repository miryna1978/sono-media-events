# sono media イベント（WordPress プラグイン）

sono media（<https://media.sono-music.com/>）に掲載されたイベントの一覧を、ご自身の WordPress サイトに表示するプラグインです。

sono media でイベントを追加したり、公開・非公開を切り替えたりすると、10分以内にサイトの表示にも反映されます。サイト側でイベントを登録し直す必要はありません。

## 動作環境

- WordPress 6.0 以上
- PHP 7.4 以上

## インストール方法

1. 配布されている `sono-media-events.zip` をダウンロードします。
2. WordPress の管理画面で「プラグイン」→「プラグインを追加」→「プラグインのアップロード」を開きます。
3. zip ファイルを選択してインストールし、プラグインを有効化します。

## 初期設定

1. sono media にログインし、アカウント画面（<https://media.sono-music.com/account/>）の「プロフィールのURLをコピー」ボタンを押します。
2. WordPress の管理画面で「設定」→「sono media イベント」を開きます。
3. 「sono media のプロフィール」欄にコピーしたURLを貼り付けて、「変更を保存」を押します。

保存すると、取得できたイベントの件数が設定画面に表示されます。

URL は `https://media.sono-music.com/users/○○○` の形式です。末尾の `○○○` の部分だけを入力しても設定できます。

### 設定画面の構成

「設定」→「sono media イベント」の画面は、次のタブに分かれています。タブごとに保存でき、別のタブの設定は変更されません。保存後は同じタブが表示されます。

| タブ | 内容 |
|---|---|
| 設定 | プロフィール、イベントがないときに表示する文、取得状況の表示、「表示を今すぐ更新する」 |
| 見た目 | 間隔、角丸、縦横比、色などの指定 |
| 使い方 | 主なショートコードの一覧 |
| 関連サービス | sono が配布している sonoTracks Discography と sonoLoop 応援ボタンの案内。該当のプラグインが有効な場合は、その設定画面へのリンクが表示されます |

### イベントがないときに表示する文

表示するイベントが0件のとき、一覧の代わりに「現在、表示できるイベントはありません。」と表示されます。

- 「設定」→「sono media イベント」の「設定」タブにある「イベントがないときに表示する文」で、文を変更できます。空欄にすると、何も表示しません。
- ショートコードの `empty` 属性を指定すると、その一覧だけ文を変更できます。属性を指定しない一覧には、設定画面の値が使われます。
- sono media に接続できず、以前に取得した内容も残っていないときは、イベントがないことを確認できないため、何も表示しません。プロフィールが見つからないときも、何も表示しません。
- 文は `.sono-events` の中の `<p class="sono-events__empty">` に出力されます。

## 使い方

投稿または固定ページに、次のショートコードを入力します。開催予定のイベントが、開催日の近い順に表示されます。

```
[sono_events]
```

### ショートコードのオプション

| オプション | 既定値 | 内容 |
|---|---|---|
| `limit` | `12` | 1ページに表示する件数（最大24件） |
| `columns` | `3` | 列数 |
| `paged` | `false` | `true` にするとページ送りを表示します |
| `past` | `false` | `true` にすると終了したイベントを新しい順に表示します |
| `related` | `true` | `false` にすると、関連ユーザーとして登録されたイベントを表示しません（ご自身が投稿したイベントだけを表示します） |
| `empty` | 設定画面の値 | イベントが0件のときに表示する文を、その一覧だけ変更します。`empty=""` を指定すると、何も表示しません |
| `slug` | 設定画面のプロフィール | 別のプロフィールのイベントを表示します |

### 記述例

表示件数と列数を指定する場合:

```
[sono_events limit="8" columns="3"]
```

すべてのイベントをページ送りで表示する場合:

```
[sono_events paged="true"]
```

終了したイベントを表示する場合:

```
[sono_events past="true" paged="true"]
```

ご自身が投稿したイベントだけを表示する場合:

```
[sono_events related="false"]
```

イベントが0件のときの文を、一覧ごとに変更する場合:

```
[sono_events empty="今後のイベントは準備中です。"]
```

イベントが0件のときに何も表示しない場合:

```
[sono_events empty=""]
```

別のプロフィールのイベントを表示する場合（レーベルのサイトなど）は、そのプロフィールのURLの末尾（`/users/` 以降の部分）を `slug` に指定します。

```
[sono_events slug="○○○"]
```

### ご注意

- ページ送りを使う一覧は、1つのページに1つだけ配置してください。同じページに2つ配置すると、片方のページ送りを操作したときに両方の一覧が切り替わります。
- 1ページに表示できるのは最大24件です。件数の多いページで表示が遅くならないよう、上限を設けています。24件を超える場合は `paged="true"` を指定してください。

## 表示されるイベント

次のいずれかにあてはまる、公開中のイベントが表示されます。

- ご自身が sono media に投稿したイベント（運営が代理で掲載したものを含みます）
- ほかの利用者が投稿したイベントのうち、ご自身が関連ユーザーに登録されたイベント（非表示に設定したものを除きます）

関連ユーザーに登録されたイベントは、標準で一覧に表示されます。表示しない場合は、sono media のアカウント画面（<https://media.sono-music.com/account/>）のイベントタブにある「関連ユーザーに登録されたイベント」で「非表示にする」ボタンを押してください。

非表示にしたイベントは、同じ欄の「非表示のイベント」を開くと一覧で表示されます。「表示する」ボタンを押すと元に戻ります。

アカウント画面の設定とは別に、ショートコードに `related="false"` を指定すると、その一覧では関連ユーザーに登録されたイベントをすべて表示しません。ご自身が投稿したイベントだけが表示されます。

## 表示内容

各イベントには、画像、開催日、イベント名、会場、チケットのリンク（登録されている場合）、sono media のイベントページへのリンクが表示されます。一覧の下には、sono media のプロフィールへのリンク「sono media ですべて見る」が表示されます。

開催日は `2026.10.24（土）18:00〜` の形式で表示されます。

- 複数の日程があるイベントは、日程をすべて表示します。4つ以上ある場合は3つまで表示し、残りは「ほか◯日程」と表示します。
- 開始時刻が登録されていないイベントは、日付のみを表示します。

## アップデート

新しいバージョンが公開されると、WordPress の管理画面に更新の通知が表示されます。「ダッシュボード」→「更新」または「プラグイン」の一覧から、通常のプラグインと同じ操作で更新できます。更新ファイルは wordpress.org ではなく sono media から配信されます。

## 動作の仕組み

このプラグインは、sono media の公開APIからイベント情報を取得して表示します。sono media からお使いのサイトにデータを書き込むことはありません。

- イベント情報はサーバー側で取得し、10分間キャッシュします。サイトの訪問者のブラウザから sono media へ直接アクセスすることはありません。
- sono media に障害が発生しているあいだは、最後に取得できた内容を表示します。取得できた内容がない場合は何も表示せず、エラーメッセージも表示しません。
- sono media でイベントやプロフィールを非公開にしたあと、その状態を一度取得できていれば、障害時でもそのイベントは表示されません。
- 「設定」→「sono media イベント」の「設定」タブにある「表示を今すぐ更新する」を押すと、キャッシュを削除して最新の内容を取得します。`slug` を指定した一覧や、ページ送りの各ページもすべて対象です。
- 取得するのは、sono media で公開されている情報（イベント名、開催日時、会場名と住所、料金、チケットのURL、出演者、画像、イベントページのURL）のみです。連絡先やメールアドレスは取得しません。

## デザインのカスタマイズ

初期状態では色や文字の指定をしていないため、お使いのテーマのデザインがそのまま適用されます。

### 設定画面から変更する

「設定」→「sono media イベント」の「見た目」タブで、間隔、角丸、縦横比、色などを指定できます。

- 空欄の項目には、テーマのデザインが適用されます。
- 色は「この色を指定する」にチェックを入れた項目だけが適用されます。

保存すると、次のような CSS が出力されます。ショートコードを本文に配置した場合は `<head>` 内に、ウィジェットなどに配置した場合はページの末尾に出力されます。

```html
<style>.sono-events{--sono-events-gap:32px;--sono-events-radius:10px}</style>
```

### CSS で変更する

子テーマの `style.css` などに、次のように記述します。設定画面の指定より優先させる場合は、セレクタの詳細度を上げるか `!important` を付けてください。

```css
.sono-events {
  --sono-events-gap: 32px;
  --sono-events-radius: 10px;
  --sono-events-title-color: #111;
  --sono-events-meta-color: #777;
  --sono-events-meta-opacity: 1;
}
```

一覧を囲む要素に記述すると、その一覧だけに適用されます。

| CSS変数 | 既定値 | 対象 |
|---|---|---|
| `--sono-events-columns` | `3` | 列数（ショートコードの `columns` の値が入ります） |
| `--sono-events-min` | `220px` | 1件あたりの最小幅。これより狭くなると折り返します |
| `--sono-events-gap` | `24px` | イベント同士の間隔 |
| `--sono-events-radius` | `4px` | 画像の角丸 |
| `--sono-events-ratio` | `4 / 5` | 画像の縦横比 |
| `--sono-events-link-color` | `inherit` | リンクの文字色 |
| `--sono-events-date-color` | `inherit` | 開催日の文字色 |
| `--sono-events-title-color` | `inherit` | イベント名の文字色 |
| `--sono-events-title-weight` | `bold` | イベント名の太さ |
| `--sono-events-title-size` | `1em` | イベント名の文字サイズ |
| `--sono-events-meta-color` | `inherit` | 会場の文字色 |
| `--sono-events-meta-opacity` | `0.8` | 会場の不透明度 |
| `--sono-events-meta-size` | `0.9em` | 開催日と会場の文字サイズ |
| `--sono-events-ticket-color` | `inherit` | チケットのリンクの文字色 |
| `--sono-events-pager-color` | `inherit` | ページ送りの文字色 |
| `--sono-events-pager-current` | `currentColor` | 現在のページを示す下線の色 |

会場の文字は、初期状態では色を指定せず、不透明度を下げて本文と区別しています。色を指定する場合は `--sono-events-meta-opacity: 1` もあわせて指定してください。不透明度を下げたままだと、背景とのコントラストが低くなり読みにくくなります。

さらに細かく調整する場合は、次のクラスに CSS を指定してください。

| クラス | 対象 |
|---|---|
| `.sono-events` | 全体 |
| `.sono-events__list` | 一覧（グリッド） |
| `.sono-events__item` | イベント1件 |
| `.sono-events__imagelink` | 画像のリンク |
| `.sono-events__image` | 画像 |
| `.sono-events__dates` | 開催日の全体 |
| `.sono-events__date` | 開催日（1日程） |
| `.sono-events__datemore` | 「ほか◯日程」 |
| `.sono-events__title` | イベント名 |
| `.sono-events__place` | 会場 |
| `.sono-events__links` | チケットと詳細のリンクの全体 |
| `.sono-events__ticket` | チケットのリンク |
| `.sono-events__detail` | 「詳細を見る」 |
| `.sono-events__pager` | ページ送り |
| `.sono-events__empty` | イベントがないときの文 |
| `.sono-events__more` | 「sono media ですべて見る」 |

## 開発者向け情報

- API: `GET https://media.sono-music.com/wp-json/sono/v1/events/public?slug={slug}&limit={n}&page={p}&past={0|1}&related={1|0}`
  - レスポンスには `total`、`totalPages`、`page` が含まれます。範囲外のページ番号を指定した場合は、最後のページが返ります。
  - `related` に `0` を指定すると、関連ユーザーとして登録されたイベントを含めません。省略した場合と不正な値の場合は `1`（含める）として扱います。`total`、`upcomingTotal`、`pastTotal` もこの条件で数えます。
  - レスポンスの `related` は、実際に適用された値（true / false）です。
  - `upcomingTotal` と `pastTotal` は、開催予定と終了済みそれぞれの総件数です。
  - プロフィールが存在しない場合や非公開の場合は、`{"code":"not_found"}` と HTTP 404 を返します。
  - 実装はメディアのテーマの `inc/setup-events-public-api.php` です。
- 更新情報: `GET https://media.sono-music.com/wp-json/sono/v1/events-plugin-update`
  - 内容はテーマの `inc/events-plugin-update.json` から返します。
- 接続先の変更: `wp-config.php` などで `define('SONO_MEDIA_API_ORIGIN', 'https://...')` を定義すると、接続先を変更できます。
- ローカル環境での確認: `SONO_MEDIA_API_ORIGIN` にローカルのメディアのURL（例: `http://localhost:8080`）を指定し、このフォルダを `wp-content/plugins/` に配置して有効化します。表示するサイトとメディアが同じコンテナで動いている場合は、コンテナ内から接続できるURL（`http://localhost`）を指定してください。
- 方式: sonoTracks Discography プラグインと同じく、表示するサイト側から API に取得しに行く方式です。sono media 側の公開・非公開の状態と、表示が食い違わないようにするためです。

### リリース手順

開発はメディアのリポジトリ（`wp-content/plugins/sono-media-events/`）で行います。公開ミラーのリポジトリは配布と履歴の公開用で、リリースのたびに上書きします。

1. `sono-media-events.php` のヘッダーの `Version` と、`VERSION` 定数を更新します。
2. テーマの `inc/events-plugin-update.json` の `version` と `changelog` を更新します。
3. `scripts/publish-events-plugin.sh <ミラーのディレクトリ>` を実行します。バージョンの整合性の確認、zip の作成、ミラーへのコピーを行います（push はしません）。
4. `dist/sono-media-events.zip` を、本番の `wp-content/uploads/sono-media-events/sono-media-events.zip` にアップロードします。
5. テーマのファイルを、次の順番で本番にアップロードします。
   1. `inc/setup-events-public-api.php`（API）
   2. `inc/events-plugin-update.json`（更新情報）。必ず手順4の zip のあとにアップロードしてください。先にアップロードすると、zip がない状態で更新の通知が表示されます。
   3. `inc/setup-event-approval.php` と `inc/setup-relay-removal.php`（関連ユーザーの非表示・解除依頼。この順に上げます）
   4. `functions.php`（API の読み込み）。最後にアップロードします。

   API（テーマ）に変更がある版では、手順5で API（5-1）を更新情報（5-2）より先にアップロードしてください。例: 1.0.1 の `related` では、古い API に 1.0.1 のプラグインが接続した場合、`related="false"` を指定した一覧でも関連ユーザーのイベントが表示されます（エラーにはなりません）。古いプラグインが新しい API に接続した場合は、従来どおり関連ユーザーのイベントを含めて表示します。

6. アカウント画面の「プロフィールのURLをコピー」ボタンのファイルを、`assets/js/script.js`、`template-parts/content-page-account.php` の順番でアップロードします。逆の順番にすると、ボタンを押しても動作しない状態になります。
7. ミラーのリポジトリで commit・push します。

`functions.php` は、API のファイルが本番にない場合は読み込みをスキップします。アップロードの順番を間違えてもサイト全体が停止することはありません。

各サイトの管理画面に更新の通知が表示されるまで、最大12時間かかります。「ダッシュボード」→「更新」の「再確認」を押すと、すぐに確認できます。

## ライセンス

GPLv2 or later
