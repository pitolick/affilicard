import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchPlatforms, updatePlatforms } from '../api/platforms';
import { PlatformEditor } from './PlatformEditor';
import { ApiCredentialsPanel } from './ApiCredentialsPanel';

const TYPE_LABELS = {
	generic: __('汎用', 'affilicard'),
	ebook: __('電子書籍', 'affilicard'),
	vod: __('VOD', 'affilicard'),
};

const API_TAB = '__api__';

// platforms の applicableTypes から、1 件以上存在する型を出現順に抽出する。
// 注: applicableTypes が空/未設定の platform はどの型タブにも現れない
// （保存対象には含まれる）。シードは全 platform に applicableTypes を持つ前提。
function usedTypes(platforms) {
	const seen = [];
	for (const p of platforms) {
		const types = Array.isArray(p.applicableTypes) ? p.applicableTypes : [];
		for (const t of types) {
			if (!seen.includes(t)) {
				seen.push(t);
			}
		}
	}
	return seen;
}

export function PlatformsPanel() {
	const [platforms, setPlatforms] = useState(null);
	const [saving, setSaving] = useState(false);
	const [notice, setNotice] = useState(null);

	useEffect(() => {
		fetchPlatforms()
			.then(setPlatforms)
			.catch(() => setPlatforms([]));
	}, []);

	if (platforms === null) {
		return <p>{__('読み込み中…', 'affilicard')}</p>;
	}
	if (platforms.length === 0) {
		return <p>{__('プラットフォームがありません', 'affilicard')}</p>;
	}

	const onChange = (idx) => (next) => {
		const copy = [...platforms];
		copy[idx] = next;
		setPlatforms(copy);
	};

	const onSave = async () => {
		setSaving(true);
		setNotice(null);
		try {
			const next = await updatePlatforms(platforms);
			setPlatforms(next);
			setNotice({
				type: 'success',
				message: __('保存しました', 'affilicard'),
			});
		} catch {
			setNotice({
				type: 'error',
				message: __('保存に失敗しました', 'affilicard'),
			});
		} finally {
			setSaving(false);
		}
	};

	const types = usedTypes(platforms);
	const tabs = [
		...types.map((t) => ({
			name: t,
			title: TYPE_LABELS[t] ?? t,
		})),
		{ name: API_TAB, title: __('API 認証', 'affilicard') },
	];

	return (
		<div className="affilicard-platforms-panel">
			<h2>{__('プラットフォーム設定', 'affilicard')}</h2>
			{notice && (
				<Notice status={notice.type} onRemove={() => setNotice(null)}>
					{notice.message}
				</Notice>
			)}
			<TabPanel className="affilicard-platform-type-tabs" tabs={tabs}>
				{(tab) => {
					if (tab.name === API_TAB) {
						return <ApiCredentialsPanel />;
					}
					const indexed = platforms
						.map((p, i) => ({ p, i }))
						.filter(
							({ p }) =>
								Array.isArray(p.applicableTypes) &&
								p.applicableTypes.includes(tab.name)
						);
					return (
						<>
							{indexed.map(({ p, i }, localIdx) => (
								<PlatformEditor
									key={p.code}
									platform={p}
									onChange={onChange(i)}
									initialOpen={localIdx === 0}
								/>
							))}
							<div className="affilicard-platforms-panel__save">
								<Button
									variant="primary"
									onClick={onSave}
									disabled={saving}
								>
									{saving
										? __('保存中…', 'affilicard')
										: __('保存', 'affilicard')}
								</Button>
							</div>
						</>
					);
				}}
			</TabPanel>
		</div>
	);
}
