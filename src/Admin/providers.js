// Provider 定義の共有定数。PlatformEditor（Provider 選択）と
// ApiCredentialsPanel（認証情報編集）で共用する。
export const PROVIDER_OPTIONS = [
	{ label: '手動入力', value: 'manual' },
	{ label: 'DMM ebook API', value: 'dmm-ebook' },
	{ label: '楽天Kobo API', value: 'rakuten-kobo' },
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
	'rakuten-kobo': [
		{
			key: 'application_id',
			label: 'アプリID',
			type: 'password',
			required: true,
		},
		{
			key: 'access_key',
			label: 'アクセスキー',
			type: 'password',
			required: true,
		},
		{
			key: 'affiliate_id',
			label: 'アフィリエイトID',
			type: 'password',
			required: true,
		},
		{
			key: 'allowed_domain',
			label: '許可ドメイン（Origin。空ならサイトURL）',
			type: 'text',
			required: false,
		},
	],
};
