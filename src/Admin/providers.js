// Provider 定義の共有定数。PlatformEditor（Provider 選択）と
// ApiCredentialsPanel（認証情報編集）で共用する。
export const PROVIDER_OPTIONS = [
	{ label: '手動入力', value: 'manual' },
	{ label: 'DMM ebook API', value: 'dmm-ebook' },
];

// provider code → 認証情報スキーマ（空配列は認証不要）。
export const CRED_SCHEMAS = {
	manual: [],
	'dmm-ebook': [
		{
			key: 'api_id',
			label: 'API ID',
			type: 'password',
			required: true,
		},
		{
			key: 'affiliate_id',
			label: 'アフィリエイト ID',
			type: 'password',
			required: true,
		},
	],
};
