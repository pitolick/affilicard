import { useState, useEffect } from '@wordpress/element';
import {
	TextControl,
	ToggleControl,
	Button,
	PanelBody,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { triggerRefresh } from '../api/refresh';
import { providerLabel } from '../providers';

/**
 * 対象ドメインの入力（カンマ区切り）を配列にする。空要素は除く。
 * 正規化（小文字化・パス除去など）はサーバ側（PlatformDefinition::fromArray）が行う。
 *
 * @param {string} value カンマ区切りの入力値
 * @return {string[]} ドメインの配列
 */
export function parseLinkHosts(value) {
	return String(value ?? '')
		.split(',')
		.map((host) => host.trim())
		.filter((host) => host !== '');
}

export function PlatformEditor({ platform, onChange, initialOpen = false }) {
	const [refreshing, setRefreshing] = useState(false);
	const [notice, setNotice] = useState(null);
	// 入力中の文字列はそのまま持つ（配列から毎回組み立て直すと、末尾のカンマが消えて
	// 2 つ目のドメインを打てなくなる）。保存用の配列は入力のたびに親へ渡す。
	const [hostsDraft, setHostsDraft] = useState(() =>
		(Array.isArray(platform.linkHosts) ? platform.linkHosts : []).join(', ')
	);
	// 外から値が変わったとき（保存後にサーバが正規化した値が返る等）だけ入力欄へ反映する。
	// 入力中は「入力欄を配列にしたもの」と親の値が一致しているので上書きしない。
	const hostsKey = (
		Array.isArray(platform.linkHosts) ? platform.linkHosts : []
	).join(', ');
	useEffect(() => {
		setHostsDraft((draft) =>
			parseLinkHosts(draft).join(', ') === hostsKey ? draft : hostsKey
		);
	}, [hostsKey]);
	const update = (patch) => onChange({ ...platform, ...patch });

	const onRefresh = async () => {
		setRefreshing(true);
		setNotice(null);
		try {
			await triggerRefresh(platform.code);
			setNotice({
				type: 'success',
				message: __(
					'価格更新を実行しました。反映結果はカードの価格・「最終同期」でご確認ください。',
					'affilicard'
				),
			});
		} catch {
			setNotice({
				type: 'error',
				message: __('価格更新の実行に失敗しました。', 'affilicard'),
			});
		} finally {
			setRefreshing(false);
		}
	};

	const base = sprintf(
		/* translators: 1: platform display name, 2: platform code */
		'%1$s (%2$s)',
		platform.name ?? '',
		platform.code ?? ''
	);
	const title = platform.enabled
		? base
		: sprintf(
				/* translators: %s: "name (code)" */
				__('%s — 無効', 'affilicard'),
				base
			);

	return (
		<PanelBody
			className="affilicard-platform-editor"
			title={title}
			initialOpen={initialOpen}
		>
			<div className="affilicard-platform-editor__section">
				<ToggleControl
					label={__('有効', 'affilicard')}
					checked={Boolean(platform.enabled)}
					onChange={(v) => update({ enabled: v })}
				/>
				<TextControl
					label={__('表示名', 'affilicard')}
					value={platform.name ?? ''}
					onChange={(v) => update({ name: v })}
				/>
				<TextControl
					label={__('ボタンラベル', 'affilicard')}
					value={platform.buttonLabel ?? ''}
					onChange={(v) => update({ buttonLabel: v })}
				/>
				<TextControl
					label={__('ブランド色', 'affilicard')}
					value={platform.brandColor ?? '#444444'}
					onChange={(v) => update({ brandColor: v })}
				/>
				<TextControl
					label={__('ボタン文字色', 'affilicard')}
					value={platform.buttonTextColor ?? '#ffffff'}
					onChange={(v) => update({ buttonTextColor: v })}
				/>
			</div>

			<div className="affilicard-platform-editor__section affilicard-platform-editor__section--link">
				<h4 className="affilicard-platform-editor__subhead">
					{__('アフィリエイトリンクの組み立て', 'affilicard')}
				</h4>
				<TextControl
					label={__('リンクテンプレート', 'affilicard')}
					value={platform.linkTemplate ?? ''}
					onChange={(v) => update({ linkTemplate: v })}
					help={__(
						'購入リンクにアフィリエイト URL が無いとき、通常 URL（作品・商品ページ）からアフィリエイトリンクを組み立てます。{url}＝通常 URL、{url_enc}＝通常 URL を URL エンコードしたもの、{sep}＝通常 URL にクエリがあれば「&」無ければ「?」。例: https://example.com/link?id=XXXX&url={url_enc} ／ {url}{sep}tag=XXXX。https:// か {url} で始まり、{url} か {url_enc} を含む形だけが有効です。',
						'affilicard'
					)}
				/>
				<TextControl
					label={__('対象ドメイン', 'affilicard')}
					value={hostsDraft}
					onChange={(v) => {
						setHostsDraft(v);
						update({ linkHosts: parseLinkHosts(v) });
					}}
					help={__(
						'テンプレートを当てる通常 URL のドメイン（カンマ区切り）。サブドメインも対象になります（例: hulu.jp は www.hulu.jp にも当たる）。一致しない URL や、ここが空のときはテンプレートを使いません。',
						'affilicard'
					)}
				/>
			</div>

			<div className="affilicard-platform-editor__section affilicard-platform-editor__section--api">
				<h4 className="affilicard-platform-editor__subhead">
					{__('価格の取得方法', 'affilicard')}
				</h4>
				{platform.eligibleProvider ? (
					<>
						<ToggleControl
							label={sprintf(
								/* translators: %s: provider display name, e.g. "楽天Kobo API" */
								__('自動取得（%s）', 'affilicard'),
								providerLabel(platform.eligibleProvider)
							)}
							checked={
								(platform.provider ?? 'manual') !== 'manual'
							}
							onChange={(v) =>
								update({
									provider: v
										? platform.eligibleProvider
										: 'manual',
								})
							}
							help={sprintf(
								/* translators: %s: provider display name, e.g. "楽天Kobo API" */
								__(
									'ON＝%s から価格・URLを自動取得し、全体設定の「更新間隔」で定期更新します。OFF＝手動入力（価格・URLを手で入力）。ストアの表示ON/OFFは上の「有効」、自動更新の稼働・間隔は全体設定で制御します。',
									'affilicard'
								),
								providerLabel(platform.eligibleProvider)
							)}
						/>
						{(platform.provider ?? 'manual') === 'manual' && (
							<Notice status="warning" isDismissible={false}>
								{sprintf(
									/* translators: %s: provider display name, e.g. "楽天Kobo API" */
									__(
										'このプラットフォームは自動取得（%s）に対応していますが、現在は『手動入力』です。価格を自動更新するには上の『価格の取得方法』を自動取得に切り替えてください（別途、API 認証情報の登録が必要です）。',
										'affilicard'
									),
									providerLabel(platform.eligibleProvider)
								)}
							</Notice>
						)}
					</>
				) : (
					<p className="affilicard-platform-editor__note">
						{__(
							'このプラットフォームは手動入力です（対応APIがありません）。',
							'affilicard'
						)}
					</p>
				)}
				<Button
					variant="secondary"
					disabled={refreshing}
					onClick={onRefresh}
				>
					{refreshing
						? __('更新中…', 'affilicard')
						: __('今すぐこのプラットフォームを更新', 'affilicard')}
				</Button>
				{notice && (
					<Notice
						status={notice.type}
						onRemove={() => setNotice(null)}
					>
						{notice.message}
					</Notice>
				)}
			</div>
		</PanelBody>
	);
}
