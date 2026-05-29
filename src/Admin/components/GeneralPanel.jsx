import { useEffect, useState } from '@wordpress/element';
import {
	TextControl,
	ToggleControl,
	SelectControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchSettings, updateSettings } from '../api/settings';
import { CronHelpBox } from './CronHelpBox';

export function GeneralPanel() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		fetchSettings()
			.then( setSettings )
			.catch( () => setSettings( {} ) );
	}, [] );

	if ( settings === null ) {
		return <p>{ __( '読み込み中…', 'affilicard' ) }</p>;
	}

	const update = ( patch ) => setSettings( { ...settings, ...patch } );

	const onSave = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const next = await updateSettings( settings );
			setSettings( next );
			setNotice( {
				type: 'success',
				message: __( '保存しました', 'affilicard' ),
			} );
		} catch ( e ) {
			setNotice( {
				type: 'error',
				message: __( '保存に失敗しました', 'affilicard' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="affilicard-general-panel">
			<h2>{ __( '一般設定', 'affilicard' ) }</h2>
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<TextControl
				label={ __( 'キャッシュ TTL (秒)', 'affilicard' ) }
				type="number"
				value={ String( settings.cache_ttl_seconds ?? 86400 ) }
				onChange={ ( v ) =>
					update( { cache_ttl_seconds: parseInt( v, 10 ) || 0 } )
				}
			/>

			<SelectControl
				label={ __( 'デフォルト商品タイプ', 'affilicard' ) }
				value={ settings.default_product_type ?? 'generic' }
				options={ [
					{ label: '汎用', value: 'generic' },
					{ label: '電子書籍', value: 'ebook' },
				] }
				onChange={ ( v ) => update( { default_product_type: v } ) }
			/>

			<ToggleControl
				label={ __( '自動更新を有効化 (WP-Cron)', 'affilicard' ) }
				checked={ Boolean( settings.cron_enabled ) }
				onChange={ ( v ) => update( { cron_enabled: v } ) }
			/>

			{ settings.cron_enabled && <CronHelpBox /> }

			<Button
				variant="primary"
				onClick={ onSave }
				disabled={ saving }
			>
				{ saving
					? __( '保存中…', 'affilicard' )
					: __( '保存', 'affilicard' ) }
			</Button>
		</div>
	);
}
