<?php
declare(strict_types=1);

namespace Affilicard\Platform;

use InvalidArgumentException;

/**
 * 1 つのアフィリエイトプラットフォーム（DMM ブックス・Amazon Kindle 等）を表す immutable value object。
 */
final class PlatformDefinition {

	private const ALLOWED_FREQUENCIES = array( 'daily', 'weekly' );

	/**
	 * @param list<string> $applicableTypes 適用可能な商品タイプコード（例: ['ebook'], ['generic', 'ebook']）
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $name,
		public readonly string $provider,
		public readonly int $displayOrder,
		public readonly bool $enabled,
		public readonly array $applicableTypes,
		public readonly string $buttonLabel,
		public readonly string $brandColor,
		public readonly string $buttonTextColor,
		public readonly bool $autoRefresh = false,
		public readonly string $refreshFrequency = 'weekly',
		public readonly int $imagePriority = 999,
		public readonly string $eligibleProvider = ''
	) {
		if ( '' === $this->code ) {
			throw new InvalidArgumentException( 'PlatformDefinition: code must not be empty.' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'code'             => $this->code,
			'name'             => $this->name,
			'provider'         => $this->provider,
			'displayOrder'     => $this->displayOrder,
			'enabled'          => $this->enabled,
			'applicableTypes'  => $this->applicableTypes,
			'buttonLabel'      => $this->buttonLabel,
			'brandColor'       => $this->brandColor,
			'buttonTextColor'  => $this->buttonTextColor,
			'autoRefresh'      => $this->autoRefresh,
			'refreshFrequency' => $this->refreshFrequency,
			'imagePriority'    => $this->imagePriority,
			'eligibleProvider' => $this->eligibleProvider,
		);
	}

	/**
	 * 配列から PlatformDefinition を生成する。
	 *
	 * 欠損キーは妥当なデフォルトで補完する。空 code は例外。
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$code = isset( $data['code'] ) ? (string) $data['code'] : '';
		if ( '' === $code ) {
			throw new InvalidArgumentException( 'PlatformDefinition::fromArray: code is required.' );
		}

		$applicable_types = array();
		if ( isset( $data['applicableTypes'] ) && is_array( $data['applicableTypes'] ) ) {
			foreach ( $data['applicableTypes'] as $type ) {
				$applicable_types[] = (string) $type;
			}
		}
		if ( array() === $applicable_types ) {
			$applicable_types = array( 'generic' );
		}

		$frequency = isset( $data['refreshFrequency'] ) ? (string) $data['refreshFrequency'] : 'weekly';
		if ( ! in_array( $frequency, self::ALLOWED_FREQUENCIES, true ) ) {
			$frequency = 'weekly';
		}

		$image_priority = isset( $data['imagePriority'] ) ? (int) $data['imagePriority'] : 999;

		return new self(
			$code,
			isset( $data['name'] ) ? (string) $data['name'] : $code,
			isset( $data['provider'] ) ? (string) $data['provider'] : 'manual',
			isset( $data['displayOrder'] ) ? (int) $data['displayOrder'] : 999,
			isset( $data['enabled'] ) ? (bool) $data['enabled'] : true,
			$applicable_types,
			isset( $data['buttonLabel'] ) && '' !== (string) $data['buttonLabel'] ? (string) $data['buttonLabel'] : '購入する',
			isset( $data['brandColor'] ) && '' !== (string) $data['brandColor'] ? (string) $data['brandColor'] : '#444444',
			isset( $data['buttonTextColor'] ) && '' !== (string) $data['buttonTextColor'] ? (string) $data['buttonTextColor'] : '#ffffff',
			isset( $data['autoRefresh'] ) ? (bool) $data['autoRefresh'] : false,
			$frequency,
			$image_priority,
			isset( $data['eligibleProvider'] ) ? (string) $data['eligibleProvider'] : ''
		);
	}
}
