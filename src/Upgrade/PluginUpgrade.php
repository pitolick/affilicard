<?php
declare(strict_types=1);

namespace Affilicard\Upgrade;

/**
 * プラグインのバージョン移行ルーチン。
 *
 * `register_activation_hook` は WordPress の自動更新・管理画面からの更新では
 * 実行されない。有効化したまま更新したサイトでも初期化処理が確実に走るよう、
 * `plugins_loaded` で保存済みバージョンと現在バージョンを比較して差分処理を行う。
 */
final class PluginUpgrade {

	public const OPTION_VERSION = 'affilicard_plugin_version';

	/** 棚卸し基準日。最終掲載日を持たない既存商品の判定基準になる（spec §5-3）。 */
	public const OPTION_STOCKTAKE_BASELINE = 'affilicard_stocktake_baseline';

	public static function maybeUpgrade( string $currentVersion ): void {
		$stored = (string) get_option( self::OPTION_VERSION, '' );
		if ( $stored === $currentVersion ) {
			return;
		}

		// 基準日を作れなかった（＝存在も確認できなかった）場合はバージョンを進めない。
		// ここでバージョンだけ進めると、次回以降このメソッドが冒頭の早期 return で
		// 素通りしてしまい、基準日が永久に作られない（棚卸しが永久に発動しない）。
		// バージョンを更新しなければ、次回の plugins_loaded で再試行される。
		if ( ! self::ensureStocktakeBaseline() ) {
			return;
		}

		update_option( self::OPTION_VERSION, $currentVersion, false );
	}

	/**
	 * 棚卸し基準日（{@see OPTION_STOCKTAKE_BASELINE}）の存在を保証する。
	 *
	 * 「無ければ作る。既にあるサイトでは絶対に書き換えない」を実現するため `add_option()`
	 * を使うが、`add_option()` は「既に値が存在する（＝正常）」場合と「保存に失敗した
	 * （＝異常）」場合のどちらでも false を返し、戻り値だけでは区別できない。
	 * false が返ったときは `get_option()` で実在を確認し、両者を切り分ける。
	 *
	 * @return bool 基準日が存在する（新規作成 or 既存）ことを確認できたら true。
	 *              保存に失敗し、かつ既存の値も確認できなければ false。
	 */
	private static function ensureStocktakeBaseline(): bool {
		if ( add_option( self::OPTION_STOCKTAKE_BASELINE, gmdate( 'c' ), '', false ) ) {
			return true;
		}

		// add_option が false を返した。「既に存在する」のか「保存に失敗した」のかを
		// get_option で確認する。空文字（未設定の既定値）以外が返れば実在すると判断する。
		return '' !== (string) get_option( self::OPTION_STOCKTAKE_BASELINE, '' );
	}
}
