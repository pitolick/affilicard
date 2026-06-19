import { useEntityProp } from '@wordpress/core-data';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ListingsEditor } from './ListingsEditor';
import { ExtrasEditor } from './ExtrasEditor';
import { StockStatusSelect } from './StockStatusSelect';

const PRODUCT_TYPE_OPTIONS = [
	{ value: 'generic', label: __( '汎用', 'affilicard' ) },
	{ value: 'ebook', label: __( '電子書籍', 'affilicard' ) },
];

const asArray = ( v ) => ( Array.isArray( v ) ? v : [] );

export function ProductSettingsPanel() {
	const [ meta, setMeta ] = useEntityProp(
		'postType',
		'affilicard_product',
		'meta'
	);
	const m = meta || {};

	const productType = m.affilicard_product_type || 'generic';
	const stockStatus = m.affilicard_stock_status || 'available';
	const listings = asArray( m.affilicard_listings );
	const extras = asArray( m.affilicard_extras );

	const patch = ( next ) => setMeta( ( prev ) => ( { ...( prev || {} ), ...next } ) );

	return (
		<div className="affilicard-product-settings">
			<PanelBody title={ __( '基本', 'affilicard' ) } initialOpen={ true }>
				<SelectControl
					label={ __( '商品タイプ', 'affilicard' ) }
					value={ productType }
					options={ PRODUCT_TYPE_OPTIONS }
					onChange={ ( v ) => patch( { affilicard_product_type: v } ) }
				/>
				<StockStatusSelect
					value={ stockStatus }
					onChange={ ( v ) => patch( { affilicard_stock_status: v } ) }
				/>
			</PanelBody>
			<PanelBody title={ __( '追加情報', 'affilicard' ) } initialOpen={ false }>
				<ExtrasEditor
					productType={ productType }
					extras={ extras }
					onChange={ ( next ) => patch( { affilicard_extras: next } ) }
				/>
			</PanelBody>
			<PanelBody
				title={ __( 'プラットフォーム listing', 'affilicard' ) }
				initialOpen={ false }
			>
				<ListingsEditor
					listings={ listings }
					onChange={ ( next ) => patch( { affilicard_listings: next } ) }
				/>
			</PanelBody>
		</div>
	);
}
