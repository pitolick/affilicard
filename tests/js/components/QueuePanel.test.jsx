/**
 * Tests for src/Admin/components/QueuePanel.jsx
 *
 * @wordpress/components is mocked globally via jest.config.js moduleNameMapper
 * (see tests/js/__mocks__/wordpress-components.js).
 *
 * v2.4.0: the queue summary is keyed by account code ('rakuten'/'dmm') rather than
 * provider code, and each row carries its own `label` (from the REST payload via
 * AccountRegistry) so the component never needs a client-side code→label map.
 */

jest.mock('../../../src/Admin/api/queue');
jest.mock('../../../src/Admin/api/settings');

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueuePanel } from '../../../src/Admin/components/QueuePanel';
import {
	fetchQueueStats,
	setPaused,
	clearQueue,
	deleteFailed,
	retryFailed,
	cancelPending,
} from '../../../src/Admin/api/queue';
import { fetchSettings, updateSettings } from '../../../src/Admin/api/settings';

const baseStats = {
	summary: {
		rakuten: {
			code: 'rakuten',
			label: '楽天',
			pending: 3,
			in_progress: 1,
			failed: 2,
			complete: 42,
		},
		dmm: {
			code: 'dmm',
			label: 'DMM',
			pending: 0,
			in_progress: 0,
			failed: 0,
			complete: 0,
		},
	},
	depth: 4,
	paused: false,
	// 掃引が直近に完走した想定（Task 12）。個別テストで上書きして未実行/超過ケースを検証する。
	last_sweep_completed_at: new Date(
		Date.now() - 30 * 60 * 1000
	).toISOString(),
};

const baseSettings = {
	queue_paused: false,
	refresh_interval_hours: 3,
	throttle_overrides: { rakuten: 1500 },
	retention_done_hours: 24,
	retention_failed_days: 7,
};

beforeEach(() => {
	fetchQueueStats.mockReset();
	setPaused.mockReset();
	clearQueue.mockReset();
	deleteFailed.mockReset();
	retryFailed.mockReset();
	cancelPending.mockReset();
	fetchSettings.mockReset();
	updateSettings.mockReset();

	fetchQueueStats.mockResolvedValue(baseStats);
	fetchSettings.mockResolvedValue(baseSettings);
	setPaused.mockResolvedValue({ ok: true, paused: true });
	clearQueue.mockResolvedValue({ ok: true, cleared: 3 });
	deleteFailed.mockResolvedValue({ ok: true, deleted: 2 });
	retryFailed.mockResolvedValue({ ok: true, retried: 2 });
	cancelPending.mockResolvedValue({ ok: true, cancelled: 3 });
	updateSettings.mockResolvedValue(baseSettings);
});

describe('QueuePanel', () => {
	test('shows loading state before fetch resolves', () => {
		fetchQueueStats.mockReturnValue(new Promise(() => {}));
		fetchSettings.mockReturnValue(new Promise(() => {}));
		render(<QueuePanel />);
		expect(screen.getByText('読み込み中…')).toBeInTheDocument();
	});

	test('renders account-wise summary counts and the queue depth', async () => {
		render(<QueuePanel />);
		await waitFor(() =>
			expect(screen.getByText('楽天')).toBeInTheDocument()
		);
		expect(screen.getByText('DMM')).toBeInTheDocument();
		// exact-string matches (not substring regex) so the assertion targets
		// only the leaf <span>, not an ancestor whose aggregated textContent
		// happens to contain the same substring (which would make
		// getByText() throw for finding multiple matches).
		expect(screen.getByText('未処理: 3')).toBeInTheDocument();
		expect(screen.getByText('処理中: 1')).toBeInTheDocument();
		expect(screen.getByText('失敗: 2')).toBeInTheDocument();
		// 完了件数（症状4 の可視化ギャップ対応）も account 行に表示する。
		expect(screen.getByText('完了: 42')).toBeInTheDocument();
		expect(screen.getByText('キューの深さ: 4')).toBeInTheDocument();
	});

	// cron 健全性の可視化（Task 12）: 最後の掃引からの経過時間と、想定間隔（3倍）を
	// 超えた場合の警告表示を検証する。「now」を固定せず Date.now() 起点で相対時刻を
	// 組み立てることで、実行タイミングに依存しないテストにする。
	test('shows how many hours ago the last sweep completed', async () => {
		const fiveHoursAgo = new Date(
			Date.now() - 5 * 60 * 60 * 1000
		).toISOString();
		fetchQueueStats.mockResolvedValue({
			...baseStats,
			last_sweep_completed_at: fiveHoursAgo,
		});
		fetchSettings.mockResolvedValue({
			...baseSettings,
			refresh_interval_hours: 3,
		});

		render(<QueuePanel />);

		await waitFor(() =>
			expect(screen.getByText(/最後の掃引/)).toBeInTheDocument()
		);
		expect(screen.getByText(/5時間前/)).toBeInTheDocument();
	});

	test('shows "未実行" when the sweep has never completed', async () => {
		fetchQueueStats.mockResolvedValue({
			...baseStats,
			last_sweep_completed_at: '',
		});

		render(<QueuePanel />);

		await waitFor(() =>
			expect(screen.getByText(/最後の掃引/)).toBeInTheDocument()
		);
		expect(screen.getByText(/未実行/)).toBeInTheDocument();
		expect(
			screen.queryByRole('link', { name: /運用ドキュメント/ })
		).not.toBeInTheDocument();
	});

	test('warns with a link to the operations doc when the sweep is far overdue', async () => {
		// refresh_interval_hours=3 の3倍=9h。10h経過は超過している。
		const tenHoursAgo = new Date(
			Date.now() - 10 * 60 * 60 * 1000
		).toISOString();
		fetchQueueStats.mockResolvedValue({
			...baseStats,
			last_sweep_completed_at: tenHoursAgo,
		});
		fetchSettings.mockResolvedValue({
			...baseSettings,
			refresh_interval_hours: 3,
		});

		render(<QueuePanel />);

		await waitFor(() =>
			expect(screen.getByText(/運用ドキュメント/)).toBeInTheDocument()
		);
		const link = screen.getByRole('link', { name: /運用ドキュメント/ });
		expect(link.getAttribute('href')).toEqual(
			expect.stringContaining('operations-refresh-queue.md')
		);
	});

	test('does not warn when the sweep is within the expected interval', async () => {
		const oneHourAgo = new Date(
			Date.now() - 1 * 60 * 60 * 1000
		).toISOString();
		fetchQueueStats.mockResolvedValue({
			...baseStats,
			last_sweep_completed_at: oneHourAgo,
		});
		fetchSettings.mockResolvedValue({
			...baseSettings,
			refresh_interval_hours: 3,
		});

		render(<QueuePanel />);

		await waitFor(() =>
			expect(screen.getByText(/最後の掃引/)).toBeInTheDocument()
		);
		expect(
			screen.queryByRole('link', { name: /運用ドキュメント/ })
		).not.toBeInTheDocument();
	});

	test('toggling the pause switch calls setPaused with the new value', async () => {
		render(<QueuePanel />);
		const toggle = await screen.findByLabelText(/一時停止/);
		fireEvent.click(toggle);
		await waitFor(() => expect(setPaused).toHaveBeenCalledWith(true));
	});

	test('"キューを全て削除" calls both clearQueue and deleteFailed', async () => {
		render(<QueuePanel />);
		const button = await screen.findByRole('button', {
			name: '未処理と失敗を削除',
		});
		fireEvent.click(button);
		await waitFor(() => expect(clearQueue).toHaveBeenCalled());
		await waitFor(() => expect(deleteFailed).toHaveBeenCalled());
	});

	test('"キューを全て削除" shows an accurate success message that excludes in-progress jobs', async () => {
		render(<QueuePanel />);
		const button = await screen.findByRole('button', {
			name: '未処理と失敗を削除',
		});
		fireEvent.click(button);
		// 実行中のジョブは止められないため、成功通知は「全て削除」ではなく
		// 未処理と失敗のみを削除した旨・実行中は残る旨を明示する（CodeRabbit 指摘）。
		await waitFor(() =>
			expect(
				screen.getByText(
					'未処理と失敗のジョブを削除しました（実行中のジョブは完了まで動きます）'
				)
			).toBeInTheDocument()
		);
	});

	test('when the post-action stats reload fails, it shows an error (not a false success)', async () => {
		// 初回ロードは成功、操作後の再取得だけ失敗させる。
		fetchQueueStats
			.mockResolvedValueOnce(baseStats)
			.mockRejectedValueOnce(new Error('stats reload failed'));

		render(<QueuePanel />);
		const button = await screen.findByRole('button', {
			name: '失敗分を削除',
		});
		fireEvent.click(button);

		await waitFor(() => expect(deleteFailed).toHaveBeenCalled());
		// 操作自体は走っても、統計の再取得に失敗したら成功と言い切らない。
		await waitFor(() =>
			expect(
				screen.getByText(
					'操作は実行しましたが、最新のキュー状態の取得に失敗しました。画面を再読み込みしてください。'
				)
			).toBeInTheDocument()
		);
		expect(
			screen.queryByText('失敗分を削除しました')
		).not.toBeInTheDocument();
	});

	test('initial stats load failure surfaces an error notice instead of a silent empty queue', async () => {
		fetchQueueStats.mockReset();
		fetchQueueStats.mockRejectedValue(new Error('boom'));

		render(<QueuePanel />);

		// 空 stats へ黙って差し替えて「成功／空」に見せず、取得失敗の Notice を出す。
		await waitFor(() =>
			expect(
				screen.getByText(
					'キュー状態の取得に失敗しました。表示が最新でない可能性があります。'
				)
			).toBeInTheDocument()
		);
	});

	test('"失敗分を削除" calls only deleteFailed', async () => {
		render(<QueuePanel />);
		const button = await screen.findByRole('button', {
			name: '失敗分を削除',
		});
		fireEvent.click(button);
		await waitFor(() => expect(deleteFailed).toHaveBeenCalled());
		expect(clearQueue).not.toHaveBeenCalled();
	});

	test('"失敗分を再試行" calls retryFailed', async () => {
		render(<QueuePanel />);
		const button = await screen.findByRole('button', {
			name: '失敗分を再試行',
		});
		fireEvent.click(button);
		await waitFor(() => expect(retryFailed).toHaveBeenCalled());
	});

	test('"未処理をキャンセル" calls cancelPending', async () => {
		render(<QueuePanel />);
		const button = await screen.findByRole('button', {
			name: '未処理をキャンセル',
		});
		fireEvent.click(button);
		await waitFor(() => expect(cancelPending).toHaveBeenCalled());
	});

	test('saving settings calls updateSettings with edited retention/throttle values', async () => {
		render(<QueuePanel />);
		const retentionInput = await screen.findByLabelText(
			/失敗保持日数/
		);
		fireEvent.change(retentionInput, { target: { value: '14' } });

		const saveButton = screen.getByRole('button', { name: '保存' });
		fireEvent.click(saveButton);

		await waitFor(() =>
			expect(updateSettings).toHaveBeenCalledWith(
				expect.objectContaining({ retention_failed_days: 14 })
			)
		);
	});

	test('saving settings after toggling pause does not send queue_paused (pause stays server-owned)', async () => {
		render(<QueuePanel />);

		const toggle = await screen.findByLabelText(/一時停止/);
		fireEvent.click(toggle);
		await waitFor(() => expect(setPaused).toHaveBeenCalledWith(true));

		const saveButton = screen.getByRole('button', { name: '保存' });
		fireEvent.click(saveButton);

		await waitFor(() => expect(updateSettings).toHaveBeenCalled());
		const payload = updateSettings.mock.calls[0][0];
		expect(payload).not.toHaveProperty('queue_paused');

		// pause の切り替え結果（stats.paused）は保存後も維持される
		// （loadStats は onSaveSettings から呼ばれないため revert されない）。
		expect(screen.getByLabelText(/一時停止/)).toBeChecked();
	});

	test('links to the affilicard queue jobs page', async () => {
		render(<QueuePanel />);
		const link = await screen.findByRole('link', {
			name: /更新キュー（ジョブ一覧）を開く/,
		});
		expect(link).toHaveAttribute(
			'href',
			'edit.php?post_type=affilicard_product&page=affilicard-queue-jobs'
		);
	});
});
