/**
 * プラットフォームの表示順（displayOrder）を扱う純関数群。
 *
 * 並べ替えの意味論を DOM・React から切り離し、単体で検証できるようにする。
 * 表示順の SSOT はプラットフォーム設定であり、カードの CTA ボタンはこの順に並ぶ。
 */

/**
 * そのタイプタブに表示する platform を、配列順のまま抽出する。
 *
 * @param {Array<Object>} platforms 全 platform（displayOrder 昇順で保持している前提）
 * @param {string}        type      商品タイプコード（'ebook' 等）
 * @return {Array<Object>} 該当する platform
 */
export function platformsOfType(platforms, type) {
	return platforms.filter(
		(p) =>
			Array.isArray(p.applicableTypes) && p.applicableTypes.includes(type)
	);
}

/**
 * タイプタブ内の「有効な」platform に 1 始まりの順位を振る。
 *
 * 無効な platform はカードに描画されないため順位を持たない。バッジに出す値は
 * displayOrder そのものではなく「カード上で何番目に出るか」であるべきなので、
 * 無効な行と他タイプの行を飛ばして数える。
 *
 * @param {Array<Object>} platforms 全 platform
 * @param {string}        type      商品タイプコード
 * @return {Object<string, number>} code => 1 始まりの順位
 */
export function enabledRanks(platforms, type) {
	const ranks = {};
	let rank = 0;
	for (const platform of platformsOfType(platforms, type)) {
		if (platform.enabled) {
			rank += 1;
			ranks[platform.code] = rank;
		}
	}
	return ranks;
}

/**
 * 配列順どおりに displayOrder を 1..N の連番で振り直した新配列を返す。
 *
 * 既存データの重複値・欠番をここで正規化する。サーバ側は displayOrder 昇順で
 * 保存し直すため、連番にしておかないと UI 上の並びと保存結果がずれる。
 *
 * @param {Array<Object>} platforms 全 platform
 * @return {Array<Object>} displayOrder を振り直した新配列
 */
export function renumberDisplayOrder(platforms) {
	return platforms.map((platform, index) => ({
		...platform,
		displayOrder: index + 1,
	}));
}

/**
 * 同じタイプタブ内で、code の platform を 1 つ前／後の「有効な」platform と入れ替える。
 *
 * 無効な行・他タイプの行は読み飛ばす。入れ替えは配列位置の交換で行うため、
 * 2 つの間に挟まる行の位置は動かない。交換後に displayOrder を 1..N へ振り直す。
 *
 * 端にいて動かせない場合・未知の code の場合は、引数の配列をそのまま返す
 * （呼び出し側は参照の同一性で「動かなかった」を判定できる）。
 *
 * @param {Array<Object>} platforms 全 platform
 * @param {string}        type      商品タイプコード
 * @param {string}        code      動かす platform の code
 * @param {string}        direction 'up' | 'down'
 * @return {Array<Object>} 並べ替え後の新配列、または引数と同一の配列
 */
export function movePlatform(platforms, type, code, direction) {
	const enabledIndexes = [];
	platforms.forEach((platform, index) => {
		if (
			platform.enabled &&
			Array.isArray(platform.applicableTypes) &&
			platform.applicableTypes.includes(type)
		) {
			enabledIndexes.push(index);
		}
	});

	const at = enabledIndexes.findIndex(
		(index) => platforms[index].code === code
	);
	if (at === -1) {
		return platforms;
	}

	const targetAt = direction === 'up' ? at - 1 : at + 1;
	if (targetAt < 0 || targetAt >= enabledIndexes.length) {
		return platforms;
	}

	const from = enabledIndexes[at];
	const to = enabledIndexes[targetAt];
	const next = [...platforms];
	next[from] = platforms[to];
	next[to] = platforms[from];
	return renumberDisplayOrder(next);
}
