import { __ } from '@wordpress/i18n';
import { CRED_SCHEMAS, PROVIDER_OPTIONS } from '../providers';
import { CredentialEditor } from './CredentialEditor';

const providerLabel = (code) =>
	PROVIDER_OPTIONS.find((o) => o.value === code)?.label ?? code;

export function ApiCredentialsPanel() {
	const providers = Object.keys(CRED_SCHEMAS).filter(
		(code) => (CRED_SCHEMAS[code] ?? []).length > 0
	);

	return (
		<div className="affilicard-api-credentials-panel">
			<h2>{__('API 認証', 'affilicard')}</h2>
			<p className="description">
				{__(
					'API 連携を使う Provider の認証情報を設定します（Provider 単位で 1 回だけ）。',
					'affilicard'
				)}
			</p>
			{providers.length === 0 && (
				<p className="description">
					{__(
						'認証情報を必要とする Provider はありません。',
						'affilicard'
					)}
				</p>
			)}
			{providers.map((code) => (
				<div
					key={code}
					className="affilicard-api-credentials-panel__provider"
				>
					<h3>{providerLabel(code)}</h3>
					<CredentialEditor
						providerCode={code}
						schema={CRED_SCHEMAS[code]}
					/>
				</div>
			))}
		</div>
	);
}
