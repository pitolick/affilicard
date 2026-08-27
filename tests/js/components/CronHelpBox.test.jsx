/**
 * Tests for src/Admin/components/CronHelpBox.jsx
 */

import { render, screen } from '@testing-library/react';
import { CronHelpBox } from '../../../src/Admin/components/CronHelpBox';

describe( 'CronHelpBox', () => {
	test( 'renders the wp-cron sweep-event command in a code block', () => {
		render( <CronHelpBox /> );
		expect(
			screen.getByText( 'wp cron event run --due-now' )
		).toBeInTheDocument();
	} );

	test( 'renders the action-scheduler queue-runner command in a code block', () => {
		render( <CronHelpBox /> );
		expect(
			screen.getByText( 'wp action-scheduler run --batches=1' )
		).toBeInTheDocument();
	} );

	test( 'renders a notice-info container', () => {
		const { container } = render( <CronHelpBox /> );
		expect(
			container.querySelector( '.affilicard-cron-help' )
		).toBeInTheDocument();
		expect(
			container.querySelector( '.notice-info' )
		).toBeInTheDocument();
	} );
} );
