/**
 * Tests for src/Admin/components/CronHelpBox.jsx
 */

import { render, screen } from '@testing-library/react';
import { CronHelpBox } from '../../../src/Admin/components/CronHelpBox';

describe( 'CronHelpBox', () => {
	test( 'renders the wp cron command in a code block', () => {
		render( <CronHelpBox /> );
		expect(
			screen.getByText( 'wp cron event run affilicard_refresh_listings' )
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
