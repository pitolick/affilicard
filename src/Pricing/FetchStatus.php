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
		// **翻訳済みの値も拾う。** v3 の ListingRefresher は __() の戻り値を
		// fetch_error に保存していたため、affilicard の翻訳を入れているサイトでは
		// 日本語リテラルと一致しない。リテラルだけを見ると恒久失敗が TRANSIENT に
		// 化け、fallback_on_terminal が ON のとき消滅した購入リンクを選び続ける。
		// 保存時と移行時でサイトのロケールは通常同じなので、現在の __() 出力と
		// 突き合わせれば拾える。
		if ( self::matchesLegacy( $message, '対応する自動 Provider がありません' ) ) {
			return self::UNSUPPORTED;
		}
		if ( self::matchesLegacy( $message, '該当する商品が見つかりませんでした' ) ) {
			return self::TERMINAL;
		}
		return self::TRANSIENT;
	}

	/**
	 * 旧 fetch_error の文言が、指定した原文（またはその現在ロケールでの翻訳）かどうか。
	 *
	 * ロケールが保存時から変わっている場合までは拾えないが、その取りこぼしは
	 * 次の再取得で解消する——Provider が同じ恒久失敗を返せば fetch_status が
	 * TERMINAL に確定するためである。
	 */
	private static function matchesLegacy( string $message, string $source ): bool {
		if ( $source === $message ) {
			return true;
		}
		return function_exists( '__' ) && (string) __( $source, 'affilicard' ) === $message; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- $source は本メソッドの呼び出し元が渡すリテラルのみ。
	}

	/**
	 * 保存してよい値へ揃える。
	 *
	 * sanitize_key() は任意の文字列を通すため、打ち間違いや外部ツールの誤りが
	 * そのまま格納される。未知の status は管理画面で文言が出ず、isTerminal() も
	 * 偽になるので「壊れているのに正常に見える」。
	 *
	 * 空は NONE（成功）。非空の未知値は **TRANSIENT** へ倒す——terminal と誤認して
	 * 購入リンクを飛ばすより、一時失敗として扱って次の取得に任せる方が安全である
	 * （{@see self::fromLegacyMessage()} が未知の文言を TRANSIENT にするのと同じ判断）。
	 */
	public static function normalise( string $status ): string {
		$status = trim( $status );
		if ( '' === $status ) {
			return self::NONE;
		}
		$known = array( self::UNSUPPORTED, self::TRANSIENT, self::TERMINAL );
		return in_array( $status, $known, true ) ? $status : self::TRANSIENT;
	}
}
