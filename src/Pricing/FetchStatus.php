<?php
declare(strict_types=1);

namespace Affilicard\Pricing;

/**
 * 購入リンク（offer）の取得状態。
 *
 * 以前は日本語の文言を meta に保存していたが、(a) 翻訳結果が固定される
 * (b) 外部から文字列でしか判定できない (c)「自動取得の対象外」が一時失敗に
 * 潰れる、の 3 点があったためコードで持つ。文言は表示時に生成する。
 */
final class FetchStatus {

	/** 成功。 */
	public const NONE = '';

	/** 自動取得の対象外（Provider 未対応・external_id 空）。恒久だがリトライ分類は transient。 */
	public const UNSUPPORTED = 'unsupported';

	/** 一時失敗（API 到達不可・認証未設定・レート制限）。 */
	public const TRANSIENT = 'transient';

	/** 恒久失敗（該当なし・無効 ID）。選択係はこれだけを飛ばす。 */
	public const TERMINAL = 'terminal';

	/**
	 * 選択係が飛ばすべき状態か。
	 *
	 * **未知の値は false を返す。** 想定外の値で購入リンクを飛ばすと、生きている
	 * リンクを勝手に切り替えることになる。飛ばさない側へ倒すのが安全である。
	 */
	public static function isTerminal( string $status ): bool {
		return self::TERMINAL === $status;
	}

	/** 管理画面に出す文言。表示時に生成するため保存しない。 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case self::UNSUPPORTED:
				return (string) __( '自動取得の対象外です', 'affilicard' );
			case self::TRANSIENT:
				return (string) __( '一時的に取得できませんでした', 'affilicard' );
			case self::TERMINAL:
				return (string) __( '商品が見つかりません', 'affilicard' );
			default:
				return '';
		}
	}

	/**
	 * 移行用。v3 以前に保存された `fetch_error` の文言をコードへ写像する。
	 *
	 * **いずれにも一致しない値は TRANSIENT へ倒す。** 恒久と誤認して購入リンクを
	 * 飛ばすより安全である。
	 */
	public static function fromLegacyMessage( string $message ): string {
		$message = trim( $message );
		if ( '' === $message ) {
			return self::NONE;
		}
		if ( '対応する自動 Provider がありません' === $message ) {
			return self::UNSUPPORTED;
		}
		if ( '該当する商品が見つかりませんでした' === $message ) {
			return self::TERMINAL;
		}
		return self::TRANSIENT;
	}
}
