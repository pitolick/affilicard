<?php
declare(strict_types=1);

namespace Affilicard\AutoCreate;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Repository\ProductRepositoryInterface;

/**
 * Block / Cron / 手動から呼ばれる商品 auto-create。
 *
 * platform code + external ID を起点に Provider で商品情報を取得し商品 CPT を 1 件生成する。
 * 自動 Provider 以外・取得失敗時は生成しない。
 */
final class ProductAutoCreator {

	public function __construct(
		private ProviderRegistry $registry,
		private ProductRepositoryInterface $repository
	) {}

	public function create( string $platformCode, string $externalId ): ?int {
		if ( '' === $platformCode || '' === $externalId ) {
			return null;
		}
		$definition = PlatformConfig::find( $platformCode );
		if ( null === $definition ) {
			return null;
		}
		$provider = $this->registry->get( $definition->provider );
		if ( null === $provider || ! $provider->isAutomatic() ) {
			return null;
		}
		$fetched = $provider->fetch( $externalId, array() );
		if ( null === $fetched ) {
			return null;
		}
		$post_id = $this->repository->save(
			$this->buildProductData( $definition->code, $definition->name, $externalId, $fetched )
		);
		return $post_id > 0 ? $post_id : null;
	}

	/**
	 * @param array<string, mixed> $fetched
	 * @return array<string, mixed>
	 */
	private function buildProductData( string $platformCode, string $platformName, string $externalId, array $fetched ): array {
		$title = isset( $fetched['title'] ) && '' !== (string) $fetched['title']
			? (string) $fetched['title']
			: trim( $platformName . ' ' . $externalId );

		return array(
			'title'        => $title,
			'status'       => 'publish',
			'product_type' => 'generic',
			'listings'     => array(
				array(
					'platform'        => $platformCode,
					'enabled'         => true,
					'update_mode'     => 'auto',
					'auto_update'     => true,
					'external_id'     => $externalId,
					'regular_url'     => isset( $fetched['regular_url'] ) ? (string) $fetched['regular_url'] : '',
					'affiliate_url'   => isset( $fetched['affiliate_url'] ) ? (string) $fetched['affiliate_url'] : '',
					'price'           => isset( $fetched['price'] ) ? (string) $fetched['price'] : '',
					'list_price'      => isset( $fetched['list_price'] ) ? (string) $fetched['list_price'] : '',
					'badge'           => isset( $fetched['badge'] ) ? (string) $fetched['badge'] : '',
					'image_url'       => isset( $fetched['image_url'] ) ? (string) $fetched['image_url'] : '',
					'platform_extras' => isset( $fetched['platform_extras'] ) && is_array( $fetched['platform_extras'] ) ? $fetched['platform_extras'] : array(),
				),
			),
		);
	}
}
