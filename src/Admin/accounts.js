// Account 定義（credentials スキーマ）は PHP を単一情報源とし、設定ページ enqueue 時に
// window.affilicardAccounts へ注入される（wp_add_inline_script）。
const injected =
	typeof window !== 'undefined' && Array.isArray(window.affilicardAccounts)
		? window.affilicardAccounts
		: [];

export const ACCOUNTS = injected; // [{code,label,credentialsSchema}]
