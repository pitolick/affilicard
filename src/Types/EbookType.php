<?php
declare(strict_types=1);

namespace Affilicard\Types;

/**
 * 電子書籍タイプ。推奨フィールドは著者・出版社・ISBN の 3 つ。
 */
final class EbookType extends AbstractProductType {

	public function code(): string {
		return 'ebook';
	}

	public function label(): string {
		return __( '電子書籍', 'affilicard' );
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	public function extrasSchema(): array {
		return array(
			array(
				'key'   => 'author',
				'label' => __( '著者', 'affilicard' ),
			),
			array(
				'key'   => 'publisher',
				'label' => __( '出版社', 'affilicard' ),
			),
			array(
				'key'   => 'isbn',
				'label' => __( 'ISBN', 'affilicard' ),
			),
		);
	}

	/**
	 * DMM Books の raw 構造から著者/出版社/ISBN を抽出する。
	 *
	 * 想定 raw shape:
	 *   {
	 *     iteminfo: { author: [{name: '...'}, ...], maker: [{name: '...'}, ...] },
	 *     isbn: '...'
	 *   }
	 *
	 * @param array<string, mixed> $providerRaw
	 * @return list<array{key?: string, label: string, value: string}>
	 */
	public function extractExtrasFromProvider( string $providerCode, array $providerRaw ): array {
		if ( 'dmm-ebook' !== $providerCode ) {
			return array();
		}

		$result = array();

		$author = self::extractFirstName( $providerRaw, 'author' );
		if ( '' !== $author ) {
			$result[] = array(
				'key'   => 'author',
				'label' => __( '著者', 'affilicard' ),
				'value' => $author,
			);
		}

		$publisher = self::extractFirstName( $providerRaw, 'maker' );
		if ( '' !== $publisher ) {
			$result[] = array(
				'key'   => 'publisher',
				'label' => __( '出版社', 'affilicard' ),
				'value' => $publisher,
			);
		}

		$isbn = '';
		if ( isset( $providerRaw['isbn'] ) && is_scalar( $providerRaw['isbn'] ) ) {
			$isbn = trim( (string) $providerRaw['isbn'] );
		}
		if ( '' !== $isbn ) {
			$result[] = array(
				'key'   => 'isbn',
				'label' => __( 'ISBN', 'affilicard' ),
				'value' => $isbn,
			);
		}

		return $result;
	}

	/**
	 * iteminfo.{group} の最初の name を取り出す。欠損や型不一致は空文字。
	 *
	 * @param array<string, mixed> $providerRaw
	 */
	private static function extractFirstName( array $providerRaw, string $group ): string {
		if ( ! isset( $providerRaw['iteminfo'] ) || ! is_array( $providerRaw['iteminfo'] ) ) {
			return '';
		}
		if ( ! isset( $providerRaw['iteminfo'][ $group ] ) || ! is_array( $providerRaw['iteminfo'][ $group ] ) ) {
			return '';
		}
		$entries = $providerRaw['iteminfo'][ $group ];
		if ( array() === $entries ) {
			return '';
		}
		$first = $entries[0];
		if ( ! is_array( $first ) || ! isset( $first['name'] ) || ! is_scalar( $first['name'] ) ) {
			return '';
		}
		return trim( (string) $first['name'] );
	}
}
