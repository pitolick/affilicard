import {
	TextControl,
	ToggleControl,
	SelectControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { CredentialEditor } from './CredentialEditor';

const PROVIDER_OPTIONS = [
	{ label: '手動入力', value: 'manual' },
	{ label: 'DMM ebook API', value: 'dmm-ebook' },
];

// Built-in schemas for each provider (avoid round-trip to PHP for schema).
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

export function PlatformEditor( { platform, onChange } ) {
	const update = ( patch ) => onChange( { ...platform, ...patch } );

	return (
		<div className="affilicard-platform-editor">
			<h3>
				{ platform.name } ({ platform.code })
			</h3>
			<ToggleControl
				label={ __( '有効', 'affilicard' ) }
				checked={ Boolean( platform.enabled ) }
				onChange={ ( v ) => update( { enabled: v } ) }
			/>
			<TextControl
				label={ __( '表示名', 'affilicard' ) }
				value={ platform.name ?? '' }
				onChange={ ( v ) => update( { name: v } ) }
			/>
			<TextControl
				label={ __( 'ボタンラベル', 'affilicard' ) }
				value={ platform.buttonLabel ?? '' }
				onChange={ ( v ) => update( { buttonLabel: v } ) }
			/>
			<TextControl
				label={ __( '表示順', 'affilicard' ) }
				type="number"
				value={ String( platform.displayOrder ?? 1 ) }
				onChange={ ( v ) =>
					update( { displayOrder: parseInt( v, 10 ) || 1 } )
				}
			/>
			<SelectControl
				label={ __( 'Provider', 'affilicard' ) }
				value={ platform.provider ?? 'manual' }
				options={ PROVIDER_OPTIONS }
				onChange={ ( v ) => update( { provider: v } ) }
			/>
			<TextControl
				label={ __( 'ブランド色', 'affilicard' ) }
				value={ platform.brandColor ?? '#444444' }
				onChange={ ( v ) => update( { brandColor: v } ) }
			/>
			<TextControl
				label={ __( 'ボタン文字色', 'affilicard' ) }
				value={ platform.buttonTextColor ?? '#ffffff' }
				onChange={ ( v ) => update( { buttonTextColor: v } ) }
			/>

			<CredentialEditor
				platformCode={ platform.code }
				schema={ CRED_SCHEMAS[ platform.provider ] ?? [] }
			/>
		</div>
	);
}
