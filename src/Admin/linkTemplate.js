/**
 * プラットフォームのリンクテンプレート（PHP の PlatformDefinition::applyLinkTemplate() と
 * Pricing\OfferUrl と同じ規則）を管理画面で判定するためのヘルパー。
 *
 * 管理画面の「フォールバック中」の警告を、カードの実物（PHP 側の判定）と食い違わせない
 * ために使う。テンプレートの正規化（小文字化など）はサーバ側が保存時に済ませている前提。
 */

const URL_PLACEHOLDERS = ['{url}', '{url_enc}'];

/**
 * テンプレートとして使える形か（https:// か {url} で始まり、作品 URL の差し込み口を持つ）。
 *
 * @param {string} template テンプレート
 * @return {boolean} 使えるなら true
 */
export function isUsableLinkTemplate(template) {
	if (typeof template !== 'string' || template === '') {
		return false;
	}
	const startsOk =
		template.startsWith('https://') || template.startsWith('{url}');
	return startsOk && URL_PLACEHOLDERS.some((p) => template.includes(p));
}

/**
 * このプラットフォームのテンプレートで、通常 URL からアフィリエイトリンクを組み立てられるか。
 *
 * @param {Object|null|undefined} platform プラットフォーム定義（linkTemplate / linkHosts）
 * @param {string}                url      通常 URL（regular_url）
 * @return {boolean} 組み立てられるなら true
 */
export function canApplyLinkTemplate(platform, url) {
	if (!platform || !isUsableLinkTemplate(platform.linkTemplate)) {
		return false;
	}
	const hosts = Array.isArray(platform.linkHosts) ? platform.linkHosts : [];
	if (hosts.length === 0 || typeof url !== 'string' || url.trim() === '') {
		return false;
	}
	let host;
	try {
		host = new URL(url.trim()).hostname.toLowerCase();
	} catch {
		return false;
	}
	return hosts.some(
		(candidate) => host === candidate || host.endsWith(`.${candidate}`)
	);
}
