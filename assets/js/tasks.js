/*
 * OdooWoo portal: task poller. Plain script; window.odoowoo.tasks in the browser, module.exports under Node.
 * Reads the shape Routes::task_view() returns: {task_id, state, label, current, total, message, results}.
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var START = 2000;
	var CAP = 10000;
	var GROW = 1.5;
	var HIDDEN = 30000;
	var LIMIT = 30 * 60 * 1000;
	var MAX_FAILURES = 5;

	var FINISHED = [ 'done', 'skipped', 'failed' ];

	var FALLBACK = {
		taskCompleted: 'Completed',
		taskSkipped: 'Skipped',
		taskRunning: 'Running',
		taskFailed: 'Failed',
		taskStarting: 'Starting…',
		taskProgress: '%1$s of %2$s',
		taskCompletedIssues: 'Completed, but some items were not synced',
	};
	var t = core.translator( FALLBACK );

	/** The word for a state; anything unknown is Failed. */
	function label( state ) {
		switch ( state ) {
			case 'done':
				return t( 'taskCompleted' );
			case 'skipped':
				return t( 'taskSkipped' );
			case 'running':
				return t( 'taskRunning' );
			default:
				return t( 'taskFailed' );
		}
	}

	/** 0 to 100, never NaN. */
	function percent( current, total ) {
		var c = Number( current );
		var n = Number( total );

		if ( ! isFinite( c ) || ! isFinite( n ) || n <= 0 || c <= 0 ) {
			return 0;
		}

		return Math.min( 100, Math.round( ( c / n ) * 100 ) );
	}

	function whole( v ) {
		var n = Number( v );

		return isFinite( n ) && n > 0 ? Math.floor( n ) : 0;
	}

	/** Did the task end "done" with items that were skipped or failed? Then "Completed" alone misleads. */
	function hasUnsyncedItems( results ) {
		return Array.isArray( results ) && results.some( function ( item ) {
			return item && typeof item === 'object' && [ 'skipped', 'failed', 'error' ].indexOf( item.status ) !== -1;
		} );
	}

	/** What to show for a task reply's data: the portal's own running text, never Django's. */
	function view( data ) {
		var d = data && typeof data === 'object' ? data : {};
		var state = d.state === 'running' || FINISHED.indexOf( d.state ) !== -1 ? d.state : 'failed';
		var current = whole( d.current );
		var total = whole( d.total );
		var indeterminate = state === 'running' && total === 0;
		var line;

		if ( indeterminate ) {
			line = t( 'taskStarting' );
		} else if ( state === 'running' ) {
			line = label( state ) + ': ' + core.fill( t( 'taskProgress' ), current, total );
		} else if ( state === 'done' && hasUnsyncedItems( d.results ) ) {
			line = t( 'taskCompletedIssues' );
		} else {
			line = label( state );
		}

		return {
			state: state,
			label: label( state ),
			current: current,
			total: total,
			percent: percent( current, total ),
			indeterminate: indeterminate,
			text: line,
			message: state === 'running' || typeof d.message !== 'string' ? '' : d.message,
			results: Array.isArray( d.results ) ? d.results : [],
			task_id: typeof d.task_id === 'string' ? d.task_id : '',
		};
	}

	/** The one line for a task view: its state, then (when it has ended) the message Django's task gave. */
	function lineOf( v ) {
		return typeof v.message === 'string' && v.message !== '' ? v.text + ': ' + v.message : v.text;
	}

	/**
	 * Polls a task until it finishes. fetch() resolves a core.api() reply. Starts at 2 s, grows
	 * 1.5x to 10 s, resets when progress changes, 30 s while the tab is hidden. Stops on done,
	 * skipped or failed; on an unknown task (404 or odoowoo_portal_task_unknown); on a lapsed
	 * session; after 5 failed polls in a row; and after 30 minutes. Resolves {reason, view}.
	 */
	async function poll( o ) {
		var sleep = o.sleep || function ( ms ) { return new Promise( function ( r ) { setTimeout( r, ms ); } ); };
		var now = o.now || Date.now;
		var hidden = o.hidden || function () { return typeof G.document !== 'undefined' && G.document.hidden === true; };
		// By default a poll ends by itself when its element has left the page.
		var cancelled = o.cancelled || function () { return !! o.el && o.el.isConnected === false; };
		var started = now();
		var delay = START;
		var failures = 0;
		var last = null;

		function finish( reason, v ) {
			if ( typeof o.onDone === 'function' ) {
				o.onDone( reason, v );
			}

			return { reason: reason, view: v };
		}

		for ( ;; ) {
			if ( cancelled() ) {
				return { reason: 'cancelled', view: last };
			}

			var reply = await o.fetch();

			if ( reply && reply.ok === true ) {
				failures = 0;

				var v = view( reply.data );
				var changed = ! last || last.current !== v.current || last.total !== v.total || last.state !== v.state;

				last = v;

				if ( typeof o.onUpdate === 'function' ) {
					o.onUpdate( v );
				}

				if ( v.state !== 'running' ) {
					return finish( v.state, v );
				}

				if ( cancelled() ) {
					return { reason: 'cancelled', view: v };
				}

				if ( changed && v.current > 0 ) {
					delay = START;
				}
			} else {
				var code = reply ? reply.code : '';

				if ( code === 'odoowoo_portal_task_unknown' || ( reply && reply.status === 404 ) ) {
					return finish( 'unknown', last );
				}

				if ( code === 'session' ) {
					return finish( 'session', last );
				}

				failures += 1;

				if ( failures >= MAX_FAILURES ) {
					return finish( 'errors', last );
				}
			}

			if ( now() - started >= LIMIT ) {
				return finish( 'timeout', last );
			}

			if ( hidden() ) {
				await sleep( HIDDEN );
			} else {
				await sleep( delay );
				delay = Math.min( CAP, delay * GROW );
			}

			if ( now() - started >= LIMIT ) {
				return finish( 'timeout', last );
			}
		}
	}

	/** Shows a task's progress in a container (a <progress>, text, then the message of a finished task). DOM glue. */
	function render( container, v ) {
		var doc = G.document;

		if ( container.isConnected === false ) {
			return false;
		}

		var bar = container.querySelector( 'progress' );
		var line = container.querySelector( '.odoowoo-progress__text' );

		if ( ! bar ) {
			container.textContent = '';
			bar = doc.createElement( 'progress' );
			bar.className = 'odoowoo-progress__bar';
			bar.setAttribute( 'max', '100' );
			line = doc.createElement( 'p' );
			line.className = 'odoowoo-progress__text';
			line.setAttribute( 'role', 'status' );
			container.appendChild( bar );
			container.appendChild( line );
		}

		if ( v.indeterminate ) {
			bar.removeAttribute( 'value' );
		} else {
			bar.setAttribute( 'value', String( v.percent ) );
		}

		line.textContent = lineOf( v );

		return true;
	}

	var out = { poll: poll, label: label, percent: percent, view: view, render: render, line: lineOf };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = out;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = window.odoowoo || {};
		window.odoowoo.tasks = out;
	}
}() );
