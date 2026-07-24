<?php
declare(strict_types=1);

namespace Affilicard\Provider;

/**
 * Provider::fetch() の取得結果を3値で表す不変値オブジェクト。
 *
 * 価格更新の give-up 機構が「恒久失敗（terminal）のみ give-up し、一時失敗（transient）は
 * リトライして give-up しない」を判定できるよう、単なる null（取得不可）ではなく
 * 「該当なし・無効 ID（恒久）」と「API 到達不可・エラー（一時）」を区別する。
 *
 * - hit(data): 成功。取得データを保持する。
 * - miss():    恒久失敗（terminal）。API へは到達したが該当商品が無い／無効 ID。
 *              リトライしても成功しないため give-up してよい。
 * - error():   一時失敗（transient）。API 到達不可・エラー・認証未設定等。後で成功し得る
 *              ため give-up せずリトライする。
 */
final class FetchResult {

	/**
	 * @param array<string, mixed>|null $data     成功時の取得データ。失敗時は null。
	 * @param bool                      $terminal 恒久失敗（miss）なら true。成功・一時失敗は false。
	 */
	private function __construct(
		public readonly ?array $data,
		public readonly bool $terminal
	) {}

	/**
	 * 成功。取得データを保持する。
	 *
	 * @param array<string, mixed> $data
	 */
	public static function hit( array $data ): self {
		return new self( $data, false );
	}

	/**
	 * 恒久失敗（terminal）。API 到達したが該当商品が無い／無効 ID。リトライしても成功しない。
	 */
	public static function miss(): self {
		return new self( null, true );
	}

	/**
	 * 一時失敗（transient）。API 到達不可・エラー・認証未設定等。後で成功し得る。
	 */
	public static function error(): self {
		return new self( null, false );
	}

	/**
	 * 成功（取得データを持つ）か。
	 */
	public function isHit(): bool {
		return null !== $this->data;
	}

	/**
	 * 恒久失敗（terminal miss）か。give-up 判定に使う。
	 */
	public function isTerminalMiss(): bool {
		return null === $this->data && $this->terminal;
	}
}
