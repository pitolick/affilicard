/**
 * 親リポジトリ (e-comi) の .prettierrc を継承させず、
 * @wordpress/scripts と整合する WordPress 公式 prettier 設定を明示的に使う。
 * これがないと、サブモジュールとして clone した場合と単独 clone した場合で
 * 整形ルールが食い違い、CI と local の lint 結果がズレる。
 */
module.exports = require( '@wordpress/prettier-config' );
