import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const App = () => (
	<div className="eventflow-admin">
		<h1>{ __( 'Events are ready to organize', 'eventflow' ) }</h1>
		<p>
			{ __(
				'Create an event, add its schedule and ticket capacity, then publish it for visitors.',
				'eventflow'
			) }
		</p>
		<p>
			<a
				className="button button-primary"
				href="edit.php?post_type=ef_event"
			>
				{ __( 'Manage events', 'eventflow' ) }
			</a>
		</p>
	</div>
);
const root = document.getElementById( 'eventflow-admin-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
