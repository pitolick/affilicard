import { useEffect, useState } from '@wordpress/element';
import { TextControl, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	fetchCredentials,
	updateCredentials,
	deleteCredentials,
	testConnection,
} from '../api/credentials';

export function AccountCredentialEditor({ account, providers }) {
	const schema = account.credentialsSchema ?? [];
	const [status, setStatus] = useState({}); // { key: {value,isSet} }
	const [inputs, setInputs] = useState({}); // 編集中の値（text の初期値は status.value）
	const [dirty, setDirty] = useState({}); // key -> true
	const [reveal, setReveal] = useState({}); // key -> 表示中か
	const [result, setResult] = useState(null);
	const [tests, setTests] = useState({}); // providerCode -> {ok,message}

	useEffect(() => {
		fetchCredentials(account.code)
			.then((s) => {
				setStatus(s);
				const init = {};
				schema.forEach((f) => {
					init[f.key] =
						f.type === 'password' ? '' : (s[f.key]?.value ?? '');
				});
				setInputs(init);
				setDirty({});
			})
			.catch(() => setStatus({}));
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [account.code]);

	const onChange = (key, v) => {
		setInputs({ ...inputs, [key]: v });
		setDirty({ ...dirty, [key]: true });
	};

	const toggleReveal = (key) =>
		setReveal((prev) => ({ ...prev, [key]: !prev[key] }));

	const dirtyValues = () => {
		const out = {};
		Object.keys(dirty).forEach((k) => {
			if (dirty[k]) {
				out[k] = inputs[k];
			}
		});
		return out;
	};

	const onSave = async () => {
		setResult(null);
		try {
			const next = await updateCredentials(account.code, dirtyValues());
			setStatus(next);
			setDirty({});
			setInputs((prev) => {
				const merged = { ...prev };
				schema.forEach((f) => {
					if (f.type === 'password') {
						merged[f.key] = '';
					} else {
						merged[f.key] = next[f.key]?.value ?? '';
					}
				});
				return merged;
			});
			setResult({
				ok: true,
				message: __('認証情報を保存しました', 'affilicard'),
			});
		} catch (e) {
			const missing = e?.data?.missing || e?.missing;
			setResult({
				ok: false,
				message: missing
					? __('必須項目が未入力です', 'affilicard')
					: __('保存に失敗しました', 'affilicard'),
			});
		}
	};

	const onDelete = async () => {
		// eslint-disable-next-line no-alert
		if (
			!window.confirm(
				__('このアカウントの認証情報を削除しますか？', 'affilicard')
			)
		) {
			return;
		}
		try {
			const next = await deleteCredentials(account.code);
			setStatus(next);
			setDirty({});
			setInputs((prev) => {
				const cleared = { ...prev };
				schema.forEach((f) => {
					cleared[f.key] = '';
				});
				return cleared;
			});
			setResult({
				ok: true,
				message: __('認証情報を削除しました', 'affilicard'),
			});
		} catch {
			setResult({
				ok: false,
				message: __('削除に失敗しました', 'affilicard'),
			});
		}
	};

	const onTest = async (providerCode) => {
		try {
			const r = await testConnection(providerCode, dirtyValues());
			setTests({ ...tests, [providerCode]: r });
		} catch {
			setTests({
				...tests,
				[providerCode]: {
					ok: false,
					message: __('接続テストに失敗しました', 'affilicard'),
				},
			});
		}
	};

	return (
		<div className="affilicard-account-credential-editor">
			{schema.map((f) => {
				const isPassword = f.type === 'password';
				const isSet = Boolean(status[f.key]?.isSet);
				return (
					<div
						key={f.key}
						className="affilicard-account-credential-editor__field"
					>
						<div className="affilicard-account-credential-editor__field-row">
							<TextControl
								label={f.label}
								type={
									isPassword && !reveal[f.key]
										? 'password'
										: 'text'
								}
								value={inputs[f.key] ?? ''}
								placeholder={
									isPassword && isSet
										? __(
												'設定済み（変更する場合のみ入力）',
												'affilicard'
											)
										: ''
								}
								onChange={(v) => onChange(f.key, v)}
							/>
							{isPassword && (
								<Button
									variant="tertiary"
									onClick={() => toggleReveal(f.key)}
								>
									{reveal[f.key]
										? __('隠す', 'affilicard')
										: __('表示', 'affilicard')}
								</Button>
							)}
						</div>
						{isPassword && isSet && !dirty[f.key] && (
							<span className="affilicard-account-credential-editor__badge">
								{__('設定済み', 'affilicard')}
							</span>
						)}
					</div>
				);
			})}

			{providers.length > 0 && (
				<div className="affilicard-account-tests">
					<p className="description">
						{__('このアカウントを使う連携:', 'affilicard')}
					</p>
					{providers.map((p) => (
						<div
							key={p.code}
							className="affilicard-account-tests__row"
						>
							<span>{p.label}</span>
							<Button
								variant="secondary"
								onClick={() => onTest(p.code)}
							>
								{__('接続テスト', 'affilicard')}
							</Button>
							{tests[p.code] && (
								<span>
									{tests[p.code].ok ? '✓' : '✗'}{' '}
									{tests[p.code].message}
								</span>
							)}
						</div>
					))}
				</div>
			)}

			<div className="affilicard-account-credential-editor__actions">
				<Button variant="secondary" onClick={onSave}>
					{__('認証情報を保存', 'affilicard')}
				</Button>
				<Button variant="tertiary" isDestructive onClick={onDelete}>
					{__('認証情報を削除', 'affilicard')}
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
