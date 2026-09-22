/**
 * `.github/workflows/release.yml` のバージョン整合ガード（package.json /
 * package-lock.json）を、ワークフローに書かれているシェルそのままで検証する。
 *
 * ここを実行しないと守れない——このガードはタグ push でしか走らず、壊れても
 * リリースの瞬間まで誰も気づかない。YAML から該当ブロックを切り出して実際の
 * bash で走らせ、固定物（fixture）の package.json に対する判定を確かめる。
 *
 * jq はランナー（ubuntu-latest）にプリインストールされており、CI の JS ジョブと
 * 開発機のどちらにも存在する。
 */
import { execFileSync } from 'child_process';
import fs from 'fs';
import os from 'os';
import path from 'path';

const WORKFLOW = path.join( __dirname, '../../../.github/workflows/release.yml' );

/**
 * release.yml から `for f in package.json package-lock.json; do ... done` を切り出す。
 *
 * @return {string} そのまま bash へ渡せるスクリプト。
 */
function extractPackageVersionGuard() {
	const lines = fs.readFileSync( WORKFLOW, 'utf8' ).split( '\n' );
	const start = lines.findIndex( ( line ) =>
		/^\s*for f in package\.json package-lock\.json; do\s*$/.test( line )
	);
	if ( start < 0 ) {
		throw new Error(
			'release.yml に package.json / package-lock.json のバージョン検証ループが見つかりません。'
		);
	}
	const end = lines.findIndex(
		( line, index ) => index > start && /^\s*done\s*$/.test( line )
	);
	if ( end < 0 ) {
		throw new Error( 'バージョン検証ループの done が見つかりません。' );
	}
	const indent = lines[ start ].match( /^\s*/ )[ 0 ];
	return lines
		.slice( start, end + 1 )
		.map( ( line ) => ( line.startsWith( indent ) ? line.slice( indent.length ) : line ) )
		.join( '\n' );
}

/**
 * 切り出したガードを fixture ディレクトリで実行する。
 *
 * @param {string} dir     package.json / package-lock.json を置いたディレクトリ。
 * @param {string} version タグ由来のバージョン。
 * @return {{status: number, output: string}} 終了コードと標準出力＋標準エラー。
 */
function runGuard( dir, version ) {
	const script = `VERSION="${ version }"\n${ extractPackageVersionGuard() }`;
	try {
		const stdout = execFileSync( 'bash', [ '-e', '-c', script ], {
			cwd: dir,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
		return { status: 0, output: stdout };
	} catch ( error ) {
		return {
			status: error.status,
			output: `${ error.stdout || '' }${ error.stderr || '' }`,
		};
	}
}

/** version を先頭 5 行より後ろに持つ package.json（キー順は JSON の自由）。 */
const versionBelowFifthLine = ( version ) =>
	JSON.stringify(
		{
			name: 'affilicard',
			private: true,
			description:
				'商品カードを出す WordPress プラグイン。キーの並びは JSON として自由である。',
			license: 'GPL-2.0-or-later',
			author: 'pitolick',
			homepage: 'https://example.test/affilicard',
			repository: { type: 'git', url: 'https://example.test/affilicard.git' },
			version,
		},
		null,
		2
	);

describe( 'release.yml の package.json バージョン整合ガード', () => {
	let dir;

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( os.tmpdir(), 'affilicard-release-guard-' ) );
	} );

	afterEach( () => {
		fs.rmSync( dir, { recursive: true, force: true } );
	} );

	const write = ( contents ) => {
		fs.writeFileSync( path.join( dir, 'package.json' ), contents );
		fs.writeFileSync( path.join( dir, 'package-lock.json' ), contents );
	};

	test( 'version が先頭 5 行に無くても、一致していればリリースを止めない', () => {
		// 説明文の追加やキーの並べ替えだけでリリースが落ちてはいけない。
		write( versionBelowFifthLine( '4.1.0' ) );

		const result = runGuard( dir, '4.1.0' );

		expect( result.output ).not.toMatch( /::error::/ );
		expect( result.status ).toBe( 0 );
	} );

	test( 'version が先頭 5 行に無く、かつ不一致ならリリースを止める', () => {
		write( versionBelowFifthLine( '4.0.9' ) );

		const result = runGuard( dir, '4.1.0' );

		expect( result.status ).toBe( 1 );
		expect( result.output ).toContain(
			'::error::package.json の version (4.0.9) がタグ (4.1.0) と一致しません。'
		);
	} );

	test( '通常の並び（version が先頭付近）でも一致すれば通り、不一致なら止める', () => {
		const normal = ( version ) =>
			JSON.stringify( { name: 'affilicard', version, private: true }, null, 2 );

		write( normal( '4.1.0' ) );
		expect( runGuard( dir, '4.1.0' ).status ).toBe( 0 );

		write( normal( '3.9.0' ) );
		const mismatch = runGuard( dir, '4.1.0' );
		expect( mismatch.status ).toBe( 1 );
		expect( mismatch.output ).toContain(
			'::error::package.json の version (3.9.0) がタグ (4.1.0) と一致しません。'
		);
	} );

	test( 'version キーが無ければ空として報告し、リリースを止める', () => {
		// 旧実装（head -5 | grep）と同じく「取れなければ空」。null を印字しない。
		write( JSON.stringify( { name: 'affilicard' }, null, 2 ) );

		const result = runGuard( dir, '4.1.0' );

		expect( result.status ).toBe( 1 );
		expect( result.output ).toContain(
			'::error::package.json の version () がタグ (4.1.0) と一致しません。'
		);
	} );
} );
