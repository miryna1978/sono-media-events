<?php
/**
 * Plugin Name: sono media イベント
 * Plugin URI:  https://media.sono-music.com/
 * Description: sono media に掲載した自分のイベントの一覧を、ショートコード [sono_events] でサイトに表示します。イベントを追加・公開・非公開にすると自動で反映されます。
 * Version:     1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      sono
 * Author URI:  https://media.sono-music.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://media.sono-music.com/
 *
 * ★ Update URI を必ず残すこと。これが無いと、将来 wordpress.org に
 *   同名（sono-media-events）のプラグインを誰かが公開したとき、
 *   WordPress の更新チェックがそれを「このプラグインの更新」として案内し、
 *   ワンクリックで**別人のコードに上書きされる**。
 *   自前配布のプラグインで実際に起きている乗っ取りの経路。
 *
 * ──────────────────────────────────────────────────────────────────────────
 * これは **会員ご自身の WordPress サイト** に入れるプラグインです。
 * 音楽メディア media.sono-music.com 側の窓口は、テーマの
 * inc/setup-events-public-api.php（GET /wp-json/sono/v1/events/public）。
 * 作りは sonoTracks Discography プラグインと同じ型にそろえてある。
 *
 * 【設計の要】pull 型。
 *   WordPress が sono media の公開APIを**読みに行く**。sono media から
 *   WordPress へ投稿を書きに行く（push）ことはしない。イベントの状態
 *   （公開・非公開・日程）は sono media 側でしか変わらないので、正を1つに
 *   しておけば「公開したのに出ない／消したのに残る」が原理的に起きなくなる。
 *
 * ★ 取得は必ずサーバーサイド（wp_remote_get）で行い、transient に10分だけ
 *   キャッシュする。ブラウザから直接APIを叩かせない。
 *
 * ★ APIが落ちている・遅いときは、期限切れでも「最後に成功した表示」を出す。
 *   それも無ければ**何も出さない**。エラー文言を人様のサイトの本文に出さない。
 *
 * ★ 関数を裸で置かず final class の静的メソッドにまとめてある。配布先のサイトに
 *   別のプラグインやテーマが入っている前提なので、sono_ で始まる裸の関数を
 *   増やすと衝突しうる（メディアのテーマ側は sono_ の関数を大量に持っている）。
 * ──────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Sono_Media_Events' ) ) :

final class Sono_Media_Events {

	const VERSION = '1.1.0';

	/** 設定を1つの option にまとめる（増えたときに option が散らからないように） */
	const OPTION = 'sono_media_events_settings';

	/** WordPress のプラグイン一覧での識別子。zip を展開したフォルダ名と同じ */
	const PLUGIN_SLUG = 'sono-media-events';

	/** 取得結果の transient / last-good option の接頭辞 */
	const CACHE_PREFIX = 'sono_me_';

	/**
	 * キャッシュの世代。「表示を今すぐ更新する」で1つ進める。
	 *
	 * ★ 鍵が（ID × 件数 × ページ × 今後/過去）になり、総当たりでは追えない。
	 *   世代を鍵に混ぜ、1つ進めるだけで**全部を無効にする**。
	 * ★ 世代を混ぜるのは transient だけ。障害時の保険（last-good）まで
	 *   無効にすると、「更新」を押した直後に sono media が落ちていた場合に
	 *   何も出せなくなる。保険は世代に関係なく残す。
	 */
	const VERSION_OPTION = 'sono_media_events_cache_version';

	/** ページ送りのクエリ名（会員のサイトのURLに付く） */
	const PAGE_QUERY = 'sono_events_page';

	/**
	 * 受け付けるページ番号の上限。訪問者が投げてくる桁の大きい番号を
	 * 持ち回らないための柵。
	 */
	const MAX_PAGE = 1000;

	/** 10分。短いほど反映が速く、長いほどサイトが軽い */
	const CACHE_MINUTES = 10;

	/** 既定・最大の表示件数。API側の丸めと合わせてある */
	const DEFAULT_LIMIT = 12;
	const MAX_LIMIT     = 24;

	/** イベントが0件のときに表示する文の既定値（設定が未保存のとき） */
	const DEFAULT_EMPTY = '現在、表示できるイベントはありません。';

	/** 本番のホスト名。更新の確認で「入れてよいコードの出どころ」を決める */
	const HOST = 'media.sono-music.com';

	/**
	 * ID（slug）の形。
	 *
	 * ★ **メディアで users 投稿の slug が取りうる形と同じにすること。**
	 *   日本語の slug は `%e3%81%82…` のパーセントエンコードで保存されており、
	 *   `_` や `.` を含むものもある。ここを狭くすると、プロフィールは正常なのに
	 *   このプラグインでは弾かれる、という本人に理由の分からない不具合になる。
	 */
	const SLUG_PATTERN = '/^[a-z0-9%_.~-]{1,200}$/';

	/** 設定画面のフック名（管理画面用 CSS をこの画面だけに読み込むため） */
	private static $hook_suffix = '';

	public static function init() {
		add_shortcode( 'sono_events', array( __CLASS__, 'shortcode' ) );
		// ★ ヘッダーの Update URI のホスト名でフィルタ名が決まる（WordPress 5.8以降）。
		//   ここを実装しておくと、管理画面の「更新があります」に載る。
		add_filter( 'update_plugins_media.sono-music.com', array( __CLASS__, 'check_update' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_sono_media_events_flush', array( __CLASS__, 'handle_flush' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( __CLASS__, 'plugin_action_links' )
		);
	}

	/** 叩き先。開発・ステージングでは SONO_MEDIA_API_ORIGIN で差し替えられる */
	private static function origin() {
		$origin = defined( 'SONO_MEDIA_API_ORIGIN' ) ? (string) SONO_MEDIA_API_ORIGIN : 'https://' . self::HOST;
		return rtrim( $origin, '/' );
	}

	// ── 設定 ────────────────────────────────────────────────────────────

	/** 保存済みの sono media のプロフィール ID（slug）。未設定なら空文字。 */
	public static function get_slug() {
		$settings = get_option( self::OPTION, array() );
		return ( is_array( $settings ) && isset( $settings['slug'] ) ) ? (string) $settings['slug'] : '';
	}

	/**
	 * イベントが0件のときに表示する文。
	 *
	 * ★ 未設定（キーが無い）は既定値、空文字は「何も表示しない」。この2つを区別するため、
	 *   既定値のままのときは設定に保存しない。
	 */
	public static function get_empty_message() {
		$settings = get_option( self::OPTION, array() );
		if ( is_array( $settings ) && array_key_exists( 'empty', $settings ) && is_string( $settings['empty'] ) ) {
			return $settings['empty'];
		}
		return self::DEFAULT_EMPTY;
	}

	/** 設定に保存されている0件の文。未設定なら null（sanitize_settings で引き継ぐため） */
	private static function stored_empty_message() {
		$settings = get_option( self::OPTION, array() );
		if ( is_array( $settings ) && array_key_exists( 'empty', $settings ) && is_string( $settings['empty'] ) ) {
			return $settings['empty'];
		}
		return null;
	}

	/**
	 * 送信された入力から、保存する0件の文を決める。null は「保存しない（既定値）」。
	 *
	 * ★ 送信に項目が無いときは保存済みの値を引き継ぐ。既定値と同じ文は保存しない。
	 */
	private static function resolve_empty_message( $input ) {
		if ( is_array( $input ) && isset( $input['empty'] ) && is_string( $input['empty'] ) ) {
			$empty = sanitize_text_field( $input['empty'] );
			return self::DEFAULT_EMPTY !== $empty ? $empty : null;
		}
		return self::stored_empty_message();
	}

	/**
	 * 設定画面から変えられる見た目の項目。
	 *
	 * ★ 形（type）ごとに、入れてよい値を**許すものだけ**決めてある。
	 *   ここを通らない値は捨てて、CSS 側の既定にそのまま任せる。
	 *   入れた文字列は `<style>` の中に出るので、`}` や `</style>` の
	 *   混入を「除く」のではなく「そもそも通さない」方針にしている。
	 * ★ 変数名は assets/sono-media-events.css の var() と同じもの。
	 *   ずれると設定しても効かないので、README の表とも揃えること。
	 */
	private static function style_fields() {
		return array(
			'gap'           => array( 'label' => 'イベントどうしの間隔', 'var' => '--sono-events-gap', 'type' => 'length', 'placeholder' => '24px' ),
			'min'           => array( 'label' => '1枠の最小幅', 'var' => '--sono-events-min', 'type' => 'length', 'placeholder' => '220px' ),
			'radius'        => array( 'label' => '画像の角丸', 'var' => '--sono-events-radius', 'type' => 'length', 'placeholder' => '4px' ),
			'ratio'         => array( 'label' => '画像の縦横比', 'var' => '--sono-events-ratio', 'type' => 'ratio', 'placeholder' => '4 / 5' ),
			'link_color'    => array( 'label' => 'リンクの文字色', 'var' => '--sono-events-link-color', 'type' => 'color' ),
			'date_color'    => array( 'label' => '開催日の色', 'var' => '--sono-events-date-color', 'type' => 'color' ),
			'title_color'   => array( 'label' => 'タイトルの色', 'var' => '--sono-events-title-color', 'type' => 'color' ),
			'title_weight'  => array( 'label' => 'タイトルの太さ', 'var' => '--sono-events-title-weight', 'type' => 'weight' ),
			'title_size'    => array( 'label' => 'タイトルの大きさ', 'var' => '--sono-events-title-size', 'type' => 'length', 'placeholder' => '1em' ),
			'meta_color'    => array( 'label' => '会場の色', 'var' => '--sono-events-meta-color', 'type' => 'color' ),
			'meta_opacity'  => array( 'label' => '会場の薄さ', 'var' => '--sono-events-meta-opacity', 'type' => 'opacity', 'placeholder' => '0.8' ),
			'meta_size'     => array( 'label' => '開催日と会場の大きさ', 'var' => '--sono-events-meta-size', 'type' => 'length', 'placeholder' => '0.9em' ),
			'ticket_color'  => array( 'label' => 'チケットのリンクの色', 'var' => '--sono-events-ticket-color', 'type' => 'color' ),
			'pager_color'   => array( 'label' => 'ページ送りの文字色', 'var' => '--sono-events-pager-color', 'type' => 'color' ),
			'pager_current' => array( 'label' => '現在ページの下線の色', 'var' => '--sono-events-pager-current', 'type' => 'color' ),
		);
	}

	/** 保存済みの見た目の設定 */
	public static function get_style() {
		$settings = get_option( self::OPTION, array() );
		return ( is_array( $settings ) && isset( $settings['style'] ) && is_array( $settings['style'] ) )
			? $settings['style'] : array();
	}

	/**
	 * 見た目の値を1つ検証する。**通らなければ空文字**（＝設定なし・既定のまま）。
	 *
	 * @return string
	 */
	private static function sanitize_style_value( $type, $raw ) {
		// 文字列以外（配列など）は、変換して警告を出す前に捨てる
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}
		$v = trim( (string) $raw );
		if ( '' === $v ) {
			return '';
		}
		switch ( $type ) {
			case 'length':
				// 数値＋単位だけ。負の値は入れさせない（間隔や角丸に負は意味が無い）
				return preg_match( '/^[0-9]+(\.[0-9]+)?(px|rem|em|%)$/', $v ) ? $v : '';
			case 'ratio':
				// 4 / 5 や 16/9。空白の有無は問わないが、形はこれだけ
				return preg_match( '#^[0-9]+(\.[0-9]+)?\s*/\s*[0-9]+(\.[0-9]+)?$#', $v ) ? $v : '';
			case 'color':
				// ★ WordPress の関数に任せる。#fff / #ffffff 以外は null が返る
				$hex = sanitize_hex_color( $v );
				return is_string( $hex ) ? $hex : '';
			case 'opacity':
				// ★ **入力された文字列をそのまま返す。** (string)(float) で正規化すると、
				//   PHP 8 より前の float→文字列はロケイル依存で、他のプラグインが
				//   setlocale を呼んでいる環境では 0.8 が "0,8" になり、CSS が捨てる。
				return preg_match( '/^(0(\.[0-9]+)?|1(\.0+)?)$/', $v ) ? $v : '';
			case 'weight':
				$allowed = array( 'normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900' );
				return in_array( $v, $allowed, true ) ? $v : '';
		}
		return '';
	}

	public static function add_settings_page() {
		self::$hook_suffix = (string) add_options_page(
			'sono media イベント',
			'sono media イベント',
			'manage_options',
			'sono-media-events',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'sono_media_events',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array( 'slug' => '' ),
			)
		);
	}

	/**
	 * 入力から ID（slug）を取り出す。形が合わなければ空文字。
	 *
	 * ★ 入力欄には「プロフィールのURL」を貼っても「ID だけ」を入れても通るようにする。
	 *   ここを厳しくすると、URLをコピーして貼った人が理由の分からないまま弾かれる。
	 * ★ 日本語の ID は、ブラウザのアドレス欄からコピーすると `かーやん` のような
	 *   文字のまま、別の場所からだと `%e3%81%8b…` で届く。どちらも同じ形に直す。
	 */
	private static function extract_slug( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		// URL で貼られた場合は /users/{slug} の slug を取り出す
		if ( false !== strpos( $raw, '/' ) ) {
			$path = wp_parse_url( $raw, PHP_URL_PATH );
			$raw  = '';
			if ( is_string( $path ) ) {
				$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
				$at    = array_search( 'users', $parts, true );
				if ( false !== $at && isset( $parts[ $at + 1 ] ) ) {
					$raw = $parts[ $at + 1 ];
				} elseif ( ! empty( $parts ) ) {
					$raw = end( $parts );
				}
			}
		}

		return self::normalize_slug( $raw );
	}

	/** デコードしてから WordPress と同じ小文字のエンコードに戻し、形を確かめる */
	private static function normalize_slug( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || strlen( $raw ) > 300 ) {
			return '';
		}
		$slug = strtolower( rawurlencode( rawurldecode( $raw ) ) );
		return preg_match( self::SLUG_PATTERN, $slug ) ? $slug : '';
	}

	/**
	 * 設定の検証。
	 *
	 * ★ 設定画面はタブごとにフォームが別なので、送信に含まれない項目がある
	 *   （「見た目」タブの保存には ID と0件の文が無い。その逆も同じ）。
	 *   含まれない項目は**保存済みの値をそのまま引き継ぐ**。引き継がないと、
	 *   別のタブの保存で ID や見た目が消える。
	 */
	public static function sanitize_settings( $input ) {
		// ★ 見た目は、送信に見た目の項目があるときだけ作り直す。無いときは保存済みを引き継ぐ。
		$style = self::get_style();
		if ( is_array( $input ) && isset( $input['style'] ) && is_array( $input['style'] ) ) {
			$style = self::build_style( $input );
		}

		// 0件の文。送られていないときは保存済みの値を引き継ぐ（resolve_empty_message が行う）
		$empty = self::resolve_empty_message( $input );

		// ★ ID の項目が無い送信（「見た目」タブの保存）では、保存済みの ID を引き継ぐ。
		//   空にして連携をやめたことにしない。
		if ( ! is_array( $input ) || ! isset( $input['slug'] ) || ! is_string( $input['slug'] ) ) {
			return self::build_result( self::get_slug(), $style, $empty );
		}

		// ★ 文字列であることを先に確かめる。配列を送られると (string) が
		//   PHP の警告を出したうえで "Array" になり、小文字化した "array" が
		//   ID の形に合ってしまう（そのまま保存される）。
		$raw  = trim( $input['slug'] );
		$slug = self::extract_slug( $raw );

		if ( '' !== $raw && '' === $slug ) {
			// ★ add_settings_error() は管理画面でしか読み込まれない。通常この
			//   コールバックは options.php からしか呼ばれないが、**万一それ以外から
			//   呼ばれたときに「関数が無い」で致命的エラーにしない**。
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'sono_media_events_slug',
					'プロフィールのURLの形式が正しくありません。sono media にログインし、アカウント画面（https://media.sono-music.com/account/）の「プロフィールのURLをコピー」ボタンでコピーしたURLを貼り付けてください。',
					'error'
				);
			}
			// ★ 不正な入力で既存の設定を消さない（表示が突然消えるのを避ける）。
			//   ID だけ保存済みの値に戻し、見た目と0件の文は同じ送信の内容を採用する。
			return self::build_result( self::get_slug(), $style, $empty );
		}

		// ★ ID を変えたら、前の ID で取った表示が残らないようキャッシュを捨てる
		if ( $slug !== self::get_slug() ) {
			self::flush_cache();
		}

		return self::build_result( $slug, $style, $empty );
	}

	/** 保存する配列を組み立てる。0件の文が null のときは保存しない（＝既定値） */
	private static function build_result( $slug, $style, $empty ) {
		$result = array(
			'slug'  => $slug,
			'style' => $style,
		);
		if ( null !== $empty ) {
			$result['empty'] = $empty;
		}
		return $result;
	}

	/**
	 * 送信された見た目の入力を検証して、保存する形にする。
	 * 通らなかった項目は警告で知らせる（黙って捨てない）。
	 */
	private static function build_style( $input ) {
		$style     = array();
		$raw_style = $input['style'];
		$dropped   = array();
		// 色は「指定する」に印を付けたものだけを使う。色の入力欄は空にできないため
		// （空欄＝テーマに任せる、を別の印で表す）
		$use = ( isset( $input['style_use'] ) && is_array( $input['style_use'] ) )
			? $input['style_use'] : array();
		foreach ( self::style_fields() as $key => $field ) {
			if ( 'color' === $field['type'] && ! isset( $use[ $key ] ) ) {
				continue; // 指定しない＝テーマに任せる
			}
			$given = isset( $raw_style[ $key ] ) ? $raw_style[ $key ] : '';
			$value = self::sanitize_style_value( $field['type'], $given );
			if ( '' !== $value ) {
				$style[ $key ] = $value;
			} elseif ( ( is_string( $given ) || is_numeric( $given ) ) && '' !== trim( (string) $given ) ) {
				// ★ 黙って捨てない。入れたのに効かない理由が分からないのが一番困る
				$dropped[] = $field['label'];
			}
		}
		if ( $dropped && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'sono_media_events_style',
				'次の項目は入力形式が正しくないため、保存されませんでした: ' . implode( '、', $dropped ),
				'warning'
			);
		}
		return $style;
	}

	/** 設定画面のタブ。値はこの一覧に無いものを受け付けない（既定は先頭） */
	private static function tabs() {
		return array(
			'settings' => '設定',
			'style'    => '見た目',
			'usage'    => '使い方',
			'related'  => '関連サービス',
		);
	}

	/** 表示するタブ。クエリの値はホワイトリストで検証し、外れたら既定のタブに倒す */
	private static function current_tab() {
		$tab = ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'settings';
	}

	/** 設定画面の URL（タブ指定つき） */
	private static function settings_url( $tab = 'settings' ) {
		return admin_url( 'options-general.php?page=sono-media-events&tab=' . rawurlencode( $tab ) );
	}

	/** この設定画面を開いたときだけ、管理画面用の CSS を読み込む */
	public static function enqueue_admin_assets( $hook ) {
		if ( '' === self::$hook_suffix || $hook !== self::$hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'sono-media-events-admin',
			plugins_url( 'assets/sono-media-events-admin.css', __FILE__ ),
			array(),
			self::VERSION
		);
		// 色を選んだら「この色を指定する」にチェックを入れる（チェックし忘れて、選んだ色が
		// 保存されないのを防ぐ）。JavaScript が動かない環境では、従来どおり手でチェックする。
		wp_add_inline_script(
			'common',
			"document.addEventListener('input',function(e){var t=e.target;if(!t||t.type!=='color'||!t.closest('.sono-events-admin'))return;var c=t.parentNode.querySelector('input[type=checkbox]');if(c){c.checked=true;}});"
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = self::current_tab();
		?>
		<div class="wrap sono-events-admin">
			<h1>sono media イベント</h1>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="設定画面のタブ">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::settings_url( $key ) ); ?>"
						class="nav-tab<?php echo $key === $current ? ' nav-tab-active' : ''; ?>"
						<?php echo $key === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'style' === $current ) {
				self::render_tab_style();
			} elseif ( 'usage' === $current ) {
				self::render_tab_usage();
			} elseif ( 'related' === $current ) {
				self::render_tab_related();
			} else {
				self::render_tab_settings();
			}
			?>
		</div>
		<?php
	}

	/** 「設定」タブ */
	private static function render_tab_settings() {
		$slug = self::get_slug();
		?>
		<p>
			sono media に掲載されたイベントの一覧を、このサイトに表示します。
			sono media でイベントを追加したり、公開・非公開を切り替えたりすると、<?php echo esc_html( (string) self::CACHE_MINUTES ); ?>分以内にこのサイトの表示にも反映されます。
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'sono_media_events' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sono-events-slug">sono media のプロフィール</label>
					</th>
					<td>
						<input
							type="text"
							id="sono-events-slug"
							name="<?php echo esc_attr( self::OPTION ); ?>[slug]"
							value="<?php echo esc_attr( rawurldecode( $slug ) ); ?>"
							class="regular-text"
							autocomplete="off"
							aria-describedby="sono-events-slug-help"
						/>
						<p class="description" id="sono-events-slug-help">
							sono media にログインし、<a href="https://media.sono-music.com/account/" target="_blank" rel="noopener">アカウント画面</a>の
							「プロフィールのURLをコピー」ボタンを押して、コピーしたURLを貼り付けてください。
							URL は <code>https://media.sono-music.com/users/○○○</code> の形式です。末尾の <code>○○○</code> の部分だけを入力しても設定できます。
						</p>
						<div class="sono-events-admin__status">
							<?php self::render_status( $slug ); ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sono-events-empty">イベントがないときに表示する文</label>
					</th>
					<td>
						<input
							type="text"
							id="sono-events-empty"
							name="<?php echo esc_attr( self::OPTION ); ?>[empty]"
							value="<?php echo esc_attr( self::get_empty_message() ); ?>"
							class="large-text"
							autocomplete="off"
							aria-describedby="sono-events-empty-help"
						/>
						<p class="description" id="sono-events-empty-help">
							表示するイベントがないときに、一覧の代わりに表示する文です。空欄にすると、何も表示しません。
							sono media に接続できないときは表示されません。
							ショートコードの <code>empty</code> 属性で、一覧ごとに変更することもできます。
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php self::render_flush_form(); ?>
		<?php
	}

	/** 「見た目」タブ */
	private static function render_tab_style() {
		$style = self::get_style();
		?>
		<p>
			変更したい項目だけを入力してください。空欄の項目には、お使いのテーマのデザインが適用されます。
			色は「この色を指定する」にチェックを入れた項目だけが適用されます。
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'sono_media_events' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( self::style_fields() as $key => $field ) : ?>
					<?php
					$id    = 'sono-events-style-' . str_replace( '_', '-', $key );
					$value = isset( $style[ $key ] ) ? $style[ $key ] : '';
					$name  = self::OPTION . '[style][' . $key . ']';
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<?php if ( 'color' === $field['type'] ) : ?>
								<?php
								// ★ 色は自由入力にせず選ばせる（書き方の間違いが起こらない）。
								//   使うかどうかは別の印で表す——色の入力欄は「空」にできないため。
								// ★ **入力欄を disabled にしない。** disabled の欄は送信されず、
								//   JavaScript が動かない環境で印を付けても何も起きない。
								//   使うかどうかはサーバー側が印で判断しているので、常に送ってよい。
								// ★ 印を先に置く。操作できるものより先に、操作の意味を読ませる。
								// ★ 未指定のときの色の見本は、WordPress 管理画面の文字色にする。
								//   実際の既定はテーマの色（inherit）で、ここからは分からないため。
								?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[style_use][' . $key . ']' ); ?>"
										value="1" <?php checked( '' !== $value ); ?> />
									この色を指定する
									<?php // 同じ文言の印が並ぶので、読み上げには項目名を添える ?>
									<span class="screen-reader-text">（<?php echo esc_html( $field['label'] ); ?>）</span>
								</label>
								<input type="color" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
									value="<?php echo esc_attr( '' !== $value ? $value : ( isset( $field['swatch'] ) ? $field['swatch'] : '#1d2327' ) ); ?>" />
							<?php elseif ( 'weight' === $field['type'] ) : ?>
								<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
									<option value="">テーマの設定に従う</option>
									<?php foreach ( array( 'normal' => '標準', 'bold' => '太字', '600' => 'やや太字（600）' ) as $v => $l ) : ?>
										<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $value, $v ); ?>><?php echo esc_html( $l ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
									value="<?php echo esc_attr( $value ); ?>" class="small-text"
									placeholder="<?php echo esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ); ?>"
									aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>" />
								<span class="description" id="<?php echo esc_attr( $id . '-help' ); ?>">
									<?php
									echo esc_html(
										'ratio' === $field['type'] ? '例: 4 / 5、1 / 1、16 / 9'
											: ( 'opacity' === $field['type'] ? '0〜1（例: 0.8）'
												: '単位付きの数値（例: ' . ( isset( $field['placeholder'] ) ? $field['placeholder'] : '16px' ) . '）' )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/** 「使い方」タブ */
	private static function render_tab_usage() {
		$rows = array(
			array(
				'開催予定のイベントを、開催日の近い順に表示する',
				'[sono_events]',
				'',
			),
			array(
				'表示件数と列数を指定する',
				'[sono_events limit="8" columns="3"]',
				'表示件数は最大 ' . self::MAX_LIMIT . '件です。',
			),
			array(
				'すべてのイベントをページ送りで表示する',
				'[sono_events paged="true"]',
				'ページ送りを使う一覧は、1つのページに1つだけ配置してください。同じページに2つ配置すると、片方のページ送りを操作したときに両方の一覧が切り替わります。',
			),
			array(
				'終了したイベントを新しい順に表示する',
				'[sono_events past="true" paged="true"]',
				'',
			),
			array(
				'別のプロフィールのイベントを表示する（レーベルのサイトなど）',
				'[sono_events slug="○○○"]',
				'そのプロフィールのURLの末尾（/users/ 以降の部分）を slug に指定します。',
			),
			array(
				'ご自身が投稿したイベントだけを表示する',
				'[sono_events related="false"]',
				'related="false" を指定すると、関連ユーザーとして登録されたイベントを表示しません。',
			),
			array(
				'イベントがないときの文を、一覧ごとに変更する',
				'[sono_events empty="今後のイベントは準備中です。"]',
				'empty="" と指定すると、イベントがないときに何も表示しません。',
			),
		);
		?>
		<p>投稿または固定ページに、次の表のショートコードを入力してください。</p>
		<div class="sono-events-admin__table">
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col">用途</th>
					<th scope="col">ショートコード</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $row[0] ); ?>
							<?php if ( '' !== $row[2] ) : ?>
								<br /><span class="description"><?php echo esc_html( $row[2] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="sono-events-admin__code"><code><?php echo esc_html( $row[1] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p class="description">
			すべてのオプションと表示内容の詳細は、<a href="https://github.com/miryna1978/sono-media-events#readme" target="_blank" rel="noopener">README（GitHub）</a>を参照してください。
		</p>
		<?php
	}

	/**
	 * 「関連サービス」タブ。同じ運営の配布プラグインの紹介。
	 *
	 * ★ 外部への通信・計測・画像は使わない。固定の文とリンクだけを出す。
	 * ★ そのプラグインがこのサイトで有効なら、紹介ページではなく設定画面へ案内する。
	 */
	private static function render_tab_related() {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$services = array(
			array(
				'name'    => 'sonoTracks Discography',
				'desc'    => 'sonoTracks（音源の直販サービス）で販売している作品の一覧を、このサイトに表示するプラグインです。楽曲を制作して販売している DJ やレーベルのサイトで使えます。',
				'file'    => 'sonotracks-discography/sonotracks-discography.php',
				'page'    => 'sonotracks-discography',
				'url'     => 'https://sono-tracks.com/wordpress',
				'label'   => '配布ページを開く',
			),
			array(
				'name'    => 'sonoLoop 応援ボタン',
				'desc'    => 'sonoLoop（ファンがアーティストに応援のチップを贈れるサービス）の応援ページへの導線を表示するプラグインです。サイトに来たファンが、その場で応援ページを開けます。パソコンでは QR コード、スマートフォンではボタンで表示されます。',
				'file'    => 'sonoloop-support/sonoloop-support.php',
				'page'    => 'sonoloop-support',
				'url'     => 'https://sono-loop.com/wordpress',
				'label'   => '配布ページを開く',
			),
		);
		?>
		<p>sono が配布しているプラグインです。このサイトで併用できます。</p>
		<?php foreach ( $services as $service ) : ?>
			<?php $active = function_exists( 'is_plugin_active' ) && is_plugin_active( $service['file'] ); ?>
			<div class="card sono-events-admin__card">
				<h2><?php echo esc_html( $service['name'] ); ?></h2>
				<p><?php echo esc_html( $service['desc'] ); ?></p>
				<p>
					<?php if ( $active ) : ?>
						<span class="sono-events-admin__active">有効化済み</span>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . $service['page'] ) ); ?>">設定画面を開く</a>
					<?php else : ?>
						<a class="button button-secondary" href="<?php echo esc_url( $service['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $service['label'] ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * いま何件取得できているかを設定画面に出す。
	 *
	 * ★ これが無いと、ID を打ち間違えた人は「保存はできたのに何も出ない」
	 *   状態から抜け出せない。設定した直後にその場で分かるようにしておく。
	 *
	 * ★ **ここだけキャッシュを通さず直接取りに行く。** キャッシュ越しだと、
	 *   通信できなかった場合と ID が見つからない場合がどちらも「空」になり、
	 *   API が一時的に落ちているだけの人に「ID が違います」と言ってしまう。
	 *   管理画面の1回きりの取得なので、都度取りに行って構わない。
	 */
	private static function render_status( $slug ) {
		if ( '' === $slug ) {
			echo '<div class="notice notice-info inline"><p>プロフィールを設定すると、取得できたイベントの件数がここに表示されます。</p></div>';
			return;
		}

		// ★ 1ページ目だけ取れば足りる。API が総件数も返すので、
		//   何件あるかを言うために全部を取りに行く必要は無い
		$fetched = self::fetch( $slug, self::DEFAULT_LIMIT, 1, false );

		if ( null === $fetched ) {
			echo '<div class="notice notice-warning inline"><p>sono media に接続できませんでした。プロフィールの設定が誤っているとは限りません。しばらく時間をおいてから、このページを再読み込みしてください。</p></div>';
			self::render_flush_button();
			return;
		}

		if ( empty( $fetched['profileUrl'] ) ) {
			echo '<div class="notice notice-warning inline"><p>プロフィールが見つかりませんでした。sono media のアカウント画面の「プロフィールのURLをコピー」ボタンでURLをコピーし直して、貼り付けてください。</p></div>';
			self::render_flush_button();
			return;
		}

		$upcoming = (int) $fetched['upcomingTotal'];
		$past     = (int) $fetched['pastTotal'];

		if ( $upcoming + $past > 0 ) {
			$notes = array();
			if ( $upcoming > self::DEFAULT_LIMIT ) {
				$notes[] = sprintf( '初期設定では%d件まで表示されます。すべて表示するには paged="true" を指定してください。', self::DEFAULT_LIMIT );
			}
			if ( 0 === $upcoming ) {
				$notes[] = '開催予定のイベントはありません。終了したイベントを表示するには past="true" を指定してください。';
			}
			printf(
				'<div class="notice notice-success inline"><p>%s件のイベントを取得しました（開催予定 %s件、終了 %s件）。%s<a href="%s" target="_blank" rel="noopener">プロフィールを開く</a></p></div>',
				esc_html( (string) ( $upcoming + $past ) ),
				esc_html( (string) $upcoming ),
				esc_html( (string) $past ),
				// 注記があるときだけ、後ろに空白を足す（無いのに空白が余らないように）
				$notes ? esc_html( implode( '', $notes ) ) . ' ' : '',
				esc_url( $fetched['profileUrl'] )
			);
			echo '<p class="description">関連ユーザーに登録されたイベントは、標準で一覧に表示されます。表示しない場合は、sono media のアカウント画面のイベントタブにある「関連ユーザーに登録されたイベント」で非表示に設定できます。</p>';
			self::render_flush_button();
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>プロフィールは見つかりましたが、表示できるイベントがありません。sono media にイベントが掲載されると表示されます。 <a href="%s" target="_blank" rel="noopener">プロフィールを開く</a></p></div>',
			esc_url( $fetched['profileUrl'] )
		);
		self::render_flush_button();
	}

	/**
	 * 「表示を今すぐ更新する」。
	 *
	 * ★ リンク（a）ではなくボタン（form + POST）にする。押すとサーバー側の
	 *   キャッシュを消すという**副作用のある操作**で、行き先を開くものではない。
	 *   リンクにすると、先読みや履歴の戻りで意図せず走りうる。
	 */
	private static function render_flush_button() {
		// フォームの外にある render_flush_form の form を、form 属性で参照する
		echo '<p><button type="submit" form="sono-events-flush-form" class="button button-secondary">表示を今すぐ更新する</button></p>';
	}

	/**
	 * 「表示を今すぐ更新する」の送信先のフォーム（見える部品は無い）。
	 * ★ 設定の form の外に置く（form は入れ子にできない）。押すボタンは設定の中にあり、
	 *   button の form 属性でこの form を指す。
	 */
	private static function render_flush_form() {
		?>
		<form id="sono-events-flush-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sono_media_events_flush" />
			<?php wp_nonce_field( 'sono_media_events_flush' ); ?>
		</form>
		<?php
	}

	/** プラグイン一覧から設定画面へ */
	public static function plugin_action_links( $links ) {
		$url = self::settings_url( 'settings' );
		array_unshift( $links, sprintf( '<a href="%s">設定</a>', esc_url( $url ) ) );
		return $links;
	}

	/** 「今すぐ再取得する」 */
	public static function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作を行う権限がありません。' );
		}
		check_admin_referer( 'sono_media_events_flush' );
		self::flush_cache();
		wp_safe_redirect( self::settings_url( 'settings' ) );
		exit;
	}

	// ── 更新の確認 ──────────────────────────────────────────────────────

	/**
	 * 新しい版があるかを WordPress に答える（ヘッダーの Update URI と対）。
	 *
	 * ★ **wordpress.org は経由しない。** Update URI があるプラグインを WP は
	 *   wordpress.org に問い合わせないので、同名のプラグインが公開されても
	 *   別人のコードに差し替えられることがない。
	 *
	 * ★ **入れるコードの出どころを二重に確かめる。** WP は返した `package` の
	 *   URL からファイルを取って展開する。ここが差し替わると、会員の
	 *   サイトに任意のコードが入る。API 側も自分のURLしか返さないが、
	 *   受け取る側でも「https で、media.sono-music.com のURLか」を確かめてから渡す。
	 *   確かめられなければ**更新を出さない**（黙って何もしないほうが安全）。
	 *
	 * @param array|false $update      他のフィルタが既に返した内容
	 * @param array       $plugin_data このプラグインのヘッダー
	 * @param string      $plugin_file プラグインのファイル（例 sono-media-events/sono-media-events.php）
	 * @return array|false
	 */
	public static function check_update( $update, $plugin_data, $plugin_file ) {
		// ★ 同じ Update URI を持つ別のプラグインの問い合わせが来ることがある。
		//   自分の分でなければ、渡されたものをそのまま返す
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $update;
		}

		$info = self::fetch_update_info();
		if ( ! is_array( $info ) ) {
			// 取れなかったときだけ、渡されたものをそのまま返す（＝何も言わない）
			return $update;
		}

		// ★ **版が同じでも配列を返す。** 新旧の比較は WordPress 側が行い
		//   （wp-includes/update.php の version_compare）、新しければ response、
		//   そうでなければ no_update に振り分ける。ここで false を返すと
		//   どちらにも載らず、プラグイン一覧で「自動更新を有効化」が
		//   **更新が出ている間しか現れない**状態になる。
		return array(
			'id'           => self::HOST . '/' . self::PLUGIN_SLUG,
			'slug'         => self::PLUGIN_SLUG,
			'version'      => $info['version'],
			'url'          => $info['url'],
			'package'      => $info['package'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
		);
	}

	/**
	 * 更新情報を取る（12時間キャッシュ）。
	 *
	 * ★ WP は管理画面を開くたびに更新の確認を回しうる。毎回 sono media へ
	 *   問い合わせると、相手のサイトの管理画面が遅くなる。長めに持たせる。
	 * ★ 取れなかったときも1時間だけ「取れなかった」を覚える。覚えないと、
	 *   届かない間ずっと毎回タイムアウトを待つ。
	 */
	private static function fetch_update_info() {
		$key = self::CACHE_PREFIX . 'update';

		// ★ 「ダッシュボード → 更新 → もう一度確認する」を押したときは、
		//   キャッシュを見ずに取りに行く（押しても12時間ぶんの古い答えが
		//   返ると、確認したのに何も起きない）。WordPress はこの操作を
		//   $_GET['force-check'] で伝える（update-core.php）。
		// ★ 権限を確かめる。確かめないと、front から ?force-check=1 を付けて
		//   叩くだけで、誰でもこのサイトから sono media への問い合わせを
		//   何度でも起こせてしまう。
		$forced = is_admin()
			&& ! empty( $_GET['force-check'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& current_user_can( 'update_plugins' );

		$cached = $forced ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			// ★ **取り出すときにも確かめる。** 検証は取り込む時だけ、にすると、
			//   option を書ける別の経路（他のプラグインの穴など）で
			//   package を差し替えられたときに素通しになる。1行で塞げる。
			return self::is_valid_update_info( $cached ) ? $cached : null;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$response = wp_remote_get(
			self::origin() . '/wp-json/sono/v1/events-plugin-update',
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		$body = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && self::is_valid_update_info( $decoded ) ) {
				$body = $decoded;
			}
		}

		if ( null === $body ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $key, $body, 12 * HOUR_IN_SECONDS );
		return $body;
	}

	/**
	 * 更新情報として受け取ってよい形か。
	 * ★ **とくに package。** ここを緩めると、任意のURLからコードを入れる道になる。
	 */
	private static function is_valid_update_info( $info ) {
		foreach ( array( 'version', 'url', 'package' ) as $k ) {
			if ( ! isset( $info[ $k ] ) || ! is_string( $info[ $k ] ) || '' === $info[ $k ] ) {
				return false;
			}
		}
		// 版は数字と点だけ（1.2.3 / 1.2 など）
		if ( ! preg_match( '/^[0-9]+(\.[0-9]+){1,3}$/', $info['version'] ) ) {
			return false;
		}
		return self::is_own_https_url( $info['package'] ) && self::is_own_https_url( $info['url'] );
	}

	/**
	 * sono media 自身の https のURLか。
	 *
	 * ★ **開発用の差し替えのために、確認をまとめて緩めない。** SONO_MEDIA_API_ORIGIN が
	 *   定義されていると scheme の確認ごと飛ばす形だと、検証環境を指すために定数を
	 *   置いた本番サイトで `http://media.sono-music.com/...` まで通ってしまい、
	 *   経路の途中ですり替えられる余地ができる。
	 *   ここは「本番の https」か「差し替え先とそっくり同じ入口（scheme・ホスト・
	 *   ポートすべて一致）」のどちらかだけを許す。
	 */
	private static function is_own_https_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		// ★ 利用者情報（user:pass@）が付いたURLは受け取らない。読み手を
		//   惑わせる形（https://media.sono-music.com@evil.com/）の入口を、
		//   ホスト名の判定に頼る前に閉じておく。
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		// 0＝指定なし＝https の既定（443）
		if ( 'https' === $scheme && self::HOST === $host && 0 === $port ) {
			return true;
		}

		if ( defined( 'SONO_MEDIA_API_ORIGIN' ) ) {
			$dev = wp_parse_url( (string) SONO_MEDIA_API_ORIGIN );
			if ( is_array( $dev ) && ! empty( $dev['host'] ) && ! empty( $dev['scheme'] ) ) {
				$dev_port = isset( $dev['port'] ) ? (int) $dev['port'] : 0;
				if ( $scheme === strtolower( $dev['scheme'] )
					&& $host === strtolower( $dev['host'] )
					&& $port === $dev_port ) {
					return true;
				}
			}
		}

		return false;
	}

	// ── 表示 ────────────────────────────────────────────────────────────

	/**
	 * 見た目の設定（設定画面で入れた値）を、CSS の変数として書き出す。
	 *
	 * ★ **`wp_add_inline_style` で本体の CSS にぶら下げる。** 直接 wp_head に
	 *   echo すると、ショートコードを置いていないページにも出てしまい、
	 *   本体の CSS との前後関係も保証できない。この形なら、一覧を出す
	 *   ページでだけ、必ず本体の直後に置かれる。
	 *
	 * ★ ここは**利用者が入れた文字列を `<style>` の中へ出す**場所。
	 *   検証を通っていない値は1つも書かない。
	 */
	private static function inline_style() {
		$style = self::get_style();
		$decls = array();
		foreach ( self::style_fields() as $key => $field ) {
			// ★ **出すときにも検証を通す。** 保存時の検証は管理画面の保存経路
			//   でしか走らない。WP-CLI・cron・移行ツール・他のプラグインの
			//   update_option から書かれた値は、検証を経ずにここへ来る。
			$value = self::sanitize_style_value(
				$field['type'],
				isset( $style[ $key ] ) ? $style[ $key ] : ''
			);
			if ( '' !== $value ) {
				$decls[] = $field['var'] . ':' . $value;
			}
		}
		return $decls ? '.sono-events{' . implode( ';', $decls ) . '}' : '';
	}

	public static function register_assets() {
		wp_register_style(
			'sono-media-events',
			plugins_url( 'assets/sono-media-events.css', __FILE__ ),
			array(),
			self::VERSION
		);
		$inline = self::inline_style();
		if ( '' !== $inline ) {
			wp_add_inline_style( 'sono-media-events', $inline );
		}

		// ★ **本文にショートコードがあるなら、ここで読み込む＝head に入る。**
		//   ショートコードの中で読み込むと、WordPress は既に head を出し終えて
		//   いるので footer へ回され、表示が一瞬崩れて見えることがある。
		// ★ ここで見つけられるのは本文に直接書かれた場合だけ。ウィジェットや
		//   テンプレートに置いた場合は見つからないが、**ショートコード側の
		//   読み込みが残っているので表示は崩れない**（footer になるだけ）。
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'sono_events' ) ) {
				wp_enqueue_style( 'sono-media-events' );
			}
		}
	}

	/**
	 * ショートコード本体。
	 *
	 * @param array $atts slug / limit / columns / paged / past / related / empty
	 * @return string HTML。出せるものが何も無ければ空文字。
	 */
	public static function shortcode( $atts ) {
		// ★ empty は「属性が無い（設定画面の値）」と「空文字（何も表示しない）」を区別する
		$has_empty = is_array( $atts ) && array_key_exists( 'empty', $atts );

		$atts = shortcode_atts(
			array(
				'slug'    => '',
				'limit'   => self::DEFAULT_LIMIT,
				'columns' => 3,
				// ページ送りを出すか。既定は出さない（1ページだけ）
				'paged'   => 'false',
				// 終わったイベントを出すか。既定は今後のイベント
				'past'    => 'false',
				// 関連ユーザーとして登録されたイベントを含めるか。既定は含める
				'related' => 'true',
				// イベントが0件のときの文。属性が無ければ設定画面の値
				'empty'   => '',
			),
			$atts,
			'sono_events'
		);

		$slug = '' !== $atts['slug'] ? self::normalize_slug( (string) $atts['slug'] ) : self::get_slug();
		if ( '' === $slug ) {
			return '';
		}

		$limit   = self::clamp( (int) $atts['limit'], self::DEFAULT_LIMIT, 1, self::MAX_LIMIT );
		// 列数はレイアウトの都合。1〜6の範囲に収める
		$columns = self::clamp( (int) $atts['columns'], 3, 1, 6 );
		$paged   = self::truthy( $atts['paged'] );
		$past    = self::truthy( $atts['past'] );
		// ★ 'false' / '0' / 'no' / 'off' のときだけ除外する。それ以外（空・不正な値）は既定の「含める」
		$related = ! self::falsy( $atts['related'] );

		// ★ ページ番号は会員のサイトのURL（?sono_events_page=2）から読む。
		//   ページ送りを出さない指定のときは読まない——同じページに複数の一覧を
		//   置いたときに、片方の操作でもう片方まで動いてしまうため。
		$page = 1;
		if ( $paged && isset( $_GET[ self::PAGE_QUERY ] ) ) {
			$page = max( 1, (int) $_GET[ self::PAGE_QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$data = self::get_data( $slug, $limit, $page, $past, $related );
		if ( empty( $data['events'] ) ) {
			// ★ 「0件と確かめられた」ときだけ文を出す。API が成功してプロフィールが実在し
			//   （profileUrl あり）、イベントが空のとき。接続できず控えも無いとき、
			//   プロフィールが見つからないとき（missing）は、「無い」と言い切れないので、
			//   またサイトの訪問者に設定の誤りを見せないために、静かに何も出さない。
			if ( ! empty( $data['profileUrl'] ) && empty( $data['missing'] ) ) {
				$message = $has_empty ? trim( (string) $atts['empty'] ) : self::get_empty_message();
				if ( '' !== $message ) {
					wp_enqueue_style( 'sono-media-events' );
					return '<div class="sono-events"><p class="sono-events__empty">' . esc_html( $message ) . '</p></div>';
				}
			}
			return '';
		}

		return self::render_grid( $data, $columns, $paged );
	}

	private static function truthy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'true', '1', 'yes', 'on' ), true );
	}

	private static function falsy( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( 'false', '0', 'no', 'off' ), true );
	}

	private static function render_grid( $data, $columns, $paged = false ) {
		// ★ 遅い enqueue でもWPが footer に回してくれる。ショートコードが
		//   使われたページでだけ読み込ませたいので、ここで呼ぶ
		wp_enqueue_style( 'sono-media-events' );

		$profile_url = $data['profileUrl'];

		ob_start();
		?>
		<div class="sono-events">
			<ul class="sono-events__list" style="--sono-events-columns: <?php echo esc_attr( (string) $columns ); ?>;">
				<?php foreach ( $data['events'] as $event ) : ?>
					<li class="sono-events__item">
						<?php if ( ! empty( $event['image']['url'] ) ) : ?>
							<?php
							// ★ 画像のリンクは、すぐ下のタイトルのリンクと行き先が同じ。
							//   読み上げとキーボードに同じリンクを二度渡さないよう外す。
							//   alt も空（タイトルのテキストが下にあるため）。
							?>
							<a class="sono-events__imagelink" href="<?php echo esc_url( $event['url'] ); ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">
								<img
									class="sono-events__image"
									src="<?php echo esc_url( $event['image']['url'] ); ?>"
									<?php if ( $event['image']['width'] > 0 && $event['image']['height'] > 0 ) : ?>
										width="<?php echo esc_attr( (string) $event['image']['width'] ); ?>"
										height="<?php echo esc_attr( (string) $event['image']['height'] ); ?>"
									<?php endif; ?>
									alt=""
									loading="lazy"
									decoding="async"
								/>
							</a>
						<?php endif; ?>

						<?php echo self::render_dates( $event['dates'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中で esc 済み ?>

						<p class="sono-events__title">
							<a href="<?php echo esc_url( $event['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $event['title'] ); ?></a>
						</p>

						<?php if ( '' !== $event['placeName'] ) : ?>
							<p class="sono-events__place"><?php echo esc_html( $event['placeName'] ); ?></p>
						<?php endif; ?>

						<p class="sono-events__links">
							<?php if ( '' !== $event['ticketUrl'] ) : ?>
								<a class="sono-events__ticket" href="<?php echo esc_url( $event['ticketUrl'] ); ?>" target="_blank" rel="noopener">チケット</a>
							<?php endif; ?>
							<a class="sono-events__detail" href="<?php echo esc_url( $event['url'] ); ?>" target="_blank" rel="noopener">詳細を見る</a>
						</p>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			if ( $paged ) {
				echo self::render_pager( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中で esc 済み
			}
			?>
			<?php if ( $profile_url ) : ?>
				<p class="sono-events__more">
					<a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener">sono media ですべて見る</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * 開催日。複数の日程はすべて並べる（多いときは3つまで出して残りは件数で示す）。
	 *
	 * 例: 2026.10.24（土）18:00〜
	 *     2026.10.24（土）〜2026.10.26（月）（24時間以上にわたる催し）
	 *
	 * ★ 日付は API が返した時差付きの値の**そのままの時刻**で出す。
	 *   読み手のサイトのタイムゾーン設定（UTC のままのサイトが多い）で
	 *   変換すると、日本の夜のイベントが前日になってしまう。
	 * ★ 時刻が 00:00 で終了も無いものは、時刻を出さない。日付だけが入力された
	 *   イベントは 00:00 として届くため、「00:00〜」と出すと偽の開始時刻になる。
	 */
	private static function render_dates( $dates ) {
		if ( empty( $dates ) ) {
			return '';
		}

		$max   = 3;
		$shown = array_slice( $dates, 0, $max );
		$rest  = count( $dates ) - count( $shown );

		ob_start();
		?>
		<div class="sono-events__dates">
			<?php foreach ( $shown as $date ) : ?>
				<?php $label = self::format_date( $date ); ?>
				<?php if ( null !== $label ) : ?>
					<p class="sono-events__date"><time datetime="<?php echo esc_attr( $date['start'] ); ?>"><?php echo esc_html( $label ); ?></time></p>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( $rest > 0 ) : ?>
				<p class="sono-events__datemore"><?php echo esc_html( sprintf( 'ほか%d日程', $rest ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** 日程1つぶんの表示。読めなければ null */
	private static function format_date( $date ) {
		try {
			$start = new DateTimeImmutable( $date['start'] );
			$end   = ! empty( $date['end'] ) ? new DateTimeImmutable( $date['end'] ) : null;
		} catch ( Exception $e ) {
			return null;
		}

		$days = array( '日', '月', '火', '水', '木', '金', '土' );
		$ymd  = static function ( DateTimeImmutable $d ) use ( $days ) {
			return $d->format( 'Y.m.d' ) . '（' . $days[ (int) $d->format( 'w' ) ] . '）';
		};

		$label = $ymd( $start );

		// 24時間以上にわたるときは、終わる日まで書く（深夜をまたぐだけの催しは開始だけ）
		if ( $end && ( $end->getTimestamp() - $start->getTimestamp() ) >= DAY_IN_SECONDS ) {
			return $label . '〜' . $ymd( $end );
		}

		if ( '00:00' === $start->format( 'H:i' ) && ! $end ) {
			return $label;
		}
		return $label . $start->format( 'H:i' ) . '〜';
	}

	/**
	 * ページ送り。
	 *
	 * ★ ふつうのリンク（a）で組む。JavaScript を使わないのは、記事の中に置く
	 *   部品で、JS を切っている人・読み上げ・検索エンジンのどれでも同じように
	 *   辿れるほうがよいため。
	 * ★ 現在地はリンクにしない（押しても何も起きないリンクを作らない）。
	 *   aria-current="page" で「いまここ」を読み上げにも伝える。
	 * ★ 行き先はいまのURLに ?sono_events_page= を足したもの。add_query_arg は
	 *   エスケープしないので、出すときに必ず esc_url を通す。
	 */
	private static function render_pager( $data ) {
		$total_pages = max( 1, (int) $data['totalPages'] );
		if ( $total_pages < 2 ) {
			return '';
		}
		$current = min( max( 1, (int) $data['page'] ), $total_pages );

		$href = function ( $n ) {
			// 1ページ目はクエリを外す（同じ内容に2つのURLを作らない）
			return 1 === (int) $n
				? remove_query_arg( self::PAGE_QUERY )
				: add_query_arg( self::PAGE_QUERY, (int) $n );
		};

		ob_start();
		?>
		<nav class="sono-events__pager" aria-label="イベント一覧のページ送り">
			<ul>
				<?php if ( $current > 1 ) : ?>
					<li class="sono-events__pagerside">
						<a href="<?php echo esc_url( $href( $current - 1 ) ); ?>" rel="prev">前へ</a>
					</li>
				<?php endif; ?>

				<?php foreach ( self::pager_numbers( $current, $total_pages ) as $n ) : ?>
					<li>
						<?php if ( null === $n ) : ?>
							<span class="sono-events__pagergap" aria-hidden="true">…</span>
						<?php elseif ( $n === $current ) : ?>
							<?php // 他の番号は「3ページ目」と読ませているので、現在地も揃える ?>
							<span class="sono-events__pagernow" aria-current="page">
								<span class="screen-reader-text"><?php echo esc_html( $n . 'ページ目' ); ?></span>
								<span aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $href( $n ) ); ?>">
								<span class="screen-reader-text"><?php echo esc_html( $n . 'ページ目' ); ?></span>
								<span aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>

				<?php if ( $current < $total_pages ) : ?>
					<li class="sono-events__pagerside">
						<a href="<?php echo esc_url( $href( $current + 1 ) ); ?>" rel="next">次へ</a>
					</li>
				<?php endif; ?>
			</ul>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * 出すページ番号の並び。端と現在地の周りだけを出し、離れたところは null（…）。
	 * 例（現在7・全20）: 1 … 6 7 8 … 20
	 */
	private static function pager_numbers( $current, $total ) {
		$shown = array( 1, $total );
		for ( $n = $current - 1; $n <= $current + 1; $n++ ) {
			if ( $n >= 1 && $n <= $total ) {
				$shown[] = $n;
			}
		}
		$shown = array_values( array_unique( $shown ) );
		sort( $shown );

		$out  = array();
		$prev = 0;
		foreach ( $shown as $n ) {
			if ( $prev && $n - $prev === 2 ) {
				// ★ 飛びが1つだけなら、省略記号ではなくその番号を出す。
				$out[] = $prev + 1;
			} elseif ( $prev && $n - $prev > 2 ) {
				$out[] = null; // 飛んだところ
			}
			$out[] = $n;
			$prev  = $n;
		}
		return $out;
	}

	// ── 取得とキャッシュ ────────────────────────────────────────────────

	/**
	 * イベント一覧を取る（transient → 最後に成功した値 → 空 の順）。
	 *
	 * @return array 常に normalize_data() の形
	 */
	public static function get_data( $slug, $limit, $page = 1, $past = false, $related = true ) {
		$empty = self::normalize_data( array() );
		if ( '' === $slug ) {
			return $empty;
		}
		// ★ 上限で頭打ちにする。訪問者が動かせる値なので、桁の大きい番号を
		//   そのまま持ち回らない（下の保存側でも丸めた先の鍵に入れ直している）。
		$page = max( 1, min( (int) $page, self::MAX_PAGE ) );

		// ★ ここでも丸める。この関数は公開で、テーマから直接呼ばれうる。
		//   範囲外のまま通すと API 側で丸められた結果と鍵が食い違い、
		//   同じ内容を別々にキャッシュすることになる。
		$limit = self::clamp( (int) $limit, self::DEFAULT_LIMIT, 1, self::MAX_LIMIT );
		$past  = (bool) $past;
		// ★ related の有無で内容が違うので、鍵・控え・帳簿のすべてに混ぜる（下の variant）
		$related = (bool) $related;

		$transient_key = self::transient_key( $slug, $limit, $page, $past, $related );
		$option_key    = self::option_key( $slug, $limit, $page, $past, $related );

		$cached = get_transient( $transient_key );
		// ★ 'redirect' を持つ配列は「このページ番号は、そのページに丸められた」という
		//   読み替えの印（下の保存側を参照）。印の先の内容が残っていれば、それを返す。
		// ★ 印を整数にしない。永続のオブジェクトキャッシュが無いサイトでは transient が
		//   wp_options に文字列で入り、別のリクエストから読むと int でなく string が
		//   返るため、型の判定が外れて毎回 API を叩くことになる。配列なら往復しても形が残る。
		if ( is_array( $cached ) && isset( $cached['redirect'] ) && ! isset( $cached['events'] ) ) {
			$cached = get_transient( self::transient_key( $slug, $limit, max( 1, (int) $cached['redirect'] ), $past, $related ) );
		}
		if ( is_array( $cached ) ) {
			return self::normalize_data( $cached );
		}

		$fetched = self::fetch( $slug, $limit, $page, $past, $related );
		if ( is_array( $fetched ) ) {
			// ★★ **要求されたページ番号の鍵で保存しない。丸められた先の鍵に入れる。**
			//   ページ番号は訪問者が URL（?sono_events_page=）で自由に動かせる。
			//   範囲外のページは API が最後のページに丸めて「イベント入りの成功」を返すため、
			//   要求された番号のまま保存すると、?sono_events_page=1000, 1001, … と
			//   叩かれるたびに**無期限の option が1行ずつ増え、相手のサイトの
			//   wp_options が際限なく太る**。丸めた先を鍵にすれば、実在するページの
			//   ぶんしか鍵は生まれない。
			$store_page = max( 1, (int) $fetched['page'] );
			if ( $store_page !== $page ) {
				// ★ 要求された番号の鍵にも、**読み替えの印だけ**を10分置く。印が無いと、
				//   ?sono_events_page=999 のような範囲外の番号は毎回キャッシュに当たらず、
				//   訪問のたびに API を叩く。印は小さな配列1つなので、行が増えても軽い。
				set_transient( $transient_key, array( 'redirect' => $store_page ), self::CACHE_MINUTES * MINUTE_IN_SECONDS );
				$transient_key = self::transient_key( $slug, $limit, $store_page, $past, $related );
				$option_key    = self::option_key( $slug, $limit, $store_page, $past, $related );
			}
			set_transient( $transient_key, $fetched, self::CACHE_MINUTES * MINUTE_IN_SECONDS );

			// ★ 無期限の last-good は、原則「中身がある時だけ」更新する。空で上書きすると
			//   障害時の保険そのものが消えるため。
			// ★ **ただし0件が確かな場合は消す。** profileUrl が返っている＝
			//   プロフィールは実在し、公開中のイベントが本当に0件だと確定している。
			//   ここを常に守りに倒すと、イベントを非公開にした・**運営が取り下げた**
			//   あとに sono media 側で障害が起きたとき、消したはずのイベントが
			//   相手のサイトに復活する。
			// ★ **消すのはそのページだけでなく、この ID（今後/過去ごと）の控え全部。**
			//   2ページ目以降の控えが残っていると、障害のときにそこだけ復活する。
			if ( ! empty( $fetched['events'] ) ) {
				update_option( $option_key, $fetched, false );
				self::remember_last( $slug, $past, $option_key, $related );
			} elseif ( ! empty( $fetched['profileUrl'] ) ) {
				self::purge_last( $slug, $past, $related );
				update_option( $option_key, $fetched, false );
				self::remember_last( $slug, $past, $option_key, $related );
			} elseif ( ! empty( $fetched['missing'] ) ) {
				// ★ プロフィール自体が非公開・削除になったと確かめられたら、今後・過去の
				//   両方の控えを消す。公開をやめた人の情報が、障害のたびに戻らないようにする。
				// related の有無も両方消す。
				foreach ( array( false, true ) as $p ) {
					foreach ( array( true, false ) as $r ) {
						self::purge_last( $slug, $p, $r );
					}
				}
			}
			return self::normalize_data( $fetched );
		}

		// ★ 取得失敗。期限切れでも構わないので最後に成功した値を出す
		$last     = get_option( $option_key, array() );
		$fallback = is_array( $last ) ? $last : array();

		// ★ 失敗の結果も1分だけ transient に入れる。入れないと、API に届かない間
		//   すべてのページ表示が毎回タイムアウトを待つことになり、
		//   sono media の障害が会員のサイト全体を重くしてしまう
		set_transient( $transient_key, $fallback, MINUTE_IN_SECONDS );
		return self::normalize_data( $fallback );
	}

	/**
	 * 公開APIを叩く。
	 *
	 * @return array|null 成功時は normalize_data() の形（プロフィールが無いと確かめられた
	 *                    ときは profileUrl が null で missing が真）。失敗時 null。
	 */
	private static function fetch( $slug, $limit, $page = 1, $past = false, $related = true ) {
		$args = array(
			'slug'  => $slug,
			'limit' => (int) $limit,
			'page'  => max( 1, (int) $page ),
			'past'  => $past ? '1' : '0',
		);
		// ★ 既定（含める）のときは付けない。related を知らない古い API には、従来と同じ要求を送る。
		if ( ! $related ) {
			$args['related'] = '0';
		}
		$url = add_query_arg(
			$args,
			self::origin() . '/wp-json/sono/v1/events/public'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// ★ プロフィールが無い・非公開は、API が code=not_found の 404 で答える。
		//   これだけは「失敗」ではなく「無いと確かめられた」として扱う。ほかの 404
		//   （叩き先の間違い、前段の機器の応答など）は失敗のまま——控えを消さない。
		if ( 404 === $code ) {
			if ( is_array( $body ) && isset( $body['code'] ) && 'not_found' === $body['code'] ) {
				$missing            = self::normalize_data( array() );
				$missing['missing'] = true;
				return $missing;
			}
			return null;
		}

		// ★ 成功判定は200のみ
		if ( 200 !== $code ) {
			return null;
		}
		if ( ! is_array( $body ) || ! isset( $body['events'] ) || ! is_array( $body['events'] ) ) {
			return null;
		}

		// ★ 形の合わない項目は捨てる。**isset だけでは足りない。**
		//   title や url が（API の形が変わる等で）配列で来ると、表示側の
		//   esc_html()/esc_url() が PHP 8 で TypeError になり、
		//   **相手サイトのそのページ全体が 500 になる**。
		//   「本文にエラー文言を出さない」と言いながら最悪が fatal では意味が無いので、
		//   文字列であることまで確かめ、表示側が触る項目はここで必ず同じ形にする。
		$events = array();
		foreach ( $body['events'] as $event ) {
			$clean = self::clean_event( $event );
			if ( $clean ) {
				$events[] = $clean;
			}
		}

		// ★ API が events を返したのに、1件も使える形でなかった（応答の形が崩れている）。
		//   「0件」と確かめられたわけではないので、失敗として扱う（「ありません」の文を出さず、
		//   控えも消さない）。
		if ( $body['events'] && ! $events ) {
			return null;
		}

		$total = ( isset( $body['total'] ) && is_numeric( $body['total'] ) ) ? max( 0, (int) $body['total'] ) : count( $events );
		return self::normalize_data( array(
			'events'        => $events,
			'profileUrl'    => ( isset( $body['profileUrl'] ) && is_string( $body['profileUrl'] ) && '' !== $body['profileUrl'] ) ? $body['profileUrl'] : null,
			'page'          => isset( $body['page'] ) ? $body['page'] : 1,
			'totalPages'    => isset( $body['totalPages'] ) ? $body['totalPages'] : 1,
			'total'         => $total,
			'upcomingTotal' => isset( $body['upcomingTotal'] ) ? $body['upcomingTotal'] : null,
			'pastTotal'     => isset( $body['pastTotal'] ) ? $body['pastTotal'] : null,
		) );
	}

	/**
	 * イベント1件を、表示側が安心して読める形にする。使えないものは null。
	 */
	private static function clean_event( $event ) {
		if ( ! is_array( $event ) ) {
			return null;
		}
		if ( ! isset( $event['url'], $event['title'] ) || ! is_string( $event['url'] ) || ! is_string( $event['title'] ) ) {
			return null;
		}
		if ( '' === $event['url'] || '' === $event['title'] ) {
			return null;
		}

		$str = static function ( $key ) use ( $event ) {
			return ( isset( $event[ $key ] ) && is_string( $event[ $key ] ) ) ? $event[ $key ] : '';
		};

		$dates = array();
		if ( isset( $event['dates'] ) && is_array( $event['dates'] ) ) {
			foreach ( $event['dates'] as $date ) {
				if ( ! is_array( $date ) || empty( $date['start'] ) || ! is_string( $date['start'] ) ) {
					continue;
				}
				$dates[] = array(
					'start' => $date['start'],
					'end'   => ( isset( $date['end'] ) && is_string( $date['end'] ) && '' !== $date['end'] ) ? $date['end'] : null,
				);
			}
		}

		$image = array( 'url' => '', 'width' => 0, 'height' => 0 );
		if ( isset( $event['image'] ) && is_array( $event['image'] ) && isset( $event['image']['url'] ) && is_string( $event['image']['url'] ) ) {
			$image['url']    = $event['image']['url'];
			$image['width']  = ( isset( $event['image']['width'] ) && is_numeric( $event['image']['width'] ) ) ? max( 0, (int) $event['image']['width'] ) : 0;
			$image['height'] = ( isset( $event['image']['height'] ) && is_numeric( $event['image']['height'] ) ) ? max( 0, (int) $event['image']['height'] ) : 0;
		}

		return array(
			'id'           => ( isset( $event['id'] ) && is_numeric( $event['id'] ) ) ? (int) $event['id'] : 0,
			'title'        => $event['title'],
			'url'          => $event['url'],
			'dates'        => $dates,
			'placeName'    => $str( 'placeName' ),
			'placeAddress' => $str( 'placeAddress' ),
			'charge'       => $str( 'charge' ),
			'ticketUrl'    => $str( 'ticketUrl' ),
			'performers'   => $str( 'performers' ),
			'image'        => $image,
		);
	}

	/** 形が崩れていても、呼び出し側で isset を書かずに済むよう必ず同じ形にする */
	private static function normalize_data( $data ) {
		$empty = array(
			'events'        => array(),
			'profileUrl'    => null,
			'page'          => 1,
			'totalPages'    => 1,
			'total'         => 0,
			'upcomingTotal' => 0,
			'pastTotal'     => 0,
			'missing'       => false,
		);
		if ( ! is_array( $data ) || ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return $empty;
		}
		$out           = $empty;
		$out['events'] = array_values( $data['events'] );
		if ( isset( $data['profileUrl'] ) && is_string( $data['profileUrl'] ) && '' !== $data['profileUrl'] ) {
			$out['profileUrl'] = $data['profileUrl'];
		}
		// 古い形のキャッシュが残っていても壊れないように、数でないものは既定へ倒す
		foreach ( array( 'page', 'totalPages' ) as $k ) {
			$out[ $k ] = ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) ? max( 1, (int) $data[ $k ] ) : 1;
		}
		$out['total']         = ( isset( $data['total'] ) && is_numeric( $data['total'] ) ) ? max( 0, (int) $data['total'] ) : count( $out['events'] );
		$out['upcomingTotal'] = ( isset( $data['upcomingTotal'] ) && is_numeric( $data['upcomingTotal'] ) ) ? max( 0, (int) $data['upcomingTotal'] ) : 0;
		$out['pastTotal']     = ( isset( $data['pastTotal'] ) && is_numeric( $data['pastTotal'] ) ) ? max( 0, (int) $data['pastTotal'] ) : 0;
		$out['missing']       = ! empty( $data['missing'] );
		return $out;
	}

	/** この ID（今後/過去ごと）の控えの option 名を覚えておく帳簿の鍵 */
	private static function last_index_key( $slug, $past, $related = true ) {
		return self::CACHE_PREFIX . md5( $slug . '|' . self::variant( $past, $related ) ) . '_idx';
	}

	/**
	 * 控えの option 名を帳簿に足す（ページ・件数ごとに鍵が分かれるため、まとめて消せるようにする）。
	 *
	 * ★ 読んでから書く形で、原子的ではない。同時の初回取得が重なると追記が1つ落ちることが
	 *   あるが、落ちた控えは次にそのページを取得したときに帳簿へ足し直される（自己修復する）
	 *   ので、このままにしてある。ロックを入れるほどの害ではない。
	 */
	private static function remember_last( $slug, $past, $option_key, $related = true ) {
		$key  = self::last_index_key( $slug, $past, $related );
		$list = get_option( $key, array() );
		$list = is_array( $list ) ? $list : array();
		if ( ! in_array( $option_key, $list, true ) ) {
			$list[] = $option_key;
			update_option( $key, $list, false );
		}
	}

	/** この ID（今後/過去のどちらか）の控えを、ページ・件数を問わず全部消す */
	private static function purge_last( $slug, $past, $related = true ) {
		$key  = self::last_index_key( $slug, $past, $related );
		$list = get_option( $key, array() );
		if ( is_array( $list ) ) {
			foreach ( $list as $option_key ) {
				if ( is_string( $option_key ) && 0 === strpos( $option_key, self::CACHE_PREFIX ) ) {
					delete_option( $option_key );
				}
			}
		}
		delete_option( $key );
	}

	/** いまの世代。壊れた値でも必ず正の整数にする */
	private static function cache_version() {
		return max( 1, (int) get_option( self::VERSION_OPTION, 1 ) );
	}

	/**
	 * 今後/過去と、関連ユーザーのイベントを含めるかの組み合わせを表す印。
	 *
	 * ★ 含める（既定）の印は従来の 'p' / 'u' のまま。1.0.0 で作られた
	 *   キャッシュと控えがそのまま使え、含めない指定（'pn' / 'un'）とは鍵が分かれる。
	 */
	private static function variant( $past, $related ) {
		return ( $past ? 'p' : 'u' ) . ( $related ? '' : 'n' );
	}

	/**
	 * 10分で切れるほうの鍵。**世代を混ぜる**ので、世代を進めれば全部が無効になる。
	 */
	private static function transient_key( $slug, $limit, $page, $past, $related = true ) {
		return self::CACHE_PREFIX . md5(
			self::cache_version() . '|' . $slug . '|' . (int) $limit . '|' . (int) $page . '|' . self::variant( $past, $related )
		);
	}

	/**
	 * 障害時の保険（last-good）の鍵。**世代を混ぜない。**
	 * 混ぜると「更新」を押した直後に sono media が落ちていたとき、保険まで
	 * 巻き添えで消えて何も出せなくなる。
	 */
	private static function option_key( $slug, $limit, $page, $past, $related = true ) {
		return self::CACHE_PREFIX . md5( $slug . '|' . (int) $limit . '|' . (int) $page . '|' . self::variant( $past, $related ) ) . '_last';
	}

	/**
	 * キャッシュを捨てる（＝世代を1つ進める）。
	 *
	 * ★ ショートコードで別の ID を直接指定している一覧にも効く。
	 */
	public static function flush_cache() {
		update_option( self::VERSION_OPTION, self::cache_version() + 1, true );
		// ★ 更新情報の鍵には世代を混ぜていない（固定鍵）ので、ここで消す。
		//   消さないと、新しい版を出しても最悪13時間ほど「更新があります」が
		//   出ず、急ぎの修正を届けたいときに手立てが無くなる。
		delete_transient( self::CACHE_PREFIX . 'update' );
	}

	// ── 小物 ────────────────────────────────────────────────────────────

	private static function clamp( $value, $default, $min, $max ) {
		if ( $value <= 0 ) {
			return $default;
		}
		return max( $min, min( $max, $value ) );
	}
}

Sono_Media_Events::init();

endif;
