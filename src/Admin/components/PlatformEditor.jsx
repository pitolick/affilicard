import {
	TextControl,
	ToggleControl,
	SelectControl,
	Button,
	PanelBody,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { triggerRefresh } from '../api/refresh';
import { PROVIDER_OPTIONS } from '../providers';

export function PlatformEditor({ platform, onChange, initialOpen = false }) {
	const update = (patch) => onChange({ ...platform, ...patch });

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
					onChange={(v) =>
						update({ imagePriority: parseInt(v, 10) || 999 })
					}
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
					{__('API 連携（自動取得）', 'affilicard')}
				</h4>
				<SelectControl
					label={__('Provider', 'affilicard')}
					value={platform.provider ?? 'manual'}
					options={PROVIDER_OPTIONS}
					onChange={(v) => update({ provider: v })}
				/>
				<ToggleControl
					label={__('API自動更新', 'affilicard')}
					checked={Boolean(platform.autoRefresh)}
					onChange={(v) => update({ autoRefresh: v })}
				/>
				{platform.autoRefresh && (
					<SelectControl
						label={__('更新頻度', 'affilicard')}
						value={platform.refreshFrequency ?? 'weekly'}
						options={[
							{ label: __('毎日', 'affilicard'), value: 'daily' },
							{
								label: __('毎週', 'affilicard'),
								value: 'weekly',
							},
						]}
						onChange={(v) => update({ refreshFrequency: v })}
					/>
				)}
				<Button
					variant="secondary"
					onClick={() => triggerRefresh(platform.code)}
				>
					{__('今すぐこのプラットフォームを更新', 'affilicard')}
				</Button>
			</div>
		</PanelBody>
	);
}
