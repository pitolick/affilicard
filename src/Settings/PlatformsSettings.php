<?php
declare(strict_types=1);

namespace Affilicard\Settings;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Platform\PlatformDefinition;

/**
 * `affilicard_platforms` オプションを REST 用に薄くラップする。
 *
 * 永続化は PlatformConfig に委譲する。
 */
final class PlatformsSettings {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		$result = array();
		foreach ( PlatformConfig::all() as $definition ) {
			$result[] = $definition->toArray();
		}
		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $platforms
	 * @return list<array<string, mixed>>
	 */
	public static function update( array $platforms ): array {
		$definitions = array();
		foreach ( $platforms as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			try {
				$definitions[] = PlatformDefinition::fromArray( $entry );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		PlatformConfig::save( $definitions );
		return self::all();
	}
}
