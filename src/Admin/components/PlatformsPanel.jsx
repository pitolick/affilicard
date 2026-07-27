import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, TabPanel } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { fetchPlatforms, updatePlatforms } from '../api/platforms';
import { enabledRanks, movePlatform } from '../platformOrder';
import { useFlipReorder } from '../useFlipReorder';
import { PlatformEditor } from './PlatformEditor';
import { ApiCredentialsPanel } from './ApiCredentialsPanel';

const TYPE_LABELS = {
	generic: __( '汎用', 'affilicard' ),
	ebook: __( '電子書籍', 'affilicard' ),
	vod: __( 'VOD', 'affilicard' ),
};

const API_TAB = '__api__';

// platforms の applicableTypes から、1 件以上存在する型を出現順に抽出する。
// 注: applicableTypes が空/未設定の platform はどの型タブにも現れない
// （保存対象には含まれる）。シードは全 platform に applicableTypes を持つ前提。
function usedTypes( platforms ) {
	const seen = [];
	for ( const p of platforms ) {
		const types = Array.isArray( p.applicableTypes ) ? p.applicableTypes : [];
		for ( const t of types ) {
			if ( ! seen.includes( t ) ) {
				seen.push( t );
			}
		}
	}
	return seen;
}

export function PlatformsPanel() {
	const [ platforms, setPlatforms ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ announcement, setAnnouncement ] = useState( '' );
	const listRef = useRef( null );
	const capturePositions = useFlipReorder( listRef );

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

	// ↑ / ↓ 押下。動かせなかったときは movePlatform が同一参照を返すので何もしない。
	const onMove = ( platform, type, direction, event ) => {
		const next = movePlatform( platforms, type, platform.code, direction );
		if ( next === platforms ) {
			return;
		}
		// FLIP の First はここで測る。state 更新後の再描画を待つと、アコーディオン開閉のような
		// 親を再描画しない DOM 変化を挟んだ直後に座標が古くなる（stale になる）ため。
		capturePositions();
		setPlatforms( next );
		setAnnouncement(
			sprintf(
				/* translators: 1: platform display name, 2: new position (1-based) */
				__( '%1$sを %2$d 番目に移動しました', 'affilicard' ),
				platform.name,
				enabledRanks( next, type )[ platform.code ]
			)
		);

		// 端に到達して押したボタン自身が disabled になると、フォーカスが body に落ちて
		// キーボード操作が途切れる。同じ行のもう一方のボタンへ移す。
		const button = event?.currentTarget;
		if ( ! button ) {
			return;
		}
		window.requestAnimationFrame( () => {
			if ( ! button.disabled ) {
				return;
			}
			const sibling = button.parentElement?.querySelector(
				'button:not([disabled])'
			);
			sibling?.focus();
		} );
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

	const types = usedTypes( platforms );
	const tabs = [
		...types.map( ( t ) => ( {
			name: t,
			title: TYPE_LABELS[ t ] ?? t,
		} ) ),
		{ name: API_TAB, title: __( 'API 認証', 'affilicard' ) },
	];

	return (
		<div className="affilicard-platforms-panel">
			<h2>{ __( 'プラットフォーム設定', 'affilicard' ) }</h2>
			{ notice && (
				<Notice status={ notice.type } onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }
			<TabPanel className="affilicard-platform-type-tabs" tabs={ tabs }>
				{ ( tab ) => {
					if ( tab.name === API_TAB ) {
						return <ApiCredentialsPanel />;
					}
					const ranks = enabledRanks( platforms, tab.name );
					const indexed = platforms
						.map( ( p, i ) => ( { p, i } ) )
						.filter(
							( { p } ) =>
								Array.isArray( p.applicableTypes ) &&
								p.applicableTypes.includes( tab.name )
						);
					const enabledCodes = indexed
						.filter( ( { p } ) => p.enabled )
						.map( ( { p } ) => p.code );
					return (
						<>
							<div className="affilicard-platforms-panel__order-help">
								<p>
									{ __(
										'この順番で商品カードのボタンが上から並びます。',
										'affilicard'
									) }
								</p>
								<ul>
									<li>
										{ __(
											'無効なプラットフォームはカードに表示されないため、この順番には含まれません。',
											'affilicard'
										) }
									</li>
									<li>
										{ __(
											'順番が意味を持つのはタブ（商品タイプ）の中だけです。',
											'affilicard'
										) }
									</li>
									<li>
										{ __(
											'「保存」を押すと、公開済みの記事のカードにも反映されます。',
											'affilicard'
										) }
									</li>
								</ul>
							</div>
							<div
								className="affilicard-platform-list"
								ref={ listRef }
							>
								{ indexed.map( ( { p, i }, localIdx ) => (
									<div
										className={
											p.enabled
												? 'affilicard-platform-row'
												: 'affilicard-platform-row affilicard-platform-row--disabled'
										}
										data-platform-code={ p.code }
										key={ p.code }
									>
										<div className="affilicard-platform-row__order">
											{ p.enabled ? (
												<span className="affilicard-platform-row__rank">
													{ ranks[ p.code ] }
													<span className="screen-reader-text">
														{ __(
															'番目',
															'affilicard'
														) }
													</span>
												</span>
											) : (
												<span
													className="affilicard-platform-row__rank"
													aria-hidden="true"
												>
													—
												</span>
											) }
											{ p.enabled && (
												<>
													<Button
														icon="arrow-up-alt2"
														size="small"
														disabled={
															enabledCodes[ 0 ] ===
															p.code
														}
														label={ sprintf(
															/* translators: %s: platform display name */
															__(
																'%sを上へ移動',
																'affilicard'
															),
															p.name
														) }
														onClick={ ( event ) =>
															onMove(
																p,
																tab.name,
																'up',
																event
															)
														}
													/>
													<Button
														icon="arrow-down-alt2"
														size="small"
														disabled={
															enabledCodes[
																enabledCodes.length -
																	1
															] === p.code
														}
														label={ sprintf(
															/* translators: %s: platform display name */
															__(
																'%sを下へ移動',
																'affilicard'
															),
															p.name
														) }
														onClick={ ( event ) =>
															onMove(
																p,
																tab.name,
																'down',
																event
															)
														}
													/>
												</>
											) }
										</div>
										<div className="affilicard-platform-row__body">
											<PlatformEditor
												platform={ p }
												onChange={ onChange( i ) }
												initialOpen={ localIdx === 0 }
											/>
										</div>
									</div>
								) ) }
							</div>
							<div
								className="screen-reader-text"
								aria-live="polite"
							>
								{ announcement }
							</div>
							<div className="affilicard-platforms-panel__save">
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
						</>
					);
				} }
			</TabPanel>
		</div>
	);
}
