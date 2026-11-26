import { TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { extrasSchemaFor } from '../productTypes';

export function ExtrasEditor({ productType, extras, onChange }) {
	// 推奨項目は商品タイプの定義（PHP の extrasSchema）から作る。
	const schema = extrasSchemaFor(productType);
	const schemaKeys = new Set(schema.map((s) => s.key));
	const rows = Array.isArray(extras) ? extras : [];

	// 今のタイプの推奨項目に無い行は、key があってもカスタム項目として出す。
	// タイプを切り替えた後に前のタイプの値（例: 電子書籍の著者）が見えないまま
	// 残らないようにするため。key は保つ（タイプを戻せば推奨項目に戻る）。
	const isSchemaRow = (r) => !!r.key && schemaKeys.has(r.key);
	const customRows = rows.filter((r) => !isSchemaRow(r));
	const findSchemaRow = (key) => rows.find((r) => r.key === key);

	const replaceSchemaValue = (key, value) => {
		const next = rows.filter((r) => r.key !== key);
		const schemaEntry = schema.find((s) => s.key === key);
		if (schemaEntry && value !== '') {
			next.push({ key, label: schemaEntry.label, value });
		}
		onChange(next);
	};

	const replaceCustomRow = (index, patch) => {
		const customs = [...customRows];
		customs[index] = { ...customs[index], ...patch };
		onChange([...rows.filter(isSchemaRow), ...customs]);
	};

	const addCustomRow = () => {
		onChange([...rows, { label: '', value: '' }]);
	};

	const removeCustomRow = (index) => {
		const customs = customRows.filter((_, i) => i !== index);
		onChange([...rows.filter(isSchemaRow), ...customs]);
	};

	return (
		<div className="affilicard-extras-editor">
			<h3>{__('追加情報', 'affilicard')}</h3>
			{schema.map((s) => {
				const current = findSchemaRow(s.key);
				return (
					<TextControl
						key={s.key}
						label={s.label}
						placeholder={s.placeholder}
						value={current?.value ?? ''}
						onChange={(v) => replaceSchemaValue(s.key, v)}
					/>
				);
			})}
			{customRows.map((row, i) => (
				<div
					key={`custom-${i}`}
					className="affilicard-extras-custom-row"
				>
					<TextControl
						label={__('項目名', 'affilicard')}
						placeholder={__('例: シリーズ', 'affilicard')}
						value={row.label ?? ''}
						onChange={(v) => replaceCustomRow(i, { label: v })}
					/>
					<TextControl
						label={__('値', 'affilicard')}
						placeholder={__('入力値', 'affilicard')}
						value={row.value ?? ''}
						onChange={(v) => replaceCustomRow(i, { value: v })}
					/>
					<Button
						variant="link"
						isDestructive
						onClick={() => removeCustomRow(i)}
					>
						{__('削除', 'affilicard')}
					</Button>
				</div>
			))}
			<Button variant="secondary" onClick={addCustomRow}>
				{__('カスタム項目を追加', 'affilicard')}
			</Button>
		</div>
	);
}
