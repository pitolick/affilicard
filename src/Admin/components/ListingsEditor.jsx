import { useEffect, useState } from '@wordpress/element';
import {
	TextControl,
	ToggleControl,
	SelectControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchPlatforms } from '../api/platforms';

const UPDATE_MODE_OPTIONS = [
	{ value: 'manual', label: __( '手動', 'affilicard' ) },
	{ value: 'api', label: __( 'API', 'affilicard' ) },
];

const EMPTY_LISTING = {
	platform: '',
	enabled: true,
	update_mode: 'manual',
	auto_update: false,
	external_id: '',
	regular_url: '',
	affiliate_url: '',
	price: '',
	list_price: '',
	badge: '',
	image_url: '',
	button_label_override: '',
	last_fetched_at: '',
	fetch_error: null,
	platform_extras: [],
};

function isFallback( listing ) {
	return (
		( listing.affiliate_url ?? '' ) === '' &&
		( listing.regular_url ?? '' ) !== ''
	);
}

export function ListingsEditor( { listings, onChange } ) {
	const [ platforms, setPlatforms ] = useState( null );

	useEffect( () => {
		fetchPlatforms()
			.then( ( list ) =>
				setPlatforms( Array.isArray( list ) ? list : [] )
			)
			.catch( () => setPlatforms( [] ) );
	}, [] );

	const rows = Array.isArray( listings ) ? listings : [];

	const updateRow = ( idx, patch ) => {
		const next = rows.map( ( r, i ) =>
			i === idx ? { ...r, ...patch } : r
		);
		onChange( next );
	};

	const removeRow = ( idx ) =>
		onChange( rows.filter( ( _, i ) => i !== idx ) );

	const addRow = () => onChange( [ ...rows, { ...EMPTY_LISTING } ] );

	if ( platforms === null ) {
		return <p>{ __( 'プラットフォーム読み込み中…', 'affilicard' ) }</p>;
	}

	const platformOptions = [
		{ value: '', label: __( '— 選択 —', 'affilicard' ) },
		...platforms.map( ( p ) => ( { value: p.code, label: p.name } ) ),
	];

	return (
		<div className="affilicard-listings-editor">
			<h3>{ __( 'プラットフォーム listing', 'affilicard' ) }</h3>
			{ rows.length === 0 && (
				<p className="description">
					{ __( 'listing がありません', 'affilicard' ) }
				</p>
			) }
			{ rows.map( ( row, i ) => (
				<div key={ i } className="affilicard-listing-row">
					{ isFallback( row ) && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'⚠ アフィリエイト URL 未設定、通常 URL にフォールバック中',
								'affilicard'
							) }
						</Notice>
					) }
					<SelectControl
						label={ __( 'プラットフォーム', 'affilicard' ) }
						value={ row.platform }
						options={ platformOptions }
						onChange={ ( v ) => updateRow( i, { platform: v } ) }
					/>
					<ToggleControl
						label={ __( '有効', 'affilicard' ) }
						checked={ Boolean( row.enabled ) }
						onChange={ ( v ) => updateRow( i, { enabled: v } ) }
					/>
					<SelectControl
						label={ __( '更新モード', 'affilicard' ) }
						value={ row.update_mode }
						options={ UPDATE_MODE_OPTIONS }
						onChange={ ( v ) => updateRow( i, { update_mode: v } ) }
					/>
					{ row.update_mode === 'api' && (
						<ToggleControl
							label={ __( '自動更新', 'affilicard' ) }
							checked={ Boolean( row.auto_update ) }
							onChange={ ( v ) =>
								updateRow( i, { auto_update: v } )
							}
						/>
					) }
					<TextControl
						label={ __( '外部 ID', 'affilicard' ) }
						value={ row.external_id }
						onChange={ ( v ) => updateRow( i, { external_id: v } ) }
					/>
					<TextControl
						label={ __( '通常 URL', 'affilicard' ) }
						value={ row.regular_url }
						onChange={ ( v ) => updateRow( i, { regular_url: v } ) }
					/>
					<TextControl
						label={ __( 'アフィリエイト URL', 'affilicard' ) }
						value={ row.affiliate_url }
						onChange={ ( v ) =>
							updateRow( i, { affiliate_url: v } )
						}
					/>
					<TextControl
						label={ __( '価格', 'affilicard' ) }
						value={ row.price }
						onChange={ ( v ) => updateRow( i, { price: v } ) }
					/>
					<TextControl
						label={ __( '参考価格', 'affilicard' ) }
						value={ row.list_price }
						onChange={ ( v ) => updateRow( i, { list_price: v } ) }
					/>
					<TextControl
						label={ __( 'バッジ', 'affilicard' ) }
						value={ row.badge }
						onChange={ ( v ) => updateRow( i, { badge: v } ) }
					/>
					<TextControl
						label={ __( '画像 URL', 'affilicard' ) }
						value={ row.image_url }
						onChange={ ( v ) => updateRow( i, { image_url: v } ) }
					/>
					<TextControl
						label={ __( 'ボタンラベル上書き', 'affilicard' ) }
						value={ row.button_label_override }
						onChange={ ( v ) =>
							updateRow( i, { button_label_override: v } )
						}
					/>
					<Button
						variant="link"
						isDestructive
						onClick={ () => removeRow( i ) }
					>
						{ __( 'listing を削除', 'affilicard' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ addRow }>
				{ __( 'listing を追加', 'affilicard' ) }
			</Button>
		</div>
	);
}
