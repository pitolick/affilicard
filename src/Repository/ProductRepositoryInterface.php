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
}
