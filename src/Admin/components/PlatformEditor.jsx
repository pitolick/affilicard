import { useState } from '@wordpress/element';
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

export function PlatformEditor({ platform, onChange, initialOpen = false }) {
	const [refreshing, setRefreshing] = useState(false);
	const [notice, setNotice] = useState(null);
	const update = (patch) => onChange({ ...platform, ...patch });

	const onRefresh = async () => {
		setRefreshing(true);
		setNotice(null);
		try {
			await triggerRefresh(platform.code);
			setNotice({
				type: 'success',
				message: __('更新しました。', 'affilicard'),
			});
		} catch {
			setNotice({
				type: 'error',
				message: __('更新に失敗しました。', 'affilicard'),
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
					label={__('表示順', 'affilicard')}
					type="number"
					value={String(platform.displayOrder ?? 1)}
					onChange={(v) =>
						update({ displayOrder: parseInt(v, 10) || 1 })
					}
				/>
				<TextControl
					label={__('画像優先度（小さいほど優先）', 'affilicard')}
					type="number"
					value={String(platform.imagePriority ?? 999)}
					onChange={(v) => {
						const value = parseInt(v, 10);
						update({
							imagePriority: Number.isNaN(value) ? 999 : value,
						});
					}}
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

			<div className="affilicard-platform-editor__section affilicard-platform-editor__section--api">
				<h4 className="affilicard-platform-editor__subhead">
					{__('価格の取得方法', 'affilicard')}
				</h4>
				{platform.eligibleProvider ? (
					<ToggleControl
						label={sprintf(
							/* translators: %s: provider display name, e.g. "楽天Kobo API" */
							__('自動取得（%s）', 'affilicard'),
							providerLabel(platform.eligibleProvider)
						)}
						checked={(platform.provider ?? 'manual') !== 'manual'}
						onChange={(v) =>
							update({
								provider: v ? platform.eligibleProvider : 'manual',
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
