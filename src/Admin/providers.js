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

export const providerAccount = (code) =>
	injected.find((p) => p.code === code)?.accountCode ?? null;
