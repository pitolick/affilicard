<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\PostType;

use Affilicard\Platform\PlatformConfig;
use Affilicard\PostType\ProductListColumns;
use Affilicard\PostType\ProductPostType;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Queue\Enqueuer;
use Affilicard\Settings\GeneralSettings;
use Affilicard\Upgrade\PluginUpgrade;
use Mockery;
use ReflectionMethod;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProductListColumnsTest extends TestCase {

	/**
	 * wp_date() への呼び出し（[format, timestamp]）を記録する。setUp() の stub は
	 * 全テスト共通で UTC 相当（gmdate と同じ基準）の戻り値を返すため、gmdate() を直接
	 * 呼んでいても出力文字列だけでは区別できない。「最終掲載日」列が実際に wp_date() を
	 * 経由しているか（= 隣接する最終同期列と同じタイムゾーン基準に揃っているか）は、
	 * この記録で呼び出し自体を検証する（final-fix-report.md Minor）。
	 *
	 * @var list<array{0: string, 1: int|null}>
	 */
	private array $wpDateCalls = array();

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->wpDateCalls = array();
		WP_Mock::userFunction( '__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_attr__' )
			->andReturnUsing(
				static function ( $text ) {
					return $text;
				}
			);
		WP_Mock::userFunction( 'esc_attr' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
		WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing(
				static function ( $text ) {
					return (string) $text;
				}
			);
		// 日付整形（Y-m-d H:i / Y-m-d）用。UTC 固定（gmdate と同じ基準）で PHP 実行環境の
		// デフォルトタイムゾーン設定に依存しないようにする（CardRendererTest と同じ手法）。
		// 呼び出し自体は $wpDateCalls に記録し、どのカラムが wp_date() を経由したかを
		// 個別テストで検証できるようにする。
		WP_Mock::userFunction( 'wp_date' )
			->andReturnUsing(
				function ( $format, $timestamp = null ) {
					$this->wpDateCalls[] = array( (string) $format, null !== $timestamp ? (int) $timestamp : null );
					return gmdate( (string) $format, null !== $timestamp ? (int) $timestamp : time() );
				}
			);
		// fetch_status 文言（FetchStatus::label()）のサニタイズ（spec §9-3 二重防御の1段目）の
		// 実体を模した stub。実 wp_strip_all_tags と同様、タグは除去するがタグ内テキストは
		// そのまま残す。
		WP_Mock::userFunction( 'wp_strip_all_tags' )
			->andReturnUsing(
				static function ( $text ) {
					return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $text ) );
				}
			);
		// 実 WordPress の esc_url_raw() は javascript:/data: 等の危険スキームを排除して
		// 空文字を返す。フォールバック判定（OfferUrl）はカードの CTA と同じこの検証を
		// 通すため、passthru ではなく危険スキームの排除だけ最小限に再現する
		// （CardRendererTest と同じ stub）。
		WP_Mock::userFunction( 'esc_url_raw' )
			->andReturnUsing(
				static function ( $value ) {
					$value = is_scalar( $value ) ? (string) $value : '';
					if ( 1 === preg_match( '/^\s*(javascript|data|vbscript)\s*:/i', $value ) ) {
						return '';
					}
					return $value;
				}
			);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * 対象商品の listings をスタブして Fallback 列（COLUMN_KEY）の HTML を返すテスト用ヘルパ。
	 *
	 * get_option は `PlatformConfig::OPTION_KEY`（プラットフォーム定義）と
	 * `GeneralSettings::OPTION_KEY`（fallbackOnTerminal 等）の両方を同じ関数名で問い合わせる。
	 * `WP_Mock::userFunction('get_option')` を `->with()` の異なる引数で複数回登録しても、
	 * Mockery は最初に登録した期待値しか使わず後続を無視することがあるため、ここではキーで
	 * 分岐する `andReturnUsing()` 1本にまとめて呼び出し引数ごとに振り分ける。
	 *
	 * @param list<array<string, mixed>> $listings
	 */
	private function renderColumnFor( array $listings ): string {
		$post_id = 999;

		WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ProductPostType::META_LISTINGS, true )
			->andReturn( $listings );

		WP_Mock::userFunction( 'get_option' )
			->andReturnUsing(
				static function ( $key, $default = false ) {
					if ( PlatformConfig::OPTION_KEY === $key ) {
						return array(
							array(
								'code'          => 'rakuten-kobo',
								'provider'      => 'rakuten-kobo',
								'priceTtlHours' => 24,
							),
						);
					}
					if ( GeneralSettings::OPTION_KEY === $key ) {
						return array( 'fallback_on_terminal' => false );
					}
					return $default;
				}
			);

		WP_Mock::userFunction( 'as_has_scheduled_action' )->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, $post_id );
		return (string) ob_get_clean();
	}

	public function test_addColumn_inserts_fallback_column_right_after_title(): void {
		$columns = array(
			'cb'     => '<input />',
			'title'  => 'タイトル',
			'author' => '著者',
			'date'   => '日付',
		);

		$result = ProductListColumns::addColumn( $columns );

		$keys = array_keys( $result );
		$this->assertSame(
			array( 'cb', 'title', ProductListColumns::COLUMN_KEY, ProductListColumns::COLUMN_LAST_VERIFIED, ProductListColumns::COLUMN_LAST_PUBLISHED, 'author', 'date' ),
			$keys
		);
		$this->assertSame( 'Fallback', $result[ ProductListColumns::COLUMN_KEY ] );
		$this->assertSame( '最終同期', $result[ ProductListColumns::COLUMN_LAST_VERIFIED ] );
		$this->assertSame( '最終掲載日', $result[ ProductListColumns::COLUMN_LAST_PUBLISHED ] );
	}

	public function test_renderColumn_echoes_warning_icon_when_listings_have_fallback(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 123, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'affiliate_url' => '',
								'regular_url'   => 'https://example.com/product',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 123,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 123 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( 'フォールバック', $output );
		$this->assertStringNotContainsString( '更新待ち', $output );
	}

	/**
	 * CodeRabbit Major #1: v3 以前の flat な listing（offers 無し・取得結果フィールドが
	 * listing 直下）は、CardRenderer の読み取りフォールバックと同じく LegacyOffer 経由で
	 * offers[0] 相当へメモリ上変換してから選択に回さなければならない。これを飛ばすと、
	 * 移行バッチが当該商品へ到達するまでの窓で、未移行の商品が一覧で軒並み em dash
	 * （警告なし）になり、実際にはフォールバック中の商品を見逃す。
	 */
	public function test_renderColumn_flatなlistingでもfallback警告を出す(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 124, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'      => 'dmm-books',
						'affiliate_url' => '',
						'regular_url'   => 'https://example.com/product',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 124,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 124 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( 'フォールバック', $output );
	}

	/**
	 * 不正な affiliate_url は「無い」と同じ——カードは regular_url を出しているので
	 * この列も警告を出す。
	 *
	 * 判定を素の空判定（`'' === $affiliate`）で書いていたころは、この商品だけ
	 * カードの実物と答えが食い違っていた（`CardRenderer::ctaHref()` は
	 * `esc_url_raw()` で検証してから採否を決めるため regular_url へ倒れる）。
	 */
	public function test_renderColumn_不正なアフィリURLでもfallback警告を出す(): void {
		$output = $this->renderColumnFor(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'affiliate_url' => 'javascript:alert(1)',
							'regular_url'   => 'https://example.com/product',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( 'フォールバック', $output );
	}

	/**
	 * 通常 URL も不正なら出せる URL が 1 つも無い——フォールバックではない。
	 *
	 * この購入リンクはカード側でも表示対象から外れる（CardRenderer::visibleListings()）。
	 * 「素の商品 URL を出している」警告を出すと、出ていないものを指すことになる。
	 */
	public function test_renderColumn_通常URLも不正ならfallback警告を出さない(): void {
		$output = $this->renderColumnFor(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'offers'   => array(
						array(
							'affiliate_url' => '',
							'regular_url'   => 'javascript:alert(1)',
						),
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'フォールバック', $output );
		$this->assertStringContainsString( '—', $output );
	}

	public function test_renderColumn_echoes_em_dash_when_no_fallback(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 456, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'affiliate_url' => 'https://aff.example.com/abc',
								'regular_url'   => 'https://example.com/product',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 456 );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '—', $output );
	}

	/**
	 * 恒久失敗（terminal）は、URL フォールバックでも価格非表示でもなくても警告を出す。
	 *
	 * アフィリエイト URL があり価格が空の terminal な購入リンクは、フォールバックにも
	 * 価格非表示にも該当しない。取得状態を見ないと一覧は em dash を出すだけで、
	 * 「もう買えない商品」であることが運用に伝わらない。
	 */
	public function test_renderColumn_取得状態がterminalなら警告を出す(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 654, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								// フォールバックではない（アフィリエイト URL あり）。
								'affiliate_url' => 'https://aff.example.com/abc',
								'regular_url'   => 'https://example.com/product',
								// 価格が空なので価格非表示の判定にも掛からない。
								'price'         => '',
								'fetch_status'  => FetchStatus::TERMINAL,
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 654 );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '—', $output, '取得状態を無視して em dash を出している' );
		$this->assertStringContainsString( '商品が見つかりません', $output );
	}

	/**
	 * 未知の fetch_status でも、警告アイコンに理由が添えられる。
	 *
	 * 保存時のサニタイズを経ていないデータ（移行前の flat listing・外部ツールの
	 * 直書き）には未知の値が入りうる。素通しすると label() が空を返し、警告アイコン
	 * だけ出て理由が書かれていない状態になる。
	 */
	public function test_renderColumn_未知の取得状態でも理由を添える(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 655, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'affiliate_url' => 'https://aff.example.com/abc',
								'regular_url'   => 'https://example.com/product',
								'price'         => '',
								'fetch_status'  => 'typo',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 655 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		// 未知の値は TRANSIENT へ倒れるので、その文言が理由として出る。
		$this->assertStringContainsString( '一時的に取得できませんでした', $output );
	}

	/**
	 * 非スカラーの取得状態・価格で、根拠の無い警告を出さない。
	 *
	 * `(string)` で直にキャストすると取得状態が `'Array'` になり、
	 * FetchStatus::normalise() が未知値として TRANSIENT へ倒すため、実際には
	 * 何も失敗していない listing に「一時的に取得できませんでした」の警告が出る。
	 * 価格の側も `'Array'` が空判定をすり抜けて「価格が隠れています」を誘発する。
	 * 運用に嘘を伝えるので、非スカラーは normalise へ渡す前に「値なし」へ倒す。
	 */
	public function test_renderColumn_非スカラーの取得状態と価格で警告を出さない(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 656, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'affiliate_url' => 'https://aff.example.com/abc',
								'regular_url'   => 'https://example.com/product',
								'price'         => array( '660' ),
								'fetch_status'  => array( 'terminal' ),
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'as_has_scheduled_action' )->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 656 );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'dashicons-warning', $output );
		$this->assertStringNotContainsString( 'Array', $output );
		$this->assertStringContainsString( '—', $output );
	}

	public function test_renderColumn_echoes_price_hidden_warning_when_price_unverified(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 321, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'price'         => '693',
								'affiliate_url' => 'https://hb.afl.rakuten.co.jp/hgc/x/',
								'regular_url'   => 'https://books.rakuten.co.jp/rk/x/',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'          => 'rakuten-kobo',
						'priceTtlHours' => 24,
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 321,
					'platform' => 'rakuten-kobo',
				),
				'affilicard-manual'
			)
			->andReturn( false );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 321 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '価格が未確認/期限切れのためカードで非表示です', $output );
		$this->assertStringNotContainsString( '更新待ち', $output );
	}

	/**
	 * 「最終同期」は listing 直下ではなく、OfferSelector が選んだ購入リンク（offer）の
	 * last_verified_at を読む。v4 で last_verified_at は offers[] の下へ移ったため、
	 * listing 直下を読む実装は移行後の全商品で em dash を出し続ける（黙って死ぬ）。
	 */
	public function test_最終同期は選ばれたofferのlast_verified_atの最大値を出す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 111, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'regular_url'      => 'https://example.test/a',
								'last_verified_at' => '2026-07-20T10:00:00+00:00',
							),
						),
					),
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'regular_url'      => 'https://example.test/b',
								'last_verified_at' => '2026-07-21T03:15:00+00:00',
							),
						),
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_VERIFIED, 111 );
		$output = (string) ob_get_clean();

		$this->assertSame( '2026-07-21 03:15', $output );
	}

	/**
	 * 使用中でない購入リンク（display_order が後ろ）の日時は出さない。
	 *
	 * 選択を OfferSelector に委ねていること自体をここで固定する——offers を単に
	 * 全走査して最大値を取る実装だと、カードが使っていない購入リンクの日時が
	 * 「最終同期」として出てしまう（fixture が新旧どちらの形でも通ってしまう
	 * 状態に戻らないための番人）。
	 */
	public function test_最終同期は使用中でない購入リンクの日時を出さない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 333, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'display_order'    => 200,
								'regular_url'      => 'https://example.test/secondary',
								'last_verified_at' => '2026-07-25T00:00:00+00:00',
							),
							array(
								'display_order'    => 100,
								'regular_url'      => 'https://example.test/primary',
								'last_verified_at' => '2026-07-21T03:15:00+00:00',
							),
						),
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_VERIFIED, 333 );
		$output = (string) ob_get_clean();

		$this->assertSame( '2026-07-21 03:15', $output );
	}

	/**
	 * v3 以前の flat な listing（offers 無し・listing 直下に last_verified_at）は
	 * この列の対象外＝em dash。旧形状を読み続ける実装へ戻ればここが落ちる。
	 */
	public function test_最終同期はlisting直下のlast_verified_atを読まない(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 222, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'         => 'dmm-books',
						'last_verified_at' => '2026-07-20T10:00:00+00:00',
					),
					array(
						'platform' => 'rakuten-kobo',
						'offers'   => array(
							array(
								'regular_url'      => 'https://example.test/b',
								'last_verified_at' => '',
							),
						),
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_VERIFIED, 222 );
		$output = (string) ob_get_clean();

		$this->assertSame( '<span aria-hidden="true">—</span>', $output );
	}

	/**
	 * CodeRabbit round 2: v3 以前の flat な listing（offers 無し・取得結果フィールドが
	 * listing 直下）でも、renderFallbackColumn() と同じ legacyOffers() 経由で
	 * last_verified_at を拾えなければならない。これを飛ばすと、移行バッチが当該商品へ
	 * 到達するまでの窓で、実際には last_verified_at を持つ未移行の商品がこの列だけ
	 * em dash になる（round 1 は Fallback 列にしか legacyOffers() を足さなかった）。
	 *
	 * 直前のテスト（test_最終同期はlisting直下のlast_verified_atを読まない）とは
	 * fixture が違う点に注意: あちらは regular_url 等の flat な取得結果フィールドを
	 * 一切持たない listing シェルなので LegacyOffer::hasFlatFetchFields() が false のまま
	 * であり、このテストの fixture（regular_url を持つ）とは矛盾しない。
	 */
	public function test_最終同期はflatなlistingでもlegacyOffer経由で値を出す(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 444, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform'         => 'dmm-books',
						'regular_url'      => 'https://example.com/product',
						'last_verified_at' => '2026-07-20T10:00:00+00:00',
					),
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_VERIFIED, 444 );
		$output = (string) ob_get_clean();

		$this->assertSame( '2026-07-20 10:00', $output );
	}

	public function test_renderColumn_returns_early_for_unrelated_column(): void {
		ob_start();
		ProductListColumns::renderColumn( 'some-other-column', 789 );
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Task 18: Fallback 列にキュー状態を連携。
	 *
	 * as_has_scheduled_action が true（pending なジョブが Enqueuer::HOOK_REFRESH /
	 * post_id・platform / "affilicard-{account}" group で見つかる）場合、警告アイコンの
	 * title に「更新待ち」が含まれること。呼び出し引数（hook・args・group）も検証する。
	 */
	public function test_renderColumn_fallback_title_includes_pending_note_when_queue_job_scheduled(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 555, ProductPostType::META_LISTINGS, true )
			->andReturn(
				array(
					array(
						'platform' => 'dmm-books',
						'offers'   => array(
							array(
								'affiliate_url' => '',
								'regular_url'   => 'https://example.com/product',
							),
						),
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PlatformConfig::OPTION_KEY, array() )
			->andReturn(
				array(
					array(
						'code'     => 'dmm-books',
						'provider' => 'dmm-ebook',
					),
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array() );
		WP_Mock::userFunction( 'as_has_scheduled_action' )
			->once()
			->with(
				Enqueuer::HOOK_REFRESH,
				array(
					'post_id'  => 555,
					'platform' => 'dmm-books',
				),
				'affilicard-dmm'
			)
			->andReturn( true );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_KEY, 555 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-warning', $output );
		$this->assertStringContainsString( '更新待ち', $output );
	}

	/**
	 * Task 12: 警告アイコンの文言は保存された文字列ではなく、選ばれた offer の
	 * `fetch_status` から `FetchStatus::label()` が都度生成する。
	 */
	public function test_fetch_statusから文言を引く(): void {
		// 保存された文言ではなく、コードから生成した文言が出ること。
		$html = $this->renderColumnFor(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'enabled'  => true,
					'offers'   => array(
						array(
							'display_order' => 100,
							'external_id'   => 'x',
							'regular_url'   => 'https://example.test/x',
							'fetch_status'  => FetchStatus::TERMINAL,
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '商品が見つかりません', $html );
	}

	/**
	 * v3 では UNSUPPORTED/TRANSIENT がともに TRANSIENT_FAILURE のリトライ分類に潰れ、
	 * 一覧上でも同じ「一時的に取得できませんでした」の文言になっていた。4値それぞれが
	 * 別の文言になることを固定する（「このプラットフォームには自動取得の provider が
	 * 無い」と「API に一時的に到達できなかった」は一覧の読み手には別の意味を持つ）。
	 */
	public function test_自動取得の対象外は一時失敗と別の文言になる(): void {
		// v3 では両方 TRANSIENT に潰れて「一時的に取得できませんでした」と出ていた。
		$html = $this->renderColumnFor(
			array(
				array(
					'platform' => 'amazon',
					'enabled'  => true,
					'offers'   => array(
						array(
							'display_order' => 100,
							'external_id'   => '',
							'regular_url'   => 'https://example.test/x',
							'fetch_status'  => FetchStatus::UNSUPPORTED,
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '自動取得の対象外です', $html );
		$this->assertStringNotContainsString( '一時的に取得できませんでした', $html );
	}

	/**
	 * 警告の判定対象は listing 全体ではなく `OfferSelector::select()` が選んだ
	 * 1件（表示中の購入リンク）である。先頭が鮮度切れ・後続が新しい場合でも、
	 * 選択係が選ぶのは表示順の先頭（display_order 昇順）なので、その offer を見て
	 * 警告を出す（後続の新しい offer を見て警告を消してはならない）。
	 *
	 * レビュー指摘（Important）: 当初の版は両 offer とも `affiliate_url` を欠いており、
	 * `OfferSelector` がどちらを選んでも `has_fallback` が true になって
	 * `assertStringContainsString('warning', ...)` が通ってしまう——選択を間違えても
	 * 検知できないテストだった。両 offer に `affiliate_url` を与えてフォールバック経路を
	 * 無効化し、代わりに2 offer 間で結果が分かれる「価格非表示警告」の文言そのものを
	 * 見ることで、選ばれた offer を取り違えると必ず失敗する形にする。
	 */
	public function test_警告の判定は選択された購入リンクを見る(): void {
		// 先頭（display_order が小さい方）が鮮度切れ・後続が新しい場合、選択係は
		// 先頭を選ぶので、先頭の鮮度切れを理由に価格非表示警告が出ること。
		// 後続（新しい方）が選ばれてしまうと price は鮮度内になり、この警告は出ない。
		$html = $this->renderColumnFor(
			array(
				array(
					'platform' => 'rakuten-kobo',
					'enabled'  => true,
					'offers'   => array(
						array(
							'display_order'    => 10,
							'external_id'      => 'shown',
							'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/a/',
							'regular_url'      => 'https://example.test/a',
							'price'            => '660',
							'last_verified_at' => gmdate( 'c', time() - 30 * 3600 ),
						),
						array(
							'display_order'    => 100,
							'external_id'      => 'hidden',
							'affiliate_url'    => 'https://hb.afl.rakuten.co.jp/hgc/b/',
							'regular_url'      => 'https://example.test/b',
							'price'            => '660',
							'last_verified_at' => gmdate( 'c' ),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( '価格が未確認/期限切れのためカードで非表示です', $html );
	}

	/**
	 * Task 12: 表示文言のサニタイズ（spec §9-3 二重防御）が引き続き機能していることの
	 * 証拠テスト。
	 *
	 * `FetchStatus::label()` が実際に返す値は本プラグイン固定の4種類の日本語のみで、
	 * `<script>` のような攻撃文字列や200文字超の長文が `renderColumn()` の経路を通じて
	 * ここに渡ることはもう無い（旧 `fetch_error` は provider 由来の外部文字列という
	 * 前提自体が誤りだったことが分かったため）。それでも「将来 provider 由来の詳細を
	 * 持つフィールドを足す余地」のためサニタイズ自体は残す方針（クラス docblock 参照）
	 * であり、そのサニタイズ処理自体が壊れていないことは private メソッドを直接叩いて
	 * 固定する。
	 */
	public function test_sanitizeStatusLabelはscriptタグを除去する(): void {
		$method = new ReflectionMethod( ProductListColumns::class, 'sanitizeStatusLabel' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'API接続エラー: <script>alert(1)</script>' );

		$this->assertStringContainsString( 'API接続エラー', $result );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringNotContainsString( '</script>', $result );
	}

	/** 上記と同じ理由で、200文字を超える入力の切り詰めも private メソッド単体で固定する。 */
	public function test_sanitizeStatusLabelは200文字に切り詰める(): void {
		$long_text = str_repeat( 'あ', 250 ) . 'TAIL_MARKER_MUST_BE_TRUNCATED';

		$method = new ReflectionMethod( ProductListColumns::class, 'sanitizeStatusLabel' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $long_text );

		$this->assertSame( 200, mb_strlen( $result ) );
		$this->assertStringNotContainsString( 'TAIL_MARKER_MUST_BE_TRUNCATED', $result );
	}

	/**
	 * Task 11: 商品一覧の「最終掲載日」列とソート。
	 *
	 * 値は ISO8601（UTC）の meta なので、meta_value の文字列比較がそのまま
	 * 時系列順になる（辞書順＝時系列順）。ソート用フィルタの登録先はコラム自身の
	 * 名前を値として使う（WP コアの `manage_*_sortable_columns` の慣例どおり）。
	 */
	public function test_最終掲載日はソート可能列として登録される(): void {
		$columns = ProductListColumns::sortableColumns( array() );

		$this->assertArrayHasKey( ProductListColumns::COLUMN_LAST_PUBLISHED, $columns );
		$this->assertSame( ProductListColumns::COLUMN_LAST_PUBLISHED, $columns[ ProductListColumns::COLUMN_LAST_PUBLISHED ] );
	}

	/**
	 * レビュー対応（Important 1・レビュー Major 3 で名前付き節を EXISTS→NOT EXISTS へ
	 * 訂正）: `meta_key` + `orderby=meta_value` という古典的パターンは暗黙に
	 * `compare=EXISTS` の INNER JOIN になり、最終掲載日メタを持たない投稿
	 * （既存カタログの大半）が結果集合から消える。代わりに EXISTS/NOT EXISTS を
	 * `relation => OR` で束ねた meta_query を設定し、`orderby` は **NOT EXISTS 側**の
	 * 節名を参照する形にする（`WP_Meta_Query` は NOT EXISTS のときだけ meta_key
	 * 一致を JOIN の ON 句へ埋め込むため、その alias だけが ORDER BY で参照できる
	 * 確定値になる。EXISTS 側を参照すると、対象 meta を持たない投稿の並び順が
	 * その投稿の無関係な他 meta の値に化けて不定になる——wp-env の実 SQL でしか
	 * 検出できないため e2e で固定した。詳細は ProductListColumns::applySortQuery()
	 * の docblock）。WP_Query はモックのため、ここでは `set()` に渡される引数の
	 * 形そのものを検証する。
	 */
	public function test_ソート指定時にmeta_queryとorderbyを設定する(): void {
		$query = Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( ProductPostType::POST_TYPE );
		$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( ProductListColumns::COLUMN_LAST_PUBLISHED );
		$query->shouldReceive( 'get' )->with( 'order' )->andReturn( 'ASC' );
		$query->shouldReceive( 'set' )->once()->with(
			'meta_query',
			array(
				'relation'                         => 'OR',
				array(
					'key'     => ProductPostType::META_LAST_PUBLISHED_AT,
					'compare' => 'EXISTS',
				),
				'affilicard_last_published_clause' => array(
					'key'     => ProductPostType::META_LAST_PUBLISHED_AT,
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$query->shouldReceive( 'set' )->once()->with( 'orderby', array( 'affilicard_last_published_clause' => 'ASC' ) );

		WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		ProductListColumns::applySortQuery( $query );

		$this->assertConditionsMet();
	}

	/**
	 * order（asc/desc）が無指定・不正な値のときは DESC にフォールバックする
	 * （WP_Query 自体の既定と揃える）。
	 */
	public function test_ソート指定時にorder未指定ならDESCにフォールバックする(): void {
		$query = Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( ProductPostType::POST_TYPE );
		$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( ProductListColumns::COLUMN_LAST_PUBLISHED );
		$query->shouldReceive( 'get' )->with( 'order' )->andReturn( '' );
		$query->shouldReceive( 'set' )->once()->with( 'meta_query', Mockery::type( 'array' ) );
		$query->shouldReceive( 'set' )->once()->with( 'orderby', array( 'affilicard_last_published_clause' => 'DESC' ) );

		WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		ProductListColumns::applySortQuery( $query );

		$this->assertConditionsMet();
	}

	/**
	 * 管理画面外（フロント）のクエリまで meta ソートに巻き込まないためのガード。
	 * is_admin() が false の場合、is_main_query() すら呼ばずに早期 return する。
	 */
	public function test_applySortQuery_admin以外では何もしない(): void {
		$query = Mockery::mock( \WP_Query::class );

		WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		ProductListColumns::applySortQuery( $query );

		$this->assertConditionsMet();
	}

	/** サイドバーウィジェット等、管理画面内でもメインクエリでなければ何もしない。 */
	public function test_applySortQuery_メインクエリ以外では何もしない(): void {
		$query = Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'is_main_query' )->andReturn( false );

		WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		ProductListColumns::applySortQuery( $query );

		$this->assertConditionsMet();
	}

	/**
	 * レビュー対応（Important 1 の付随修正）: `pre_get_posts` はグローバルフックのため、
	 * 商品 CPT 以外の一覧（例: 固定ページ一覧）に同名の orderby が来ても作用しない。
	 */
	public function test_applySortQuery_対象post_type以外では何もしない(): void {
		$query = Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'page' );

		WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		ProductListColumns::applySortQuery( $query );

		$this->assertConditionsMet();
	}

	/** orderby が最終掲載日以外（他列でのソート・未指定）なら meta_query/orderby を書き換えない。 */
	public function test_applySortQuery_orderbyが一致しない場合は何もしない(): void {
		$query = Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( ProductPostType::POST_TYPE );
		$query->shouldReceive( 'get' )->with( 'orderby' )->andReturn( 'title' );

		WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		ProductListColumns::applySortQuery( $query );

		$this->assertConditionsMet();
	}

	/** register() が sortable_columns フィルタと pre_get_posts アクションを配線すること。 */
	public function test_register_wires_sortable_columns_filter_and_pre_get_posts_action(): void {
		WP_Mock::expectFilterAdded(
			'manage_edit-' . ProductPostType::POST_TYPE . '_sortable_columns',
			array( ProductListColumns::class, 'sortableColumns' )
		);
		WP_Mock::expectActionAdded(
			'pre_get_posts',
			array( ProductListColumns::class, 'applySortQuery' )
		);

		ProductListColumns::register();

		$this->assertConditionsMet();
	}

	/**
	 * PublicationDate::get() が有効な値を返し、StocktakePolicy::isRetired() が
	 * false（棚卸し対象外）の場合、日付のみ（アーカイブアイコン無し）で表示される。
	 */
	public function test_renderColumn_last_published_shows_date_without_archive_icon_when_not_retired(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 901, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '2026-08-01T00:00:00+00:00' );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'stocktake_enabled' => false ) );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_PUBLISHED, 901 );
		$output = (string) ob_get_clean();

		$this->assertSame( '2026-08-01', $output );
	}

	/**
	 * spec 2026-08-25 Minor: 「最終掲載日」列は隣の「最終同期」列
	 * （renderLastVerifiedColumn が wp_date('Y-m-d H:i', ...) を使う）と同じ基準——
	 * `gmdate()`（UTC 固定）ではなく `wp_date()`（サイトのタイムゾーン）——で整形される。
	 * 以前は gmdate() を直接呼んでおり、JST サイトで JST 08:00 に公開した商品が
	 * UTC では前日 23:00 になるため、この列だけ 1 日前の日付が出ていた
	 * （final-fix-report.md Minor）。出力文字列だけでは gmdate/wp_date を区別できない
	 * （setUp() の stub がどちらも UTC 相当を返すため）ので、$wpDateCalls で
	 * `wp_date('Y-m-d', $ts)` が実際に呼ばれたことを直接検証する。
	 */
	public function test_renderColumn_last_publishedはwp_dateでサイトのタイムゾーンに整形される(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 906, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '2026-08-01T00:00:00+00:00' );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'stocktake_enabled' => false ) );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_PUBLISHED, 906 );
		ob_end_clean();

		$this->assertContains(
			array( 'Y-m-d', strtotime( '2026-08-01T00:00:00+00:00' ) ),
			$this->wpDateCalls,
			'renderLastPublishedColumn() は wp_date(\'Y-m-d\', $ts) を呼ぶべき（gmdate() 直接呼び出しは不可）'
		);
	}

	/**
	 * StocktakePolicy::isRetired() が true（棚卸し対象）の場合、日付に加えて
	 * dashicons-archive の警告アイコンを付記する。
	 */
	public function test_renderColumn_last_published_shows_archive_icon_when_retired(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 902, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '2020-01-01T00:00:00+00:00' );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'stocktake_enabled' => true,
					'stocktake_days'    => 180,
				)
			);

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_PUBLISHED, 902 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '2020-01-01', $output );
		$this->assertStringContainsString( 'dashicons-archive', $output );
		$this->assertStringContainsString( '棚卸し対象', $output );
	}

	/**
	 * レビュー対応（Critical 1）: 最終掲載日が無く、棚卸し自体が無効化されているケース。
	 * `StocktakePolicy::isRetired()` は無条件に呼ばれるが（Critical 1 修正）、
	 * 無効化されていれば常に false を返すため em dash のみで、アーカイブアイコンは
	 * 付かない。
	 */
	public function test_renderColumn_last_published_shows_em_dash_without_archive_icon_when_no_timestamp_and_stocktake_disabled(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 903, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn( array( 'stocktake_enabled' => false ) );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_PUBLISHED, 903 );
		$output = (string) ob_get_clean();

		$this->assertSame( '<span aria-hidden="true">—</span>', $output );
	}

	/**
	 * レビュー対応（Critical 1）: 最終掲載日メタを持たない商品（既存カタログの大半。
	 * `META_LAST_PUBLISHED_AT` は `PublishTrigger::syncPost()` でしか書かれない）でも、
	 * `StocktakePolicy::isRetired()` は棚卸し基準日（`PluginUpgrade::OPTION_STOCKTAKE_BASELINE`）
	 * にフォールバックして判定する。旧実装は $ts が null の時点で早期 return しており
	 * isRetired() に到達しなかったため、この一覧上で棚卸し対象を一切区別できない
	 * バグがあった（spec §5-2 違反）。ここでは基準日経由で棚卸し対象と判定される
	 * ケースで、em dash に加えてアーカイブアイコンが表示されることを固定する。
	 */
	public function test_renderColumn_last_published_shows_archive_icon_when_no_timestamp_but_baseline_retired(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 904, ProductPostType::META_LAST_PUBLISHED_AT, true )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_option' )
			->with( GeneralSettings::OPTION_KEY, array() )
			->andReturn(
				array(
					'stocktake_enabled' => true,
					'stocktake_days'    => 180,
				)
			);
		WP_Mock::userFunction( 'get_option' )
			->with( PluginUpgrade::OPTION_STOCKTAKE_BASELINE, '' )
			->andReturn( '2020-01-01T00:00:00+00:00' );

		ob_start();
		ProductListColumns::renderColumn( ProductListColumns::COLUMN_LAST_PUBLISHED, 904 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '—', $output );
		$this->assertStringContainsString( 'dashicons-archive', $output );
		$this->assertStringContainsString( '棚卸し対象', $output );
	}
}
