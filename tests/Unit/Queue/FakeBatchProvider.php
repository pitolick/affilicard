<?php
declare(strict_types=1);

namespace Affilicard\Tests\Unit\Queue;

use Affilicard\Provider\BatchFetchingProvider;
use Affilicard\Provider\FetchResult;
use Affilicard\Provider\ProviderInterface;

/**
 * テスト用のまとめ取得 Provider。prefetch() の呼び出しを記録し、すべて hit として覚える。
 */
final class FakeBatchProvider implements ProviderInterface, BatchFetchingProvider {

	/** @var list<list<string>> */
	public array $prefetchCalls = array();
	public int $singleFetches   = 0;
	public int $clearCalls      = 0;

	/** @var array<string, FetchResult> */
	private array $memo = array();

	public function code(): string {
		return 'fake-batch';
	}

	public function label(): string {
		return 'fake';
	}

	public function isAutomatic(): bool {
		return true;
	}

	public function accountCode(): ?string {
		return 'amazon';
	}

	public function fetch( string $externalId, array $platformConfig ): FetchResult {
		if ( isset( $this->memo[ $externalId ] ) ) {
			return $this->memo[ $externalId ];
		}
		++$this->singleFetches;
		return FetchResult::hit( array() );
	}

	public function testConnection( array $credentials ): array {
		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	public function minRequestIntervalMs(): int {
		return 0;
	}

	public function maxBatchSize(): int {
		return 10;
	}

	public function prefetch( array $externalIds ): void {
		$this->prefetchCalls[] = $externalIds;
		foreach ( $externalIds as $id ) {
			$this->memo[ $id ] = FetchResult::hit( array( 'price' => '1' ) );
		}
	}

	public function hasPrefetched( string $externalId ): bool {
		return isset( $this->memo[ $externalId ] );
	}

	public function clearPrefetched(): void {
		++$this->clearCalls;
		$this->memo = array();
	}
}
