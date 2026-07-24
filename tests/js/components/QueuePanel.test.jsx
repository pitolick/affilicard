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
		rakuten: { code: 'rakuten', label: '楽天', pending: 3, in_progress: 1, failed: 2 },
		dmm: { code: 'dmm', label: 'DMM', pending: 0, in_progress: 0, failed: 0 },
	},
	depth: 4,
	paused: false,
};

const baseSettings = {
	queue_paused: false,
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
		expect(screen.getByText('キューの深さ: 4')).toBeInTheDocument();
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
			name: 'キューを全て削除',
		});
		fireEvent.click(button);
		await waitFor(() => expect(clearQueue).toHaveBeenCalled());
		await waitFor(() => expect(deleteFailed).toHaveBeenCalled());
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

	test('links to the Scheduled Actions tools page', async () => {
		render(<QueuePanel />);
		const link = await screen.findByRole('link', {
			name: /Scheduled Actions/,
		});
		expect(link).toHaveAttribute(
			'href',
			'tools.php?page=action-scheduler&s=affilicard'
		);
	});
});
