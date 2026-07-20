// Provider 定義は PHP を単一情報源とし、window.affilicardProviders へ注入される。
// schema は持たず accountCode を持つ（credentials スキーマは accounts.js の ACCOUNTS 側）。
const injected =
	typeof window !== 'undefined' && Array.isArray(window.affilicardProviders)
		? window.affilicardProviders
		: [];

export const PROVIDER_OPTIONS = injected.map((p) => ({
	label: p.label,
	value: p.code,
}));

// platform の Provider 選択肢を絞る。手動系（非自動）は常に候補に含めつつ、
// 自動 provider は「その platform が現在選択中のもの」に加え、
// 呼び出し側が渡す eligibleProvider（その platform に紐づく自動 provider）だけを残す。
// これで platform に無関係な自動 provider（例: Amazon Kindle に DMM）を誤って
// 割り当てられなくなる一方、provider='manual' のまま登録された platform でも
// 対応する自動 provider へ切り替えられる。
export const providerOptionsFor = (currentProvider, eligibleProvider = '') =>
	injected
		.filter(
			(p) =>
				!p.isAutomatic ||
				p.code === currentProvider ||
				(eligibleProvider && p.code === eligibleProvider)
		)
		.map((p) => ({ label: p.label, value: p.code }));

export const providerAccount = (code) =>
	injected.find((p) => p.code === code)?.accountCode ?? null;
