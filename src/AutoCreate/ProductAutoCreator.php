<?php
declare(strict_types=1);

namespace Affilicard\AutoCreate;

use Affilicard\Platform\PlatformConfig;
use Affilicard\Pricing\FetchStatus;
use Affilicard\Pricing\OfferSelector;
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

	/** GET_LOCK の待ち時間（秒）。ProductRepository の listing ロックと揃える。 */
	private const LOCK_TIMEOUT = 10;

	public function __construct(
		private ProviderRegistry $registry,
		private ProductRepositoryInterface $repository
	) {}

	/**
	 * 商品を1件 auto-create し、結果を WorkOutcome で返す。
	 *
	 * give-up 機構（AutoCreateHandler）が terminal/transient を区別できるよう、単なる成否では
	 * なく3値で返す:
	 * - SUCCESS           = fetch hit → 商品作成成功、または既存商品が見つかり生成不要（no-op）
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
		// enqueue から実行までの間に別経路で既に作成済みになっている場合がある
		// （Block::autoCreate の 5 分ロックが切れた後の再 enqueue 等）。external_id
		// ミラー（offers[] 由来）で既存商品を引ければ、ここで作らず no-op で終える。
		if ( null !== $this->repository->findByExternalId( $definition->code, $externalId ) ) {
			return WorkOutcome::SUCCESS;
		}
		$result = $provider->fetch( $externalId, array() );
		if ( $result->isTerminalMiss() ) {
			return WorkOutcome::TERMINAL_FAILURE;
		}
		if ( ! $result->isHit() ) {
			return WorkOutcome::TRANSIENT_FAILURE;
		}

		return $this->createLocked( $definition->code, $definition->name, $externalId, $result->data );
	}

	/**
	 * 「まだ無ければ作る」を名前付きロックの中で行う。
	 *
	 * **上の事前チェックだけでは重複商品を防げない。** Action Scheduler の
	 * `unique=true` は原子的ではなく、同じ platform + external ID の
	 * `affilicard_autocreate` が 2 つ同時に走り得る。両方が事前チェックで null を
	 * 見て、両方が wp_insert_post() する窓が fetch（数百 ms〜数秒）のぶんだけ開く。
	 * external_id の一意性を担保しているのは post meta のミラーだけで、DB 制約は
	 * 無いため、入ってしまった重複は自動では解消しない。
	 *
	 * そこで fetch の**後**にロックを取り、その中で findByExternalId() をやり直し、
	 * 依然として不在のときだけ保存する。ロックを fetch の前に取らないのは、
	 * 外部 API の待ち時間ぶんロックを握り続けないため。
	 *
	 * **ロックを取れなければ保存しない（TRANSIENT_FAILURE）。** 取れない＝別の worker が
	 * 同じキーで作成中なので、ここで押し通すと重複商品ができる——ロックを置く意味が
	 * 無くなる。一時失敗として返せば AutoCreateHandler が backoff して再投入し、
	 * 次の試行では先着が作った商品を事前チェックが引いて no-op（SUCCESS）で終わる。
	 * ProductRepository::updateListing() のロックが best-effort で続行するのとは
	 * 逆の判断だが、あちらは「取得済みの値を捨てない」ためで、こちらは
	 * 「取り消せない重複を作らない」ためである。
	 *
	 * @param array<string, mixed> $fetched
	 */
	private function createLocked( string $platformCode, string $platformName, string $externalId, array $fetched ): WorkOutcome {
		global $wpdb;

		// GET_LOCK の名前は 64 バイト以内。external ID は長さも文字種も外部由来なので
		// ハッシュへ畳む（prefix 22 + md5 32 = 54 バイト）。
		$lock = 'affilicard_autocreate_' . md5( $platformCode . '|' . $externalId );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) );
		if ( $got <= 0 ) {
			return WorkOutcome::TRANSIENT_FAILURE;
		}

		try {
			// ロックの中で引き直す。fetch のあいだに別経路が作り終えていれば no-op。
			if ( null !== $this->repository->findByExternalId( $platformCode, $externalId ) ) {
				return WorkOutcome::SUCCESS;
			}

			$post_id = $this->repository->save(
				$this->buildProductData( $platformCode, $platformName, $externalId, $fetched )
			);
			// save 失敗（0）はリトライで解決し得るため一時失敗。
			return $post_id > 0 ? WorkOutcome::SUCCESS : WorkOutcome::TRANSIENT_FAILURE;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * @param array<string, mixed> $fetched
	 * @return array<string, mixed>
	 */
	private function buildProductData( string $platformCode, string $platformName, string $externalId, array $fetched ): array {
		$title = isset( $fetched['title'] ) && '' !== (string) $fetched['title']
			? (string) $fetched['title']
			: trim( $platformName . ' ' . $externalId );

		$now = gmdate( 'c' );

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
					'platform_extras' => isset( $fetched['platform_extras'] ) && is_array( $fetched['platform_extras'] ) ? $fetched['platform_extras'] : array(),
					'offers'          => array(
						array(
							'display_order'    => OfferSelector::DEFAULT_ORDER,
							'external_id'      => $externalId,
							'regular_url'      => isset( $fetched['regular_url'] ) ? (string) $fetched['regular_url'] : '',
							'affiliate_url'    => isset( $fetched['affiliate_url'] ) ? (string) $fetched['affiliate_url'] : '',
							'price'            => isset( $fetched['price'] ) ? (string) $fetched['price'] : '',
							'list_price'       => isset( $fetched['list_price'] ) ? (string) $fetched['list_price'] : '',
							'badge'            => isset( $fetched['badge'] ) ? (string) $fetched['badge'] : '',
							'image_url'        => isset( $fetched['image_url'] ) ? (string) $fetched['image_url'] : '',
							'search_key'       => isset( $fetched['search_key'] ) ? (string) $fetched['search_key'] : '',
							'fetch_status'     => FetchStatus::NONE,
							'last_fetched_at'  => $now,
							'last_verified_at' => $now,
						),
					),
				),
			),
		);
	}
}
