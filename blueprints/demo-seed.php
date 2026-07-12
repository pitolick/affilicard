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

$listing = static function ( string $platform, string $aff, string $price, string $badge = '', string $list_price = '', string $fetched = '2026-04-20T10:30:00+09:00' ): array {
	return array(
		'platform'        => $platform,
		'enabled'         => true,
		'affiliate_url'   => $aff,
		'regular_url'     => '',
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
$set_demo_cover = static function ( int $post_id, string $label, string $bg, string $ratio = 'portrait' ): void {
	if ( $post_id <= 0 ) {
		return;
	}
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return;
	}
	$safe_label = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );

	if ( 'portrait' === $ratio ) {
		// 既存の書影マスク確認サンプル向け。挙動維持のためマークアップは変更しない。
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 560" width="400" height="560">'
			. '<rect width="400" height="560" fill="' . $bg . '"/>'
			. '<rect x="28" y="70" width="344" height="180" fill="#ffffff" opacity="0.28"/>'
			. '<rect x="28" y="380" width="344" height="90" fill="#ffffff" opacity="0.28"/>'
			. '<circle cx="200" cy="300" r="70" fill="#ffffff" opacity="0.22"/>'
			. '<text x="40" y="52" font-family="sans-serif" font-size="26" font-weight="700" fill="#ffffff">AFFILICARD DEMO</text>'
			. '<text x="40" y="520" font-family="sans-serif" font-size="30" font-weight="700" fill="#ffffff">' . $safe_label . '</text>'
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

	$filename = 'affilicard-demo-cover-' . $post_id . '.svg';
	$file     = trailingslashit( $uploads['path'] ) . $filename;
	if ( false === file_put_contents( $file, $svg ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return;
	}

	$attach_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => 'Affilicard demo cover ' . $post_id,
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $uploads['url'] ) . $filename,
		),
		$file,
		$post_id
	);
	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		return;
	}
	// SVG はサブサイズを持たないため _wp_attached_file だけ設定すれば
	// wp_get_attachment_image_url( $id, 'medium' ) がフルサイズ URL を返す。
	update_post_meta( $attach_id, '_wp_attached_file', _wp_relative_upload_path( $file ) );
	set_post_thumbnail( $post_id, $attach_id );
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

$block = static function ( int $id, array $attrs = array() ): string {
	$a = array_merge( array( 'productId' => $id ), $attrs );
	return '<!-- wp:affilicard/product-card ' . wp_json_encode( $a ) . ' /-->';
};

$content = implode(
	"\n\n",
	array(
		'<!-- wp:heading --><h2>Affilicard デモ（ダミーデータ）</h2><!-- /wp:heading -->',
		'<!-- wp:heading {"level":3} --><h3>予約（発売前）カード確認</h3><!-- /wp:heading -->',
		'<!-- wp:paragraph --><p>上＝発売前（予約受付中・「予約する」・発売日表示）／下＝発売済み（通常表示）。発売日を過ぎると上のカードも自動で通常表示へ戻ります。</p><!-- /wp:paragraph -->',
		$block( $p_preorder ),
		$block( $p_released ),
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
