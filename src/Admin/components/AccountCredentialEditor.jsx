import { useEffect, useState } from '@wordpress/element';
import { TextControl, Button, Notice, Dashicon } from '@wordpress/components';
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
	const [missing, setMissing] = useState([]); // required だが未入力だったフィールドキー
	const [busy, setBusy] = useState(false); // 保存/削除/テストの実行中フラグ（同時実行防止）

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
		setMissing([]);
		setBusy(true);
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
			const missingKeys = e?.data?.missing || e?.missing || [];
			setMissing(missingKeys);
			setResult({
				ok: false,
				message:
					missingKeys.length > 0
						? __('必須項目が未入力です', 'affilicard')
						: __('保存に失敗しました', 'affilicard'),
			});
		} finally {
			setBusy(false);
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
		setBusy(true);
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
		} finally {
			setBusy(false);
		}
	};

	const onTest = async (providerCode) => {
		setBusy(true);
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
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="affilicard-account-credential-editor">
			{schema.map((f) => {
				const isPassword = f.type === 'password';
				const isSet = Boolean(status[f.key]?.isSet);
				const isMissing = missing.includes(f.key);
				return (
					<div
						key={f.key}
						className={
							'affilicard-account-credential-editor__field' +
							(isMissing
								? ' affilicard-account-credential-editor__field--error'
								: '')
						}
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
								className={
									isMissing
										? 'affilicard-account-credential-editor__input--error'
										: undefined
								}
								help={
									isMissing
										? __('必須項目です', 'affilicard')
										: undefined
								}
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
									className="affilicard-account-credential-editor__reveal"
									icon={
										<Dashicon
											icon={
												reveal[f.key]
													? 'hidden'
													: 'visibility'
											}
										/>
									}
									label={
										reveal[f.key]
											? __('隠す', 'affilicard')
											: __('表示', 'affilicard')
									}
									showTooltip
									onClick={() => toggleReveal(f.key)}
								/>
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
								disabled={busy}
								onClick={() => onTest(p.code)}
							>
								{__('接続テスト', 'affilicard')}
							</Button>
							{tests[p.code] && (
								<span
									className={
										tests[p.code].ok
											? 'affilicard-account-tests__result--ok'
											: 'affilicard-account-tests__result--ng'
									}
								>
									{tests[p.code].ok ? '✓' : '✗'}{' '}
									{tests[p.code].message}
								</span>
							)}
						</div>
					))}
				</div>
			)}

			<div className="affilicard-account-credential-editor__actions">
				<Button variant="secondary" disabled={busy} onClick={onSave}>
					{__('認証情報を保存', 'affilicard')}
				</Button>
				<Button
					variant="tertiary"
					isDestructive
					disabled={busy}
					onClick={onDelete}
				>
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
