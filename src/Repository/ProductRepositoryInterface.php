<?php
declare(strict_types=1);

namespace Affilicard\Repository;

/**
 * 商品リポジトリの抽象インターフェース。
 *
 * ProductAutoCreator 等からリポジトリをモック可能にするために導入。
 */
interface ProductRepositoryInterface {

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( array $data ): int;

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $postId ): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByExternalId( string $platformCode, string $externalId ): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findBySlug( string $slug ): ?array;
}
