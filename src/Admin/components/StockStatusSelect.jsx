import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const OPTIONS = [
	{ value: 'available', label: __( '販売中', 'affilicard' ) },
	{ value: 'out_of_stock', label: __( '在庫切れ', 'affilicard' ) },
	{ value: 'discontinued', label: __( '取扱終了', 'affilicard' ) },
];

export function StockStatusSelect( { value, onChange } ) {
	return (
		<SelectControl
			label={ __( '在庫状況', 'affilicard' ) }
			value={ value ?? 'available' }
			options={ OPTIONS }
			onChange={ onChange }
		/>
	);
}
