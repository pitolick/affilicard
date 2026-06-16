import { useEffect, useState } from '@wordpress/element';
import { TextControl, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	fetchCredentials,
	updateCredentials,
	testConnection,
} from '../api/credentials';

export function CredentialEditor({ providerCode, schema }) {
	// schema: list of { key, label, type, required }
	const [values, setValues] = useState({});
	const [testing, setTesting] = useState(false);
	const [result, setResult] = useState(null);
	const [saving, setSaving] = useState(false);

	useEffect(() => {
		if (!schema?.length) {
			return;
		}
		fetchCredentials(providerCode)
			.then(setValues)
			.catch(() => setValues({}));
	}, [providerCode, schema]);

	if (!schema?.length) {
		return (
			<p className="description">
				{__('この Provider は認証情報を必要としません。', 'affilicard')}
			</p>
		);
	}

	const onChange = (key, v) => setValues({ ...values, [key]: v });

	const onSave = async () => {
		setSaving(true);
		setResult(null);
		try {
			const next = await updateCredentials(providerCode, values);
			setValues(next);
			setResult({
				ok: true,
				message: __('認証情報を保存しました', 'affilicard'),
			});
		} catch {
			setResult({
				ok: false,
				message: __('保存に失敗しました', 'affilicard'),
			});
		} finally {
			setSaving(false);
		}
	};

	const onTest = async () => {
		setTesting(true);
		setResult(null);
		try {
			const r = await testConnection(providerCode, values);
			setResult(r);
		} catch {
			setResult({
				ok: false,
				message: __('接続テストに失敗しました', 'affilicard'),
			});
		} finally {
			setTesting(false);
		}
	};

	return (
		<div className="affilicard-credential-editor">
			<h4>{__('認証情報', 'affilicard')}</h4>
			{schema.map((field) => (
				<TextControl
					key={field.key}
					label={field.label}
					type={field.type === 'password' ? 'password' : 'text'}
					value={values[field.key] ?? ''}
					onChange={(v) => onChange(field.key, v)}
				/>
			))}
			<div className="affilicard-credential-actions">
				<Button variant="secondary" onClick={onSave} disabled={saving}>
					{saving
						? __('保存中…', 'affilicard')
						: __('認証情報を保存', 'affilicard')}
				</Button>
				<Button variant="secondary" onClick={onTest} disabled={testing}>
					{testing
						? __('テスト中…', 'affilicard')
						: __('接続テスト', 'affilicard')}
				</Button>
			</div>
			{result && (
				<Notice
					status={result.ok ? 'success' : 'error'}
					onRemove={() => setResult(null)}
				>
					{result.message}
				</Notice>
			)}
		</div>
	);
}
