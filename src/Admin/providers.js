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
// 自動 provider は「その platform が現在選択中のもの」だけを残す。これで platform に
// 無関係な自動 provider（例: Amazon Kindle に DMM）を誤って割り当てられなくなる。
export const providerOptionsFor = (currentProvider) =>
	injected
		.filter((p) => !p.isAutomatic || p.code === currentProvider)
		.map((p) => ({ label: p.label, value: p.code }));

export const providerAccount = (code) =>
	injected.find((p) => p.code === code)?.accountCode ?? null;
