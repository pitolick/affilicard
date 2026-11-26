<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * 新しい商品の既定のタイプを決める（設定「既定の商品タイプ」）。
 *
 * - 商品編集画面で新しく作る商品: 設定の値をそのまま使う（{@see self::forNewProduct()}）
 * - ストアの URL から自動で作る商品: そのストアの「対象タイプ」に設定の値が含まれていれば
 *   それを、含まれていなければストアの対象タイプの先頭を使う（{@see self::forPlatform()}）。
 *   既定が「動画配信」のサイトで電子書籍ストアの商品を自動で作ったとき、動画配信の商品に
 *   ならないようにするため
 *
 * REST でタイプを指定せずに作ったときの既定（generic）は変えない。外から商品を作る処理
 * （利用側のサイトの投稿スクリプト）の動きを、設定ひとつで変えないため。
 */
final class DefaultProductType {

	public static function forNewProduct( string $configured, ProductTypeRegistry $registry ): string {
		return null !== $registry->get( $configured ) ? $configured : 'generic';
	}

	/**
	 * @param list<string> $applicableTypes ストアの対象タイプ（PlatformDefinition::$applicableTypes）
	 */
	public static function forPlatform( string $configured, array $applicableTypes, ProductTypeRegistry $registry ): string {
		$known = array_values(
			array_filter(
				array_map( 'strval', $applicableTypes ),
				static fn( string $code ): bool => null !== $registry->get( $code )
			)
		);
		if ( array() === $known || in_array( $configured, $known, true ) ) {
			return self::forNewProduct( $configured, $registry );
		}
		return $known[0];
	}
}
