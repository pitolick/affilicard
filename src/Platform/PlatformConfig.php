<?php
declare(strict_types=1);

namespace Affilicard\Platform;

/**
 * `affilicard_platforms` オプションを読み書きする。
 *
 * オプションは PlatformDefinition::toArray() の配列を直接 PHP serialize で保存する
 * （WordPress option API がシリアライズを担当）。
 */
final class PlatformConfig {

	public const OPTION_KEY = 'affilicard_platforms';

	/**
	 * 全プラットフォームを displayOrder 昇順で返す。
	 *
	 * @return list<PlatformDefinition>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$definitions = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			try {
				$definitions[] = PlatformDefinition::fromArray( $entry );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}

		self::sortByDisplayOrder( $definitions );
		return $definitions;
	}

	public static function find( string $code ): ?PlatformDefinition {
		foreach ( self::all() as $definition ) {
			if ( $definition->code === $code ) {
				return $definition;
			}
		}
		return null;
	}

	/**
	 * PlatformDefinition もしくは配列の配列を受け取り、保存する。
	 *
	 * 同じ code は最後に書かれたものが勝つ（dedupe）。displayOrder 昇順で並べてから保存する。
	 *
	 * @param array<int, PlatformDefinition|array<string, mixed>> $platforms
	 */
	public static function save( array $platforms ): void {
		/** @var array<string, PlatformDefinition> $by_code */
		$by_code = array();
		foreach ( $platforms as $entry ) {
			if ( $entry instanceof PlatformDefinition ) {
				$definition = $entry;
			} elseif ( is_array( $entry ) ) {
				try {
					$definition = PlatformDefinition::fromArray( $entry );
				} catch ( \InvalidArgumentException $e ) {
					continue;
				}
			} else {
				continue;
			}
			$by_code[ $definition->code ] = $definition;
		}

		$definitions = array_values( $by_code );
		self::sortByDisplayOrder( $definitions );

		$serialized = array();
		foreach ( $definitions as $definition ) {
			$serialized[] = $definition->toArray();
		}

		update_option( self::OPTION_KEY, $serialized, false );
	}

	/**
	 * デフォルトのプラットフォーム定義（8 件: ebook 3 + vod 5）。Plugin::onActivate で seed する想定。
	 *
	 * @return list<PlatformDefinition>
	 */
	public static function defaults(): array {
		return array(
			new PlatformDefinition(
				'dmm-books',
				__( 'DMMブックス', 'affilicard' ),
				'dmm-ebook',
				1,
				true,
				array( 'ebook' ),
				__( 'DMMブックスで読む', 'affilicard' ),
				'#d72d65',
				'#ffffff',
				true,
				'weekly',
				imagePriority: 10
			),
			new PlatformDefinition(
				'amazon-kindle',
				__( 'Amazon Kindle', 'affilicard' ),
				'manual',
				2,
				true,
				array( 'ebook' ),
				__( 'Kindleで読む', 'affilicard' ),
				'#ff9900',
				'#000000',
				imagePriority: 20
			),
			new PlatformDefinition(
				'rakuten-kobo',
				__( '楽天Kobo', 'affilicard' ),
				'manual',
				3,
				true,
				array( 'ebook' ),
				__( '楽天Koboで読む', 'affilicard' ),
				'#bf0000',
				'#ffffff',
				imagePriority: 30
			),
			new PlatformDefinition(
				'u-next',
				__( 'U-NEXT', 'affilicard' ),
				'manual',
				5,
				true,
				array( 'vod' ),
				__( 'U-NEXTで見る', 'affilicard' ),
				'#000000',
				'#ffffff'
			),
			new PlatformDefinition(
				'netflix',
				__( 'Netflix', 'affilicard' ),
				'manual',
				6,
				true,
				array( 'vod' ),
				__( 'Netflixで見る', 'affilicard' ),
				'#e50914',
				'#ffffff'
			),
			new PlatformDefinition(
				'hulu',
				__( 'Hulu', 'affilicard' ),
				'manual',
				7,
				true,
				array( 'vod' ),
				__( 'Huluで見る', 'affilicard' ),
				'#1ce783',
				'#000000'
			),
			new PlatformDefinition(
				'prime-video',
				__( 'Prime Video', 'affilicard' ),
				'manual',
				8,
				true,
				array( 'vod' ),
				__( 'Prime Videoで見る', 'affilicard' ),
				'#00a8e1',
				'#ffffff'
			),
			new PlatformDefinition(
				'danime',
				__( 'dアニメストア', 'affilicard' ),
				'manual',
				9,
				true,
				array( 'vod' ),
				__( 'dアニメストアで見る', 'affilicard' ),
				'#ff6600',
				'#ffffff'
			),
		);
	}

	/**
	 * @param list<PlatformDefinition> $definitions
	 */
	private static function sortByDisplayOrder( array &$definitions ): void {
		usort(
			$definitions,
			static function ( PlatformDefinition $a, PlatformDefinition $b ): int {
				return $a->displayOrder <=> $b->displayOrder;
			}
		);
	}
}
