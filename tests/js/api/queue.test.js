/**
 * Tests for src/Admin/api/queue.js
 */

jest.mock('@wordpress/api-fetch');

import apiFetch from '@wordpress/api-fetch';
import {
	fetchQueueStats,
	setPaused,
	clearQueue,
	deleteFailed,
	retryFailed,
	cancelPending,
} from '../../../src/Admin/api/queue';

beforeEach(() => {
	apiFetch.mockClear();
	apiFetch.mockResolvedValue({});
});

describe('api/queue', () => {
	test('fetchQueueStats calls GET /affilicard/v1/refresh-queue', async () => {
		apiFetch.mockResolvedValue({ summary: {}, depth: 0, paused: false });
		const out = await fetchQueueStats();
		expect(apiFetch).toHaveBeenCalledWith({
			path: '/affilicard/v1/refresh-queue',
		});
		expect(out.paused).toBe(false);
	});

	test('setPaused calls POST /affilicard/v1/refresh-queue/pause with paused flag', async () => {
		await setPaused(true);
		expect(apiFetch).toHaveBeenCalledWith({
			path: '/affilicard/v1/refresh-queue/pause',
			method: 'POST',
			data: { paused: true },
		});
	});

	test('clearQueue calls DELETE /affilicard/v1/refresh-queue', async () => {
		await clearQueue();
		expect(apiFetch).toHaveBeenCalledWith({
			path: '/affilicard/v1/refresh-queue',
			method: 'DELETE',
		});
	});

	test('deleteFailed calls DELETE /affilicard/v1/refresh-queue/failed', async () => {
		await deleteFailed();
		expect(apiFetch).toHaveBeenCalledWith({
			path: '/affilicard/v1/refresh-queue/failed',
			method: 'DELETE',
		});
	});

	test('retryFailed calls POST /affilicard/v1/refresh-queue/retry-failed', async () => {
		await retryFailed();
		expect(apiFetch).toHaveBeenCalledWith({
			path: '/affilicard/v1/refresh-queue/retry-failed',
			method: 'POST',
		});
	});

	test('cancelPending calls POST /affilicard/v1/refresh-queue/cancel-pending', async () => {
		await cancelPending();
		expect(apiFetch).toHaveBeenCalledWith({
			path: '/affilicard/v1/refresh-queue/cancel-pending',
			method: 'POST',
		});
	});
});
