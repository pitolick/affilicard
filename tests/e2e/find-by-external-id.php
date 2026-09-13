<?php
/**
 * E2E 補助スクリプト（Task 16）。`wp eval-file` でコンテナ内実行する。
 *
 * `ProductRepository::findByExternalId()` は自動作成（ProductAutoCreator）専用の
 * 内部経路で REST に露出していないため、複数値 meta（affilicard_extid_<platform>）に
 * 対する meta_query の `=` 一致を実 WordPress/MySQL 上で直接確認するにはこれしかない。
 * **このファイルには `declare(strict_types=1);` を置けない**（seed.php と同じ理由。
 * `wp eval-file` は内容を `eval()` するため strict_types 宣言が
 * 「スクリプトの最初の文」になり得ない）。
 *
 * 引数: <platform> <externalId>
 * 出力: 1 行 `RESULT_JSON:{"id":123}`（見つからなければ `{"id":null}`）
 */

$platform    = (string) ( $args[0] ?? '' );
$external_id = (string) ( $args[1] ?? '' );

$product = ( new \Affilicard\Repository\ProductRepository() )->findByExternalId( $platform, $external_id );

echo 'RESULT_JSON:' . wp_json_encode( array( 'id' => $product['id'] ?? null ) ) . "\n";
