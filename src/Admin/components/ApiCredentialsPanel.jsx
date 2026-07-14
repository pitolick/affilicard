import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ACCOUNTS } from '../accounts';
import { PROVIDER_OPTIONS, providerAccount } from '../providers';
import { AccountCredentialEditor } from './AccountCredentialEditor';

export function ApiCredentialsPanel() {
	return (
		<div className="affilicard-api-credentials-panel">
			<h2>{__('API 認証', 'affilicard')}</h2>
			<p className="description">
				{__(
					'API 連携を使うアカウントの認証情報を設定します（アカウント単位で共有）。',
					'affilicard'
				)}
			</p>
			{ACCOUNTS.length === 0 && (
				<p className="description">
					{__(
						'認証情報を必要とするアカウントはありません。',
						'affilicard'
					)}
				</p>
			)}
			{ACCOUNTS.map((account) => {
				const providers = PROVIDER_OPTIONS.filter(
					(o) => providerAccount(o.value) === account.code
				).map((o) => ({ code: o.value, label: o.label }));
				return (
					<PanelBody
						key={account.code}
						title={account.label}
						initialOpen={!account.isConfigured}
					>
						<AccountCredentialEditor
							account={account}
							providers={providers}
						/>
					</PanelBody>
				);
			})}
		</div>
	);
}
