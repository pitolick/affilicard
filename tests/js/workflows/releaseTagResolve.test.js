/**
 * `.github/workflows/release.yml` の「リリースするバージョンとタグを決める」ステップを、
 * ワークフローに書かれているシェルそのままで検証する。
 *
 * 手動実行（workflow_dispatch）の入口のガードは、実際にリリースするときにしか走らない。
 * 壊れても誰も気づかないまま、main 以外のコミットや既存タグへリリースしてしまうため、
 * YAML から run ブロックを切り出して実際の bash で走らせる。
 */
import { execFileSync } from 'child_process';
import fs from 'fs';
import os from 'os';
import path from 'path';

const WORKFLOW = path.join( __dirname, '../../../.github/workflows/release.yml' );
const STEP_NAME = 'リリースするバージョンとタグを決める';

/**
 * release.yml から STEP_NAME の `run: |` ブロックを切り出す。
 *
 * @return {string} そのまま bash へ渡せるスクリプト。
 */
function extractResolveStep() {
	const lines = fs.readFileSync( WORKFLOW, 'utf8' ).split( '\n' );
	const nameIndex = lines.findIndex( ( line ) =>
		new RegExp( `^\\s*- name: ${ STEP_NAME }\\s*$` ).test( line )
	);
	if ( nameIndex < 0 ) {
		throw new Error( `release.yml に「${ STEP_NAME }」ステップが見つかりません。` );
	}
	const runIndex = lines.findIndex(
		( line, index ) => index > nameIndex && /^\s*run: \|\s*$/.test( line )
	);
	if ( runIndex < 0 ) {
		throw new Error( `「${ STEP_NAME }」ステップに run: | がありません。` );
	}
	const runIndent = lines[ runIndex ].match( /^\s*/ )[ 0 ].length;
	const body = [];
	for ( let i = runIndex + 1; i < lines.length; i++ ) {
		const line = lines[ i ];
		if ( line.trim() !== '' && line.match( /^\s*/ )[ 0 ].length <= runIndent ) {
			break;
		}
		body.push( line );
	}
	const indent = body.find( ( line ) => line.trim() !== '' ).match( /^\s*/ )[ 0 ];
	return body
		.map( ( line ) => ( line.startsWith( indent ) ? line.slice( indent.length ) : line ) )
		.join( '\n' );
}

/**
 * @param {string} cwd 実行ディレクトリ（origin を持つ git リポジトリ）。
 * @param {Object} env GitHub Actions が渡す環境変数。
 * @return {{status: number, output: string, githubEnv: string}} 終了コード・出力・GITHUB_ENV の中身。
 */
function runStep( cwd, env ) {
	const githubEnv = path.join( cwd, '..', 'github-env' );
	fs.writeFileSync( githubEnv, '' );
	const options = {
		cwd,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: { PATH: process.env.PATH, GITHUB_ENV: githubEnv, GITHUB_SHA: 'abc123', ...env },
	};
	let status = 0;
	let output;
	try {
		output = execFileSync( 'bash', [ '-e', '-c', extractResolveStep() ], options );
	} catch ( error ) {
		status = error.status;
		output = `${ error.stdout || '' }${ error.stderr || '' }`;
	}
	return { status, output, githubEnv: fs.readFileSync( githubEnv, 'utf8' ) };
}

const git = ( cwd, ...args ) =>
	execFileSync( 'git', args, {
		cwd,
		stdio: 'ignore',
		env: {
			PATH: process.env.PATH,
			GIT_AUTHOR_NAME: 't',
			GIT_AUTHOR_EMAIL: 't@example.test',
			GIT_COMMITTER_NAME: 't',
			GIT_COMMITTER_EMAIL: 't@example.test',
			GIT_CONFIG_NOSYSTEM: '1',
			HOME: cwd,
		},
	} );

describe( 'release.yml のバージョンとタグの決定', () => {
	let root;
	let work;

	beforeEach( () => {
		// origin（bare）に v4.1.0 だけがある状態を作る。
		root = fs.mkdtempSync( path.join( os.tmpdir(), 'affilicard-release-resolve-' ) );
		const origin = path.join( root, 'origin.git' );
		work = path.join( root, 'work' );
		fs.mkdirSync( work );
		git( root, 'init', '-q', '--bare', origin );
		git( work, 'init', '-q' );
		git( work, 'commit', '-q', '--allow-empty', '-m', 'init' );
		git( work, 'tag', 'v4.1.0' );
		git( work, 'remote', 'add', 'origin', origin );
		git( work, 'push', '-q', 'origin', 'HEAD:refs/heads/main', 'refs/tags/v4.1.0' );
	} );

	afterEach( () => {
		fs.rmSync( root, { recursive: true, force: true } );
	} );

	const dispatch = ( version, ref = 'refs/heads/main' ) =>
		runStep( work, {
			GITHUB_EVENT_NAME: 'workflow_dispatch',
			GITHUB_REF: ref,
			INPUT_VERSION: version,
		} );

	test( '手動実行: main から未使用のバージョンを指定すれば、VERSION と TAG を決める', () => {
		const result = dispatch( '4.2.0' );

		expect( result.status ).toBe( 0 );
		expect( result.githubEnv ).toBe( 'VERSION=4.2.0\nTAG=v4.2.0\n' );
	} );

	test( '手動実行: 先頭の v は付けても付けなくてもよい', () => {
		const result = dispatch( 'v4.2.0' );

		expect( result.status ).toBe( 0 );
		expect( result.githubEnv ).toBe( 'VERSION=4.2.0\nTAG=v4.2.0\n' );
	} );

	test( '手動実行: main 以外からは止める', () => {
		const result = dispatch( '4.2.0', 'refs/heads/feature' );

		expect( result.status ).toBe( 1 );
		expect( result.output ).toContain( '::error::手動リリースは main からだけ実行できます' );
		expect( result.githubEnv ).toBe( '' );
	} );

	test.each( [ '4.2', '4.2.0; rm -rf /', '$(echo 4.2.0)', '' ] )(
		'手動実行: x.y.z 形式でない入力（%p）は止める',
		( version ) => {
			const result = dispatch( version );

			expect( result.status ).toBe( 1 );
			expect( result.output ).toContain( 'が x.y.z の形式ではありません。' );
			expect( result.githubEnv ).toBe( '' );
		}
	);

	test( '手動実行: 既にあるタグへはリリースしない', () => {
		const result = dispatch( '4.1.0' );

		expect( result.status ).toBe( 1 );
		expect( result.output ).toContain( '::error::タグ v4.1.0 は既にあります。' );
		expect( result.githubEnv ).toBe( '' );
	} );

	test( 'タグ push: タグ名からバージョンを決める（従来どおり）', () => {
		const result = runStep( work, {
			GITHUB_EVENT_NAME: 'push',
			GITHUB_REF: 'refs/tags/v4.1.0',
			GITHUB_REF_NAME: 'v4.1.0',
		} );

		expect( result.status ).toBe( 0 );
		expect( result.githubEnv ).toBe( 'VERSION=4.1.0\nTAG=v4.1.0\n' );
	} );
} );
