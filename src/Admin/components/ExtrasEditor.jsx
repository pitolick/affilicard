import { TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

// Schemas keyed by product_type. Keep in sync with PHP side (EbookType / GenericType).
const SCHEMAS = {
	generic: [],
	ebook: [
		{ key: 'author', label: __('著者', 'affilicard') },
		{ key: 'publisher', label: __('出版社', 'affilicard') },
		{ key: 'isbn', label: __('ISBN', 'affilicard') },
	],
};

export function ExtrasEditor({ productType, extras, onChange }) {
	const schema = SCHEMAS[productType] ?? [];
	const rows = Array.isArray(extras) ? extras : [];

	const customRows = rows.filter((r) => !r.key);
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
		onChange([...rows.filter((r) => r.key), ...customs]);
	};

	const addCustomRow = () => {
		onChange([...rows, { label: '', value: '' }]);
	};

	const removeCustomRow = (index) => {
		const customs = customRows.filter((_, i) => i !== index);
		onChange([...rows.filter((r) => r.key), ...customs]);
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
						value={row.label ?? ''}
						onChange={(v) => replaceCustomRow(i, { label: v })}
					/>
					<TextControl
						label={__('値', 'affilicard')}
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
