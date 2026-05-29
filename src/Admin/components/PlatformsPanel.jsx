import { useEffect, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchPlatforms, updatePlatforms } from '../api/platforms';
import { PlatformEditor } from './PlatformEditor';

export function PlatformsPanel() {
	const [ platforms, setPlatforms ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		fetchPlatforms()
			.then( setPlatforms )
			.catch( () => setPlatforms( [] ) );
	}, [] );

	if ( platforms === null ) {
		return <p>{ __( '読み込み中…', 'affilicard' ) }</p>;
	}
	if ( platforms.length === 0 ) {
		return <p>{ __( 'プラットフォームがありません', 'affilicard' ) }</p>;
	}

	const onChange = ( idx ) => ( next ) => {
		const copy = [ ...platforms ];
		copy[ idx ] = next;
		setPlatforms( copy );
	};

	const onSave = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const next = await updatePlatforms( platforms );
			setPlatforms( next );
			setNotice( {
				type: 'success',
				message: __( '保存しました', 'affilicard' ),
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __( '保存に失敗しました', 'affilicard' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="affilicard-platforms-panel">
			<h2>{ __( 'プラットフォーム設定', 'affilicard' ) }</h2>
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ platforms.map( ( p, i ) => (
				<PlatformEditor
					key={ p.code }
					platform={ p }
					onChange={ onChange( i ) }
				/>
			) ) }
			<Button variant="primary" onClick={ onSave } disabled={ saving }>
				{ saving
					? __( '保存中…', 'affilicard' )
					: __( '保存', 'affilicard' ) }
			</Button>
		</div>
	);
}
