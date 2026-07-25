<?php
declare(strict_types=1);

namespace Affilicard\AutoCreate;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Provider\ProviderRegistry;
use Affilicard\Queue\WorkOutcome;
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

	/**
	 * 商品を1件 auto-create し、結果を WorkOutcome で返す。
	 *
	 * give-up 機構（AutoCreateHandler）が terminal/transient を区別できるよう、単なる成否では
	 * なく3値で返す:
	 * - SUCCESS           = fetch hit → 商品作成成功
	 * - TERMINAL_FAILURE  = データ不備（空 ID）・未知 platform・非自動 Provider・fetch miss
	 *                       （該当なし・無効 ID）。リトライしても成功しないため give-up してよい。
	 * - TRANSIENT_FAILURE = fetch error（API 到達不可・エラー・認証未設定）・save 失敗。
	 *                       後で成功し得るため give-up しない。
	 */
	public function create( string $platformCode, string $externalId ): WorkOutcome {
		if ( '' === $platformCode || '' === $externalId ) {
			return WorkOutcome::TERMINAL_FAILURE;
		}
		$definition = PlatformConfig::find( $platformCode );
		if ( null === $definition ) {
			return WorkOutcome::TERMINAL_FAILURE;
		}
		$provider = $this->registry->get( $definition->provider );
		if ( null === $provider || ! $provider->isAutomatic() ) {
			return WorkOutcome::TERMINAL_FAILURE;
		}
		$result = $provider->fetch( $externalId, array() );
		if ( $result->isTerminalMiss() ) {
			return WorkOutcome::TERMINAL_FAILURE;
		}
		if ( ! $result->isHit() ) {
			return WorkOutcome::TRANSIENT_FAILURE;
		}
		$post_id = $this->repository->save(
			$this->buildProductData( $definition->code, $definition->name, $externalId, $result->data )
		);
		// save 失敗（0）はリトライで解決し得るため一時失敗。
		return $post_id > 0 ? WorkOutcome::SUCCESS : WorkOutcome::TRANSIENT_FAILURE;
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
					'platform'         => $platformCode,
					'enabled'          => true,
					'update_mode'      => 'auto',
					'auto_update'      => true,
					'external_id'      => $externalId,
					'regular_url'      => isset( $fetched['regular_url'] ) ? (string) $fetched['regular_url'] : '',
					'affiliate_url'    => isset( $fetched['affiliate_url'] ) ? (string) $fetched['affiliate_url'] : '',
					'price'            => isset( $fetched['price'] ) ? (string) $fetched['price'] : '',
					'list_price'       => isset( $fetched['list_price'] ) ? (string) $fetched['list_price'] : '',
					'badge'            => isset( $fetched['badge'] ) ? (string) $fetched['badge'] : '',
					'image_url'        => isset( $fetched['image_url'] ) ? (string) $fetched['image_url'] : '',
					'platform_extras'  => isset( $fetched['platform_extras'] ) && is_array( $fetched['platform_extras'] ) ? $fetched['platform_extras'] : array(),
					'last_verified_at' => gmdate( 'c' ),
				),
			),
		);
	}
}
