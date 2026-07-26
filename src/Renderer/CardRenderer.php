<?php
declare(strict_types=1);

namespace Affilicard\Renderer;

use Affilicard\Platform\PlatformDefinition;
use Affilicard\Pricing\PriceFreshness;
use Affilicard\Stock\StockStatus;

/**
 * 商品データ + platform 定義から商品カードの HTML 文字列を生成する純粋なレンダラ。
 *
 * 副作用を持たず（DB/option を読まない）、入力はすべて引数で受け取る。
 * WordPress の escape 関数のみに依存する。
 */
final class CardRenderer {

	/**
	 * R18 バッジ（自作オリジナル・白地に太い赤リング＋黒「18」＋赤の禁止斜線）。
	 * assets/r18-badge.svg と同期。既存キャラクター／マークを模倣しない汎用の禁止標識風デザイン。
	 * 白ディスク＋黒数字で任意の表紙上でも視認性を確保。
	 * 静的マークアップのみで外部依存・副作用を持たない（純粋レンダラの制約を維持）。
	 */
	private const R18_BADGE_SVG = '<svg class="affilicard-card__cover-badge" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" role="img" aria-label="18歳未満閲覧禁止"><circle cx="50" cy="50" r="43" fill="#ffffff" stroke="#e60012" stroke-width="10"/><text x="50" y="67" text-anchor="middle" font-family="Arial, sans-serif" font-size="52" font-weight="700" fill="#111111">18</text><line x1="21" y1="21" x2="79" y2="79" stroke="#e60012" stroke-width="10" stroke-linecap="round"/></svg>';

	/** 画像なしプレースホルダの汎用アイコン(中立の「画像」意匠。既存マーク非模倣・静的 SVG)。 */
	private const MEDIA_PLACEHOLDER_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="画像なし"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="M4 17l5-5 4 4 3-3 4 4"/></svg>';

	/**
	 * @param array<string, mixed>     $product   ProductRepository::find() の戻り値形
	 * @param list<PlatformDefinition> $platforms enabled な platform（displayOrder 昇順想定）
	 * @param array<string, mixed>     $options   hide_platforms / image_url / colors
	 */
	public function render( array $product, array $platforms, array $options = array() ): string {
		$by_code = array();
		foreach ( $platforms as $platform ) {
			if ( $platform instanceof PlatformDefinition ) {
				$by_code[ $platform->code ] = $platform;
			}
		}

		$hide             = isset( $options['hide_platforms'] ) && is_array( $options['hide_platforms'] ) ? array_map( 'strval', $options['hide_platforms'] ) : array();
		$only             = isset( $options['only_platforms'] ) && is_array( $options['only_platforms'] ) ? array_map( 'strval', $options['only_platforms'] ) : array();
		$fallback_image   = isset( $options['image_url'] ) ? (string) $options['image_url'] : '';
		$visible_listings = $this->visibleListings(
			isset( $product['listings'] ) && is_array( $product['listings'] ) ? $product['listings'] : array(),
			$by_code,
			$hide,
			$only
		);
		$image_url        = $this->selectCardImage( $visible_listings, $by_code, $fallback_image );
		$colors           = isset( $options['colors'] ) && is_array( $options['colors'] ) ? $options['colors'] : array();
		$header_keys      = isset( $options['header_keys'] ) && is_array( $options['header_keys'] ) ? array_map( 'strval', $options['header_keys'] ) : array( 'author', 'publisher' );
		$hidden_keys      = isset( $options['hidden_keys'] ) && is_array( $options['hidden_keys'] ) ? array_map( 'strval', $options['hidden_keys'] ) : array();
		$media_label      = isset( $options['media_label'] ) ? (string) $options['media_label'] : (string) __( '商品画像', 'affilicard' );
		$media_aspect     = isset( $options['media_aspect_ratio'] ) ? trim( (string) $options['media_aspect_ratio'] ) : '';
		if ( '' === $media_aspect ) {
			$media_aspect = '1 / 1';
		}
		$cta_overrides = isset( $options['cta_label_overrides'] ) && is_array( $options['cta_label_overrides'] )
			? $options['cta_label_overrides']
			: array();

		$mask_blur  = ! empty( $options['mask_blur'] );
		$mask_r18   = ! empty( $options['mask_r18'] );
		$mask_label = isset( $options['mask_label'] ) ? trim( (string) $options['mask_label'] ) : '';
		// R18 はぼかしを強制する。
		$mask_blur = $mask_blur || $mask_r18;

		$stock        = StockStatus::normalize( isset( $product['stock_status'] ) ? (string) $product['stock_status'] : null );
		$is_available = StockStatus::AVAILABLE === $stock;

		$is_preorder        = $is_available && ! empty( $options['is_preorder'] );
		$release_date_label = isset( $options['release_date_label'] ) ? (string) $options['release_date_label'] : '';

		$extras = isset( $product['extras'] ) && is_array( $product['extras'] ) ? $product['extras'] : array();

		$style = $this->rootStyle( $colors );
		$html  = '<div class="affilicard-card"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';

		$html .= '<div class="affilicard-card__inner">';

		// 書影カラム（画像が無ければプレースホルダ）。inline aspect-ratio は枠（padding 込みの
		// border-box）ではなく実画像/マスクカバー/プレースホルダ側に付与し、type 別の比率が
		// そのまま実際の content box に適用されるようにする（padding が比率を歪ませない）。
		// 実画像は object-fit: contain（全 type 共通）で枠内に収める。mask/R18/label は不変。
		$aspect_attr = ' style="aspect-ratio: ' . esc_attr( $media_aspect ) . '"';

		$html .= '<div class="affilicard-card__media">';
		if ( '' !== $image_url ) {
			$src = esc_url( $image_url );
			$alt = esc_attr( (string) ( $product['title'] ?? '' ) );
			if ( $mask_blur ) {
				// マスク時: aspect-ratio はカバーラッパ側が持つ。内側のぼかし画像は cover を
				// そのまま埋めるだけでよい（アスペクトを持たせない）。
				$img     = '<img class="affilicard-card__media-image" src="' . $src . '" alt="' . $alt . '" loading="lazy" />';
				$overlay = '';
				if ( $mask_r18 ) {
					$overlay .= self::R18_BADGE_SVG;
				}
				if ( '' !== $mask_label ) {
					$overlay .= '<span class="affilicard-card__cover-label">' . esc_html( $mask_label ) . '</span>';
				}
				$overlay_html = '' !== $overlay
					? '<div class="affilicard-card__cover-overlay">' . $overlay . '</div>'
					: '';
				$html        .= '<div class="affilicard-card__cover affilicard-card__cover--masked"' . $aspect_attr . '>'
					. '<div class="affilicard-card__cover-blur">' . $img . '</div>'
					. $overlay_html
					. '</div>';
			} else {
				$html .= '<img class="affilicard-card__media-image" src="' . $src . '" alt="' . $alt . '" loading="lazy"' . $aspect_attr . ' />';
			}
		} else {
			// 可視ラベルは中立の「画像がありません」に固定する（type 名を出すと読み込み失敗に見えるため）。
			// type別ラベル（書影／商品画像／キービジュアル等）は role="img" + aria-label の
			// スクリーンリーダー向け情報として保持する。
			$placeholder_label = sprintf(
				/* translators: %s: media type label (e.g. 書影). */
				(string) __( '%sがありません', 'affilicard' ),
				$media_label
			);
			$html .= '<div class="affilicard-card__media-placeholder"' . $aspect_attr . ' role="img" aria-label="' . esc_attr( $placeholder_label ) . '">'
				. '<span class="affilicard-card__media-placeholder-icon" aria-hidden="true">' . self::MEDIA_PLACEHOLDER_ICON_SVG . '</span>'
				. '<span class="affilicard-card__media-placeholder-label" aria-hidden="true">' . esc_html__( '画像がありません', 'affilicard' ) . '</span>'
				. '</div>';
		}
		$html .= '</div>';

		// 本文カラム。
		$html .= '<div class="affilicard-card__body">';
		$html .= $this->renderMetaHeader( $extras, $header_keys );
		$html .= '<h3 class="affilicard-card__title">' . esc_html( (string) ( $product['title'] ?? '' ) ) . '</h3>';

		if ( ! $is_available ) {
			$html .= '<span class="affilicard-card__badge affilicard-card__badge--' . esc_attr( $stock ) . '">' . esc_html( StockStatus::label( $stock ) ) . '</span>';
		}

		if ( $is_preorder ) {
			$html .= '<div class="affilicard-card__preorder">';
			$html .= '<span class="affilicard-card__badge affilicard-card__badge--preorder">' . esc_html__( '予約受付中', 'affilicard' ) . '</span>';
			if ( '' !== $release_date_label ) {
				$html .= '<span class="affilicard-card__release-date">' . esc_html( $release_date_label ) . '</span>';
			}
			$html .= '</div>';
		}

		$content = (string) ( $product['content'] ?? '' );
		if ( '' !== $content ) {
			$html .= '<div class="affilicard-card__desc">' . wp_kses_post( $content ) . '</div>';
		}

		$html .= $this->renderExtras( $extras, $header_keys, $hidden_keys );

		if ( $is_available ) {
			$html .= $this->renderListings(
				isset( $product['listings'] ) && is_array( $product['listings'] ) ? $product['listings'] : array(),
				$by_code,
				$hide,
				$only,
				$cta_overrides,
				$is_preorder
			);
		}

		$html .= '</div>'; // __body
		$html .= '</div>'; // __inner

		if ( $is_available ) {
			$html .= $this->renderTimestamp( $visible_listings, $by_code );
		}

		$html .= '</div>'; // __card
		return $html;
	}

	/**
	 * @param array<string, mixed> $colors
	 */
	private function rootStyle( array $colors ): string {
		$map   = array(
			'card_bg'     => '--affilicard-card-bg',
			'card_border' => '--affilicard-card-border',
			'cta_bg'      => '--affilicard-cta-bg',
			'cta_text'    => '--affilicard-cta-text',
		);
		$parts = array();
		foreach ( $map as $key => $var ) {
			$raw = isset( $colors[ $key ] ) ? trim( (string) $colors[ $key ] ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$value = (string) sanitize_hex_color( $raw );
			if ( '' === $value ) {
				continue;
			}
			$parts[] = $var . ':' . $value;
		}
		return array() === $parts ? '' : implode( ';', $parts ) . ';';
	}

	/**
	 * @param list<array<string, mixed>> $extras
	 * @param list<string>               $header_keys
	 * @param list<string>               $hidden_keys
	 */
	private function renderExtras( array $extras, array $header_keys, array $hidden_keys ): string {
		$excluded = array_merge( $header_keys, $hidden_keys );
		$rows     = '';
		foreach ( $extras as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$key = isset( $extra['key'] ) ? (string) $extra['key'] : '';
			if ( '' !== $key && in_array( $key, $excluded, true ) ) {
				continue;
			}
			$label = isset( $extra['label'] ) ? trim( (string) $extra['label'] ) : '';
			$value = isset( $extra['value'] ) ? trim( (string) $extra['value'] ) : '';
			if ( '' === $label || '' === $value ) {
				continue;
			}
			$rows .= '<div class="affilicard-card__extra"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		return '' === $rows ? '' : '<dl class="affilicard-card__extras">' . $rows . '</dl>';
	}

	/**
	 * header_keys に昇格した extras を書誌ヘッダ行にまとめる。
	 * author キーのみ「著」を付す（書誌表記）。
	 *
	 * @param list<array<string, mixed>> $extras
	 * @param list<string>               $header_keys
	 */
	private function renderMetaHeader( array $extras, array $header_keys ): string {
		$parts = array();
		foreach ( $header_keys as $key ) {
			$value = $this->extraValueByKey( $extras, $key );
			if ( '' === $value ) {
				continue;
			}
			// 著者キーのみ「著」を付す（書誌表記）。
			$parts[] = 'author' === $key ? esc_html( $value ) . esc_html__( ' 著', 'affilicard' ) : esc_html( $value );
		}
		if ( array() === $parts ) {
			return '';
		}
		return '<div class="affilicard-card__meta">' . implode( ' ／ ', $parts ) . '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $extras
	 */
	private function extraValueByKey( array $extras, string $key ): string {
		// 同じ key が複数あれば最初のマッチを返す。
		foreach ( $extras as $extra ) {
			if ( ! is_array( $extra ) ) {
				continue;
			}
			$k = isset( $extra['key'] ) ? (string) $extra['key'] : '';
			if ( $k === $key ) {
				return isset( $extra['value'] ) ? trim( (string) $extra['value'] ) : '';
			}
		}
		return '';
	}

	/**
	 * 表示中（API確認済み・鮮度内＝PriceFreshness::isPriceDisplayable）listing のうち
	 * 最新 last_verified_at（ISO8601）から「※ YYYY年M月D日 HH:MM時点の価格です。…」を生成する。
	 * 日付のみだと「時点」が最大24時間の幅を持ち、規約（価格は取得後24h以内）に照らして
	 * 期限超過に見え得るため、時刻（サイトのタイムゾーン）まで明示して鮮度を一意にする。
	 * 表示中の価格 listing が1件も無ければ空文字（免責文言は出さない＝手動/未確認/期限切れのみの
	 * カードでは価格の裏付けが無いため注記自体を出さない）。
	 *
	 * @param list<array<string, mixed>>        $listings 対象 listing 群（visibleListings() の戻り）
	 * @param array<string, PlatformDefinition> $by_code  code => PlatformDefinition
	 */
	private function renderTimestamp( array $listings, array $by_code ): string {
		$now_ts = time();
		$latest = 0;
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$code     = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			$platform = '' !== $code && isset( $by_code[ $code ] ) ? $by_code[ $code ] : null;
			if ( ! PriceFreshness::isPriceDisplayable( $listing, $platform, $now_ts ) ) {
				// CTA 行の価格スパンと同じ集合（表示中のみ）に揃える。
				continue;
			}
			$at = isset( $listing['last_verified_at'] ) ? trim( (string) $listing['last_verified_at'] ) : '';
			$ts = '' !== $at ? strtotime( $at ) : false;
			if ( false !== $ts && $ts > $latest ) {
				$latest = $ts;
			}
		}
		if ( 0 === $latest ) {
			return '';
		}
		// 日付＋時刻（サイトのタイムゾーン）まで表示する。日付のみだと最大24時間の幅が生まれ、
		// 規約（Amazon Creators API/楽天/DMM とも価格は取得後24h以内の表示）に照らして期限超過に
		// 見え得るため、時刻を添えて「いつ確認したか」を一意にする。
		$date = (string) wp_date( 'Y年n月j日 H:i', $latest );
		$note = sprintf(
			/* translators: %s: formatted date and time */
			(string) __( '※ %s時点の価格です。最新価格は各販売サイトでご確認ください。', 'affilicard' ),
			$date
		);
		return '<div class="affilicard-card__timestamp">' . esc_html( $note ) . '</div>';
	}

	/**
	 * @param list<array<string, mixed>>        $listings
	 * @param array<string, PlatformDefinition> $by_code
	 * @param list<string>                      $hide
	 * @param list<string>                      $only          許可リスト（空 = 全表示）
	 * @param array<string, string>             $cta_overrides ブロック属性由来の CTA ラベル上書き（code→label）
	 */
	/**
	 * 表示対象（platform 既知・hide 非該当・only 許可・platform/listing 有効）の listing だけを、
	 * platform の displayOrder 昇順（同値は元の出現順）で返す。
	 * CTA 行（renderListings）と日時フッター（renderTimestamp）が同一集合・同一順序を見るための共有フィルタ。
	 *
	 * @param list<array<string, mixed>>        $listings
	 * @param array<string, PlatformDefinition> $by_code
	 * @param list<string>                      $hide
	 * @param list<string>                      $only     許可リスト（空 = 全表示）
	 * @return list<array<string, mixed>>
	 */
	private function visibleListings( array $listings, array $by_code, array $hide, array $only ): array {
		$out = array();
		foreach ( $listings as $listing ) {
			if ( ! is_array( $listing ) ) {
				continue;
			}
			$code = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			if ( '' === $code || ! isset( $by_code[ $code ] ) || in_array( $code, $hide, true ) ) {
				continue;
			}
			if ( ! empty( $only ) && ! in_array( $code, $only, true ) ) {
				continue;
			}
			if ( ! $by_code[ $code ]->enabled ) {
				continue;
			}
			if ( isset( $listing['enabled'] ) && false === (bool) $listing['enabled'] ) {
				continue;
			}
			// URL が無い listing は CTA 行を出さない＝非表示扱い。
			// renderListings の行と renderTimestamp の日付計算を同一集合に揃える。
			$affiliate = isset( $listing['affiliate_url'] ) ? trim( (string) $listing['affiliate_url'] ) : '';
			$regular   = isset( $listing['regular_url'] ) ? trim( (string) $listing['regular_url'] ) : '';
			if ( '' === $affiliate && '' === $regular ) {
				continue;
			}
			$out[] = $listing;
		}
		return $this->sortByDisplayOrder( $out, $by_code );
	}

	/**
	 * listing を platform の displayOrder 昇順に並べ替える。同値は元の出現順を保つ。
	 *
	 * CTA 行の並びを listing の登録順から切り離すのが目的。listing を後から追記する運用
	 * （生成後に別ストアの listing を merge する等）では登録順がカードごとにばらつき、
	 * 同一記事内でボタン位置が食い違うため、表示順はプラットフォーム設定を単一の出所とする。
	 *
	 * PHP 8.0 以降の usort は安定ソートだが、意図を明示するため元 index を第 2 キーにする。
	 *
	 * @param list<array<string, mixed>>        $listings visibleListings() でフィルタ済みの listing
	 * @param array<string, PlatformDefinition> $by_code  code => PlatformDefinition（全 code 存在が保証済み）
	 * @return list<array<string, mixed>>
	 */
	private function sortByDisplayOrder( array $listings, array $by_code ): array {
		$indexed = array();
		foreach ( $listings as $index => $listing ) {
			$indexed[] = array(
				'index'   => $index,
				'order'   => $by_code[ (string) $listing['platform'] ]->displayOrder,
				'listing' => $listing,
			);
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				if ( $a['order'] === $b['order'] ) {
					return $a['index'] <=> $b['index'];
				}
				return $a['order'] <=> $b['order'];
			}
		);

		$sorted = array();
		foreach ( $indexed as $entry ) {
			$sorted[] = $entry['listing'];
		}
		return $sorted;
	}

	/**
	 * 表示中 listing のうち image_url 非空のものから imagePriority 順で 1 枚選ぶ。
	 * 同値は displayOrder 昇順 → 出現順。無ければ $fallback（WP アイキャッチ）。
	 *
	 * @param list<array<string, mixed>>        $visibleListings visibleListings() の戻り
	 * @param array<string, PlatformDefinition> $by_code       code => PlatformDefinition
	 */
	private function selectCardImage( array $visibleListings, array $by_code, string $fallback ): string {
		$best_url      = '';
		$best_priority = PHP_INT_MAX;
		$best_order    = PHP_INT_MAX;
		foreach ( $visibleListings as $listing ) {
			$img = isset( $listing['image_url'] ) ? trim( (string) $listing['image_url'] ) : '';
			$img = esc_url_raw( $img );
			if ( '' === $img ) {
				continue;
			}
			$code     = isset( $listing['platform'] ) ? (string) $listing['platform'] : '';
			$def      = $by_code[ $code ] ?? null;
			$priority = $def instanceof PlatformDefinition ? $def->imagePriority : 999;
			$order    = $def instanceof PlatformDefinition ? $def->displayOrder : 999;
			if ( $priority < $best_priority || ( $priority === $best_priority && $order < $best_order ) ) {
				$best_url      = $img;
				$best_priority = $priority;
				$best_order    = $order;
			}
		}
		return '' !== $best_url ? $best_url : $fallback;
	}

	private function renderListings( array $listings, array $by_code, array $hide, array $only, array $cta_overrides = array(), bool $is_preorder = false ): string {
		$rows   = '';
		$now_ts = time();
		foreach ( $this->visibleListings( $listings, $by_code, $hide, $only ) as $listing ) {
			$code     = (string) $listing['platform'];
			$platform = $by_code[ $code ];

			$affiliate = isset( $listing['affiliate_url'] ) ? trim( (string) $listing['affiliate_url'] ) : '';
			$regular   = isset( $listing['regular_url'] ) ? trim( (string) $listing['regular_url'] ) : '';
			$url       = '' !== $affiliate ? $affiliate : $regular;
			if ( '' === $url ) {
				continue;
			}

			$block_override = isset( $cta_overrides[ $code ] ) ? trim( (string) $cta_overrides[ $code ] ) : '';
			$override       = isset( $listing['button_label_override'] ) ? trim( (string) $listing['button_label_override'] ) : '';
			if ( '' !== $block_override ) {
				$label = $block_override;
			} elseif ( '' !== $override ) {
				$label = $override;
			} elseif ( $is_preorder ) {
				$label = (string) __( '予約する', 'affilicard' );
			} else {
				$label = $platform->buttonLabel;
			}

			// CTA はプラットフォーム別ブランド色を維持（block 注入の --affilicard-cta-* で上書き可能）。
			$brand = (string) sanitize_hex_color( $platform->brandColor );
			if ( '' === $brand ) {
				$brand = '#444444';
			}
			$text = (string) sanitize_hex_color( $platform->buttonTextColor );
			if ( '' === $text ) {
				$text = '#ffffff';
			}
			$btn_style = 'background:var(--affilicard-cta-bg,' . $brand . ');color:var(--affilicard-cta-text,' . $text . ');';

			// 価格エリア（通常価格(取り消し線) + ¥価格 + （税込） + 割引バッジ）。
			// API 確認済み・鮮度内（PriceFreshness::isPriceDisplayable）のときだけ表示する。
			// 手動入力／未確認／TTL 期限切れの listing は CTA ボタンのみを残す。
			$pricing = '';
			if ( PriceFreshness::isPriceDisplayable( $listing, $platform, $now_ts ) ) {
				$price    = isset( $listing['price'] ) ? trim( (string) $listing['price'] ) : '';
				$list_raw = isset( $listing['list_price'] ) ? trim( (string) $listing['list_price'] ) : '';

				// 通常価格(取り消し線): list_price と price が共に正の数値で list_price > price のときのみ。
				$list_num  = self::priceToNumber( $list_raw );
				$price_num = self::priceToNumber( $price );
				if ( null !== $list_num && null !== $price_num && $list_num > $price_num ) {
					$list_no_yen = (string) preg_replace( '/^[\x{00A5}\x{FFE5}\s]+/u', '', $list_raw );
					$pricing    .= '<span class="affilicard-card__list-price">¥' . esc_html( $list_no_yen ) . '</span>';
				}

				if ( '' !== $price ) {
					// 先頭の半角¥(U+00A5)/全角￥(U+FFE5)/空白のみを安全に除去（ltrim のバイト単位破壊を回避）。
					$price_no_yen = (string) preg_replace( '/^[\x{00A5}\x{FFE5}\s]+/u', '', $price );
					$pricing     .= '<span class="affilicard-card__price">¥' . esc_html( $price_no_yen ) . '</span>';
					$pricing     .= '<span class="affilicard-card__tax">' . esc_html__( '（税込）', 'affilicard' ) . '</span>';
				}
				$badge = isset( $listing['badge'] ) ? trim( (string) $listing['badge'] ) : '';
				if ( '' !== $badge ) {
					$pricing .= '<span class="affilicard-card__discount">' . esc_html( $badge ) . '</span>';
				}
			}

			$rows .= '<li class="affilicard-card__row">'
				. '<div class="affilicard-card__platform">' . esc_html( $platform->name ) . '</div>'
				. '<div class="affilicard-card__pricing">' . $pricing . '</div>'
				. '<a class="affilicard-card__cta" href="' . esc_url( $url ) . '" target="_blank" rel="nofollow sponsored noopener" style="' . esc_attr( $btn_style ) . '">' . esc_html( $label ) . '</a>'
				. '</li>';
		}
		return '' === $rows ? '' : '<ul class="affilicard-card__listings">' . $rows . '</ul>';
	}

	/**
	 * 価格文字列を比較用の正の数値に変換する。¥/￥/カンマ/空白を除去し、
	 * is_numeric かつ 0 より大きいなら float を返す。それ以外は null。
	 */
	private static function priceToNumber( string $raw ): ?float {
		$normalized = preg_replace( '/[\x{00A5}\x{FFE5},\s]/u', '', $raw );
		if ( null === $normalized || '' === $normalized || ! is_numeric( $normalized ) ) {
			return null;
		}
		$num = (float) $normalized;
		return $num > 0 ? $num : null;
	}
}
