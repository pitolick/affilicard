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

	public function delete( int $postId ): bool;

	/**
	 * 商品を検索し、各 item に thumbnail/price/platform を付与して返す。
	 *
	 * - $term 空: modified 降順の最近商品（ページ処理あり）
	 * - $term 非空: title/content 全文検索 + external_id ミラー OR meta_query の和集合、一意化・modified 降順
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function search( string $term, int $perPage, int $page ): array;
}
