<?php
/**
 * WordPress Playground プレビュー用のダミーデータ seed。
 * blueprint の runPHP から require される（本番 zip には含まれない）。
 * 冪等: affilicard_demo_seeded オプションで二重実行を防ぐ。
 */

if ( ! class_exists( '\Affilicard\Repository\ProductRepository' ) ) {
	return;
}
if ( get_option( 'affilicard_demo_seeded' ) ) {
	return;
}

$repo = new \Affilicard\Repository\ProductRepository();

// **通常 URL（regular_url）は空にしない。** v4.0.0 から、保存時に購入リンクの身元
// （external_id か regular_url）を 1 つも持たないものは捨てられる（ProductSchema::sanitizeOffers()。
// アフィリエイト URL はリダイレクタが転送先の生死に関係なく 302 を返すため身元にならない）。
// ここを空のままにすると、デモ商品はすべて購入リンク 0 件になり、プレビューのカードから
// 価格も購入ボタンも消える。通常 URL はアフィリエイト URL から機械的に作る。
$listing = static function ( string $platform, string $aff, string $price, string $badge = '', string $list_price = '', string $fetched = '2026-04-20T10:30:00+09:00' ): array {
	return array(
		'platform'        => $platform,
		'enabled'         => true,
		'affiliate_url'   => $aff,
		'regular_url'     => (string) preg_replace( '#^https://example\.com/(?:aff-)?#', 'https://example.com/product/', $aff ),
		'price'           => $price,
		'list_price'      => $list_price,
		'badge'           => $badge,
		'last_fetched_at' => $fetched,
	);
};

$p_generic = $repo->save(
	array(
		'title'        => 'サンプル雑貨（汎用・在庫あり）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-generic', '1,200', '', '1,500' ) ),
	)
);

$p_ebook = $repo->save(
	array(
		'title'        => 'サンプル漫画 1巻（電子書籍・複数ストア）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'content'      => "<!-- wp:paragraph -->\n<p>架空のダンジョンを舞台にした冒険グルメ漫画のサンプル紹介文です。書影・著者・あらすじ・複数ストアの価格行・税込表記・価格時点フッタの表示確認用ダミーデータ。</p>\n<!-- /wp:paragraph -->",
		'listings'     => array(
			$listing( 'dmm-books', 'https://example.com/dmm', '600', '40%OFF', '1,000' ),
			$listing( 'amazon-kindle', 'https://example.com/amz', '660', '50%ポイント還元' ),
			$listing( 'rakuten-kobo', 'https://example.com/kobo', '640', '', '900' ),
		),
		'extras'       => array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => '架空 太郎',
			),
			array(
				'key'   => 'publisher',
				'label' => '出版社',
				'value' => 'サンプル出版社',
			),
			array(
				'key'   => 'isbn',
				'label' => 'ISBN',
				'value' => '978-4-00-000000-0',
			),
		),
	)
);

$p_out = $repo->save(
	array(
		'title'        => 'サンプル（在庫切れ）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'out_of_stock',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-out', '980' ) ),
	)
);

$p_disc = $repo->save(
	array(
		'title'        => 'サンプル（取扱終了）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'discontinued',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-disc', '800' ) ),
	)
);

// 予約（発売前）商品: release_date が未来 → カードは「予約受付中」バッジ＋CTA「予約する」＋発売日を表示する。
$p_preorder = $repo->save(
	array(
		'title'        => 'サンプル新刊 5巻（予約・発売前）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'release_date' => gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) ),
		'content'      => "<!-- wp:paragraph -->\n<p>発売前の新刊サンプル。発売日までは予約カード（予約受付中バッジ・CTA「予約する」・発売日表示）になり、発売日を過ぎると自動で通常表示へ戻る確認用ダミーデータ。</p>\n<!-- /wp:paragraph -->",
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-preorder', '700' ) ),
		'extras'       => array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => '架空 花子',
			),
			array(
				'key'   => 'publisher',
				'label' => '出版社',
				'value' => 'サンプル出版社',
			),
		),
	)
);

// 発売済み（release_date が過去）商品: 予約表示にならず通常カードになる対照サンプル。
$p_released = $repo->save(
	array(
		'title'        => 'サンプル既刊 1巻（発売済み・対照）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'release_date' => gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ),
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-released', '600' ) ),
		'extras'       => array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => '架空 花子',
			),
			array(
				'key'   => 'publisher',
				'label' => '出版社',
				'value' => 'サンプル出版社',
			),
		),
	)
);

// 表紙マスク確認用サンプル（架空データ）。
$p_mask_blur = $repo->save(
	array(
		'title'        => 'サンプル作品（ぼかしのみ）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'mask_blur'    => true,
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-blur', '700' ) ),
	)
);

$p_mask_label = $repo->save(
	array(
		'title'        => 'サンプル作品（ぼかし＋ラベル）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'mask_blur'    => true,
		'mask_label'   => '刺激的な表現を含みます',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-label', '700' ) ),
	)
);

$p_mask_r18 = $repo->save(
	array(
		'title'        => 'サンプル作品（R18・18+ バッジ）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'mask_r18'     => true,
		'mask_label'   => '成人向け',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-r18', '700' ) ),
	)
);

// ブロック優先の対照: 商品側は mask 無しだが、ブロック属性でぼかしを上書きする。
$p_mask_inherit = $repo->save(
	array(
		'title'        => 'サンプル作品（商品側マスク無し・ブロックで上書き）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-inherit', '700' ) ),
	)
);

// メディア枠（type 別アスペクト比＋contain＋プレースホルダ）確認用サンプル。
// vod（画像あり）: 正方に近いデモ画像で 1/1 枠にキービジュアルが収まることを確認。
$p_vod_image = $repo->save(
	array(
		'title'        => 'サンプル映像作品（VOD・キービジュアル）',
		'status'       => 'publish',
		'product_type' => 'vod',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-vod-image', '2,000' ) ),
	)
);

// generic（画像なし）: アイキャッチ未設定 → プレースホルダ「商品画像」＋1/1 枠を確認。
$p_generic_noimage = $repo->save(
	array(
		'title'        => 'サンプル雑貨（画像なし・プレースホルダ）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-generic-noimage', '900' ) ),
	)
);

// ebook（画像なし）: アイキャッチ未設定 → プレースホルダ「書影」＋2/3 枠を確認。
$p_ebook_noimage = $repo->save(
	array(
		'title'        => 'サンプル漫画（書影なし・プレースホルダ）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-ebook-noimage', '600' ) ),
	)
);

// vod（画像なし）: アイキャッチ未設定 → プレースホルダ「キービジュアル」＋1/1 枠を確認。
$p_vod_noimage = $repo->save(
	array(
		'title'        => 'サンプル映像作品（画像なし・プレースホルダ）',
		'status'       => 'publish',
		'product_type' => 'vod',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-vod-noimage', '2,200' ) ),
	)
);

// generic（横長画像）: 1/1 枠に object-fit: contain でレターボックス収まり、
// 隣の本文カラムが崩れないことを確認するレイアウト頑健性サンプル。
$p_generic_landscape = $repo->save(
	array(
		'title'        => 'サンプル雑貨（横長画像・contain 確認）',
		'status'       => 'publish',
		'product_type' => 'generic',
		'stock_status' => 'available',
		'listings'     => array( $listing( 'dmm-books', 'https://example.com/aff-generic-landscape', '1,800' ) ),
	)
);

// 表紙マスク（ぼかし／R18／ラベル）は書影 <img> に掛かるため、マスク確認用サンプルには
// アイキャッチ（book cover 相当）が必要。GD/Imagick 非依存でダミー書影を生成するため SVG を
// uploads に書き出して添付する（Playground の PHP に画像拡張が無くても確実に書影が出る）。
// SVG を <img> で表示しても CSS filter: blur() は効くのでぼかしの視認に問題はない。
// $ratio: 'portrait'(既定・書影マスク確認用) / 'square'(vod 近似正方) / 'landscape'(object-fit:contain 確認用の横長)。
//
// SVG の組み立て・ファイル書き出し・添付ファイル登録は「アイキャッチに設定する」
// （$set_demo_cover）と「listing の image_url 用に URL だけ欲しい」（$demo_cover_url、
// 表示順デモでストアごとに違う書影を用意するために使う）の両方から必要なため、
// 共通処理として 1 か所に切り出す（重複させない）。
// $slug はファイル名の一意化に使う。$set_demo_cover は 1 投稿につき書影 1 枚のため
// post_id で足りるが、$demo_cover_url は 1 投稿に複数枚（プラットフォームごと）の
// 書影を持たせるケースがあるため、呼び出し元で platform 等を含めた一意な値を渡す。
$build_demo_cover_attachment = static function ( int $parent_post_id, string $slug, string $label, string $bg, string $ratio = 'portrait' ): int {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return 0;
	}
	$safe_label = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );

	if ( 'portrait' === $ratio ) {
		// 電子書籍(ebook)の書影・マスク確認サンプル向け。キャンバスをフレーム比率と
		// 同じ 2:3(400x600)にし、object-fit:contain で上下に余白が出ないようにする。
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 600" width="400" height="600">'
			. '<rect width="400" height="600" fill="' . $bg . '"/>'
			. '<rect x="28" y="80" width="344" height="190" fill="#ffffff" opacity="0.28"/>'
			. '<rect x="28" y="410" width="344" height="96" fill="#ffffff" opacity="0.28"/>'
			. '<circle cx="200" cy="320" r="72" fill="#ffffff" opacity="0.22"/>'
			. '<text x="40" y="56" font-family="sans-serif" font-size="26" font-weight="700" fill="#ffffff">AFFILICARD DEMO</text>'
			. '<text x="40" y="560" font-family="sans-serif" font-size="30" font-weight="700" fill="#ffffff">' . $safe_label . '</text>'
			. '</svg>';
	} else {
		// vod 近似正方(square)・contain 確認用の横長(landscape)。
		$dimensions             = array(
			'square'    => array( 480, 480 ),
			'landscape' => array( 800, 320 ),
		);
		list( $width, $height ) = $dimensions[ $ratio ] ?? $dimensions['square'];
		$svg                    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '">'
			. '<rect width="' . $width . '" height="' . $height . '" fill="' . $bg . '"/>'
			. '<circle cx="' . ( $width / 2 ) . '" cy="' . ( $height / 2 ) . '" r="' . ( min( $width, $height ) * 0.3 ) . '" fill="#ffffff" opacity="0.22"/>'
			. '<text x="24" y="40" font-family="sans-serif" font-size="22" font-weight="700" fill="#ffffff">AFFILICARD DEMO</text>'
			. '<text x="24" y="' . ( $height - 24 ) . '" font-family="sans-serif" font-size="26" font-weight="700" fill="#ffffff">' . $safe_label . '</text>'
			. '</svg>';
	}

	$filename = 'affilicard-demo-cover-' . $slug . '.svg';
	$file     = trailingslashit( $uploads['path'] ) . $filename;
	if ( false === file_put_contents( $file, $svg ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return 0;
	}

	$attach_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => 'Affilicard demo cover ' . $slug,
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $uploads['url'] ) . $filename,
		),
		$file,
		$parent_post_id
	);
	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		return 0;
	}
	// SVG はサブサイズを持たないため _wp_attached_file だけ設定すれば
	// wp_get_attachment_image_url( $id, 'medium' ) がフルサイズ URL を返す。
	update_post_meta( $attach_id, '_wp_attached_file', _wp_relative_upload_path( $file ) );
	return (int) $attach_id;
};

$set_demo_cover = static function ( int $post_id, string $label, string $bg, string $ratio = 'portrait' ) use ( $build_demo_cover_attachment ): void {
	if ( $post_id <= 0 ) {
		return;
	}
	$attach_id = $build_demo_cover_attachment( $post_id, (string) $post_id, $label, $bg, $ratio );
	if ( $attach_id > 0 ) {
		set_post_thumbnail( $post_id, $attach_id );
	}
};

// listing の image_url 用にダミー書影の URL だけを返す（アイキャッチには設定しない）。
// 表示順デモ商品で、プラットフォームごとに見た目の違う書影を用意するために使う。
$demo_cover_url = static function ( string $slug, string $label, string $bg, string $ratio = 'portrait' ) use ( $build_demo_cover_attachment ): string {
	$attach_id = $build_demo_cover_attachment( 0, $slug, $label, $bg, $ratio );
	if ( $attach_id <= 0 ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $attach_id, 'medium' );
	return is_string( $url ) ? $url : '';
};

// マスク確認セクションの各サンプル（＋「なし」対照の $p_ebook）にダミー書影を設定。
$set_demo_cover( (int) $p_ebook, 'NO MASK', '#2f5c94' );
$set_demo_cover( (int) $p_mask_blur, 'BLUR', '#944828' );
$set_demo_cover( (int) $p_mask_label, 'BLUR + LABEL', '#783078' );
$set_demo_cover( (int) $p_mask_r18, 'R18', '#963028' );
$set_demo_cover( (int) $p_mask_inherit, 'BLOCK OVERRIDE', '#347860' );

// メディア枠確認セクション: 画像ありの 2 サンプルにのみデモ画像を設定
// （画像なし 3 サンプルはアイキャッチ未設定のままプレースホルダを確認する）。
$set_demo_cover( (int) $p_vod_image, 'KEY VISUAL', '#1f7a5c', 'square' );
$set_demo_cover( (int) $p_generic_landscape, 'LANDSCAPE', '#7a5c1f', 'landscape' );

// 表示順デモ: プラットフォーム設定（設定 → プラットフォーム → 電子書籍タブ）で
// 表示順を並べ替えて保存すると、カードの書影が表示順の先頭 listing の画像に切り替わる
// ことを目視確認するためのサンプル。dmm-books / amazon-kindle / rakuten-kobo の
// 3 listing それぞれに見た目で区別できる書影を持たせる。
$p_display_order = $repo->save(
	array(
		'title'        => 'サンプル漫画 2巻（書影が表示順に追従）',
		'status'       => 'publish',
		'product_type' => 'ebook',
		'stock_status' => 'available',
		'content'      => "<!-- wp:paragraph -->\n<p>設定 → プラットフォーム → 電子書籍タブで表示順を並べ替えて保存すると、このカードの書影が新しい先頭 listing の画像に切り替わることを確認できるダミーデータです。</p>\n<!-- /wp:paragraph -->",
		'listings'     => array(
			array_merge(
				$listing( 'dmm-books', 'https://example.com/aff-display-order-dmm', '600', '40%OFF', '1,000' ),
				array( 'image_url' => $demo_cover_url( 'display-order-dmm', 'DMM', '#0f6ab4' ) )
			),
			array_merge(
				$listing( 'amazon-kindle', 'https://example.com/aff-display-order-amazon', '660', '50%ポイント還元' ),
				array( 'image_url' => $demo_cover_url( 'display-order-amazon', 'AMAZON', '#ff9900' ) )
			),
			array_merge(
				$listing( 'rakuten-kobo', 'https://example.com/aff-display-order-rakuten', '640', '', '900' ),
				array( 'image_url' => $demo_cover_url( 'display-order-rakuten', 'RAKUTEN', '#bf0000' ) )
			),
		),
		'extras'       => array(
			array(
				'key'   => 'author',
				'label' => '著者',
				'value' => '架空 次郎',
			),
			array(
				'key'   => 'publisher',
				'label' => '出版社',
				'value' => 'サンプル出版社',
			),
		),
	)
);

$block = static function ( int $id, array $attrs = array() ): string {
	$a = array_merge( array( 'productId' => $id ), $attrs );
	return '<!-- wp:affilicard/product-card ' . wp_json_encode( $a ) . ' /-->';
};

// VOD（動画配信）デモ: プラットフォームのリンクテンプレート（v4.2.0）の見た目確認用。
// 購入リンクには作品ページの URL（regular_url）だけを入れ、CTA のリンク先は
// プラットフォームのテンプレートで組み立てる。テンプレートの ID（rk / af_id / tag）は架空。
//
// - Hulu        : リダイレクタで包む型（{url_enc}）
// - Prime Video : クエリを足す型（{url}{sep}tag=…）
// - DMM TV      : リダイレクタで包む型
// - U-NEXT      : テンプレートなし。作品ごとの affiliate_url で上書きする型
$vod_templates = array(
	'hulu'        => 'https://h.example.test/sp/cc?rk=DEMO&url={url_enc}',
	'prime-video' => '{url}{sep}tag=demo-22',
	'dmm-tv'      => 'https://al.example.test/?lurl={url_enc}&af_id=demo-001&ch=link_tool&ch_id=link',
);
$vod_platforms = array();
foreach ( \Affilicard\Platform\PlatformConfig::all() as $definition ) {
	$arr = $definition->toArray();
	if ( isset( $vod_templates[ $definition->code ] ) ) {
		$arr['linkTemplate'] = $vod_templates[ $definition->code ];
	}
	$vod_platforms[] = $arr;
}
\Affilicard\Platform\PlatformConfig::save( $vod_platforms );

// VOD の購入リンク。価格は持たず、提供形態のラベル（plan_label）で「見放題」「レンタル」を出す。
// plan_label は価格の主張ではないので、価格を表示しない購入リンクでもカードに出る。
$vod_listing = static function ( string $platform, string $regular, string $plan_label = '', string $affiliate = '' ): array {
	return array(
		'platform'      => $platform,
		'enabled'       => true,
		'affiliate_url' => $affiliate,
		'regular_url'   => $regular,
		'price'         => '',
		'plan_label'    => $plan_label,
	);
};

$vod_extras = static function ( string $director, string $cast, string $year, string $distributor ): array {
	return array(
		array(
			'key'   => 'director',
			'label' => '監督',
			'value' => $director,
		),
		array(
			'key'   => 'cast',
			'label' => '出演',
			'value' => $cast,
		),
		array(
			'key'   => 'release_year',
			'label' => '公開年',
			'value' => $year,
		),
		array(
			'key'   => 'distributor',
			'label' => '配給',
			'value' => $distributor,
		),
	);
};

// 4 サービスすべてで配信している作品（テンプレート 3 種 + 作品ごとの上書き 1 件）。
$p_vod_all = $repo->save(
	array(
		'title'        => 'サンプル映画 ザ・デモンストレーション',
		'status'       => 'publish',
		'product_type' => 'vod',
		'stock_status' => 'available',
		'content'      => "<!-- wp:paragraph -->\n<p>架空の映画のサンプル紹介文です。4 つの配信サービスの購入リンクと、監督・出演・公開年・配給の表示確認用ダミーデータ。</p>\n<!-- /wp:paragraph -->",
		'listings'     => array(
			$vod_listing( 'u-next', 'https://video.unext.jp/title/SID0000001', '見放題', 'https://t.example.test/visit.php?a=DEMO-UNEXT' ),
			$vod_listing( 'hulu', 'https://www.hulu.jp/the-demonstration', '見放題' ),
			$vod_listing( 'prime-video', 'https://www.amazon.co.jp/gp/video/detail/B0DEMO0001', 'レンタル' ),
			$vod_listing( 'dmm-tv', 'https://tv.dmm.com/vod/detail/?season=demo0001', '見放題' ),
		),
		'extras'       => $vod_extras( '架空 監督', '架空 主演、架空 助演', '2024', 'サンプル配給' ),
	)
);

// 1 サービスだけの作品（一般的なケース）。
$p_vod_single = $repo->save(
	array(
		'title'        => 'サンプルアニメ映画 劇場版デモ',
		'status'       => 'publish',
		'product_type' => 'vod',
		'stock_status' => 'available',
		'listings'     => array(
			$vod_listing( 'prime-video', 'https://www.amazon.co.jp/gp/video/detail/B0DEMO0002', '見放題' ),
		),
		'extras'       => $vod_extras( '架空 演出', '架空 声優', '2023', 'サンプルアニメーション' ),
	)
);

// 対照: U-NEXT の作品 URL だけ（テンプレートなし・上書きなし）→ 通常 URL へフォールバックし、
// 管理画面（商品一覧の警告列・ダッシュボード）にフォールバックとして出る。
$p_vod_fallback = $repo->save(
	array(
		'title'        => 'サンプル映画（U-NEXT の上書き未設定・フォールバック）',
		'status'       => 'publish',
		'product_type' => 'vod',
		'stock_status' => 'available',
		'listings'     => array(
			$vod_listing( 'u-next', 'https://video.unext.jp/title/SID0000003', '見放題' ),
		),
		'extras'       => $vod_extras( '架空 監督', '架空 俳優', '2022', 'サンプル配給' ),
	)
);

// バージョン違い（字幕版・吹替版・ディレクターズカット版）が別の商品ページになっている作品。
// 購入リンクの variant_label が違うものは、同じストアの中で 1 行ずつ並ぶ（v4.3.0）。
// 字幕版には「代わりのリンク」（同じバージョン・表示順が後ろ）も持たせ、1 件だけが出ることを確かめる。
$vod_offer = static function ( int $order, string $regular, string $variant, string $plan_label ): array {
	return array(
		'display_order' => $order,
		'regular_url'   => $regular,
		'variant_label' => $variant,
		'plan_label'    => $plan_label,
	);
};
$p_vod_variants = $repo->save(
	array(
		'title'        => 'サンプル洋画 バージョンズ',
		'status'       => 'publish',
		'product_type' => 'vod',
		'stock_status' => 'available',
		'listings'     => array(
			array(
				'platform' => 'prime-video',
				'enabled'  => true,
				'offers'   => array(
					$vod_offer( 10, 'https://www.amazon.co.jp/gp/video/detail/B0DEMOSUB1', '字幕版', '見放題' ),
					$vod_offer( 20, 'https://www.amazon.co.jp/gp/video/detail/B0DEMODUB1', '吹替版', '見放題' ),
					$vod_offer( 30, 'https://www.amazon.co.jp/gp/video/detail/B0DEMODC01', 'ディレクターズカット版', 'レンタル' ),
					$vod_offer( 40, 'https://www.amazon.co.jp/gp/video/detail/B0DEMOSUB2', '字幕版', 'レンタル' ),
				),
			),
			$vod_listing( 'hulu', 'https://www.hulu.jp/the-versions', '見放題' ),
		),
		'extras'       => $vod_extras( '架空 監督', '架空 俳優', '2021', 'サンプル配給' ),
	)
);

$set_demo_cover( (int) $p_vod_all, 'THE DEMONSTRATION', '#1d2b53', 'square' );
$set_demo_cover( (int) $p_vod_variants, 'THE VERSIONS', '#008751', 'square' );
$set_demo_cover( (int) $p_vod_single, 'ANIME MOVIE', '#7e2553', 'square' );
$set_demo_cover( (int) $p_vod_fallback, 'FALLBACK', '#5f574f', 'square' );

$content = implode(
	"\n\n",
	array(
		'<!-- wp:heading --><h2>Affilicard デモ（ダミーデータ）</h2><!-- /wp:heading -->',
		'<!-- wp:heading {"level":3} --><h3>予約（発売前）カード確認</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>上＝発売前（予約受付中・「予約する」・発売日表示）／下＝発売済み（通常表示）。発売日を過ぎると上のカードも自動で通常表示へ戻ります。</p><!-- /wp:paragraph -->',
		$block( $p_preorder ),
		$block( $p_released ),
		'<!-- wp:heading {"level":3} --><h3>VOD（動画配信）カード確認</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>購入リンクには作品ページの URL だけを入れ、ボタンのリンク先はプラットフォームのリンクテンプレートで組み立てています（Hulu・DMM TV＝リダイレクタで包む型／Prime Video＝クエリを足す型。U-NEXT は作品ごとのアフィリエイト URL で上書き）。「見放題」「レンタル」は提供形態のラベルで、価格を持たない購入リンクでも出ます。テンプレートの ID は架空です。</p><!-- /wp:paragraph -->',
		$block( $p_vod_all ),
		$block( $p_vod_single ),
		'<!-- wp:paragraph --><p>対照：テンプレートも上書きも無い購入リンク（作品ページの URL がそのままリンク先になり、管理画面ではフォールバックとして警告が出ます）。</p><!-- /wp:paragraph -->',
		$block( $p_vod_fallback ),
		'<!-- wp:heading {"level":3} --><h3>バージョン違い（字幕版・吹替版）カード確認</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>同じ作品が字幕版・吹替版・ディレクターズカット版で別の商品ページになっているとき、購入リンクの「バージョン」を分けると、同じストアの中で 1 行ずつ並びます（2 行目以降はストア名を省き、区切り線を点線にしています）。Prime Video の字幕版にはもう 1 つ「代わりのリンク」（同じバージョン・表示順が後ろのレンタル）がありますが、カードに出るのは表示順が先の 1 件だけです。バージョンを持たない Hulu は従来どおり 1 行です。</p><!-- /wp:paragraph -->',
		$block( $p_vod_variants ),
		'<!-- wp:heading {"level":3} --><h3>各種カード表示</h3><!-- /wp:heading -->',
		$block(
			$p_ebook,
			array(
				'ctaBgColor'   => '#d72d65',
				'ctaTextColor' => '#ffffff',
			)
		),
		$block(
			$p_generic,
			array(
				'cardBgColor'     => '#f6f7f7',
				'cardBorderColor' => '#c3c4c7',
			)
		),
		$block( $p_out ),
		$block( $p_disc ),
		'<!-- wp:heading {"level":3} --><h3>表示順デモ（並べ替えで書影も切り替わる）</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>設定 → プラットフォーム → 電子書籍タブで表示順を並べ替えて保存すると、下のカードの CTA ボタンだけでなく書影もストアごとに用意した別々の画像へ切り替わることを確認できます。</p><!-- /wp:paragraph -->',
		$block( $p_display_order ),
		'<!-- wp:heading {"level":3} --><h3>表紙マスク確認</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>なし／ぼかしのみ／ぼかし＋ラベル／R18（18+ バッジ＋ぼかし強制）／ブロック属性でぼかしを上書き（商品側は無し）の対照。</p><!-- /wp:paragraph -->',
		$block( $p_ebook ),
		$block( $p_mask_blur ),
		$block( $p_mask_label ),
		$block( $p_mask_r18 ),
		$block(
			$p_mask_inherit,
			array(
				'maskBlur'  => true,
				'maskLabel' => 'ブロック属性で上書き',
			)
		),
		'<!-- wp:heading {"level":3} --><h3>メディア枠（type別アスペクト比・contain・プレースホルダ）確認</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>vod（画像あり・1/1枠）／画像なし3種（generic「商品画像」・ebook「書影」・vod「キービジュアル」のプレースホルダ）／generic（横長画像・1/1枠に contain でレターボックス収まり隣の本文カラムが崩れないことを確認）。</p><!-- /wp:paragraph -->',
		$block( $p_vod_image ),
		$block( $p_generic_noimage ),
		$block( $p_ebook_noimage ),
		$block( $p_vod_noimage ),
		$block( $p_generic_landscape ),
		'<!-- wp:heading {"level":3} --><h3>商品画像を表示しない（v3.1.0）</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>カードから商品画像を出さない設定です。<strong>上＝通常表示／下＝非表示</strong>。非表示側は画像カラムごと畳んで本文が全幅になり、「画像がありません」のプレースホルダにも落ちません。</p><!-- /wp:paragraph -->',
		$block( $p_ebook ),
		$block( $p_ebook, array( 'hideMedia' => true ) ),
		'<!-- wp:paragraph --><p>ぼかし・R18 マスクを設定した商品でも、非表示側では画像そのものが出ません（下）。</p><!-- /wp:paragraph -->',
		$block( $p_mask_r18 ),
		$block( $p_mask_r18, array( 'hideMedia' => true ) ),
		'<!-- wp:paragraph --><p>ここでは 1 枚ずつ比較できるようブロック属性で上書きしていますが、<strong>設定 → 一般 →「商品画像を表示しない」</strong>でサイト全体を一括で切り替えられます。</p><!-- /wp:paragraph -->',
	)
);

$demo_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Affilicard デモ',
		'post_content' => $content,
	)
);

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', (int) $demo_page );
update_option( 'affilicard_demo_seeded', 1 );
