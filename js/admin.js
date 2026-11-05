( function () {
	'use strict';

	var cfg = window.shift8Woodpecker;
	if ( ! cfg ) {
		return;
	}

	var timer = null;

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.set( key, extra[ key ] );
		} );
		body.set( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function el( id ) {
		return document.getElementById( id );
	}

	// Everything below uses textContent: pipeline data originates from a remote service.
	function showMessage( text, type ) {
		var box = el( 's8w-message' );
		if ( ! box ) {
			return;
		}
		box.className = text ? 'notice inline notice-' + type : '';
		box.textContent = '';
		if ( text ) {
			var p = document.createElement( 'p' );
			p.textContent = text;
			box.appendChild( p );
		}
	}

	function row( container, label, value ) {
		var p = document.createElement( 'p' );
		var strong = document.createElement( 'strong' );
		strong.textContent = label + ': ';
		p.appendChild( strong );
		p.appendChild( document.createTextNode( value ) );
		container.appendChild( p );
		return p;
	}

	function render( pipeline ) {
		var box = el( 's8w-pipeline' );
		var cancel = el( 's8w-cancel' );
		var deploy = el( 's8w-deploy' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		if ( ! pipeline ) {
			return;
		}
		var i18n = cfg.i18n;
		var heading = document.createElement( 'h2' );
		heading.textContent = i18n.pipeline + ' #' + pipeline.number;
		box.appendChild( heading );
		var stateText = i18n.states[ pipeline.state ] || pipeline.state;
		var status = row( box, i18n.status, stateText );
		status.className = 's8w-status s8w-status-' + pipeline.state;
		if ( pipeline.started ) {
			row( box, i18n.startedAt, pipeline.started );
		}
		if ( pipeline.finished ) {
			row( box, i18n.finishedAt, pipeline.finished );
		}
		if ( /^https?:\/\//.test( pipeline.url ) ) {
			var p = document.createElement( 'p' );
			var a = document.createElement( 'a' );
			a.href = pipeline.url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			a.textContent = i18n.viewPipeline;
			p.appendChild( a );
			box.appendChild( p );
		}
		if ( cancel ) {
			cancel.hidden = ! pipeline.active;
		}
		if ( deploy ) {
			deploy.disabled = pipeline.active;
		}
	}

	function renderSchedule( schedule ) {
		var box = el( 's8w-schedule-status' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		if ( ! schedule ) {
			return;
		}
		var i18n = cfg.i18n;
		var p = document.createElement( 'p' );
		p.textContent = i18n.scheduledFor.replace( '%1$s', schedule.when ).replace( '%2$s', schedule.user );
		if ( schedule.overdue ) {
			p.textContent += ' ' + i18n.overdue;
		}
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button';
		button.textContent = i18n.cancelSchedule;
		button.addEventListener( 'click', unschedule );
		p.appendChild( document.createTextNode( ' ' ) );
		p.appendChild( button );
		box.appendChild( p );
	}

	function renderLog( log ) {
		var table = el( 's8w-log' );
		if ( ! table ) {
			return;
		}
		var body = table.tBodies[ 0 ];
		body.textContent = '';
		if ( ! log || ! log.length ) {
			var empty = body.insertRow();
			var cell = empty.insertCell();
			cell.colSpan = 4;
			cell.textContent = cfg.i18n.noActivity;
			return;
		}
		log.forEach( function ( entry ) {
			var tr = body.insertRow();
			[ entry.date, entry.user, entry.action, entry.details ].forEach( function ( value ) {
				tr.insertCell().textContent = value;
			} );
		} );
	}

	function apply( data ) {
		render( data.pipeline );
		renderSchedule( data.schedule );
		renderLog( data.log );
		if ( ( data.pipeline && data.pipeline.active ) || data.schedule ) {
			schedulePoll();
		} else {
			stopPolling();
		}
	}

	function unschedule() {
		if ( ! window.confirm( cfg.i18n.confirmUnschedule ) ) {
			return;
		}
		post( 'shift8_woodpecker_unschedule' ).then( function ( result ) {
			handle( result, apply );
		} ).catch( function () {
			showMessage( cfg.i18n.requestFailed, 'error' );
		} );
	}

	function stopPolling() {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
	}

	function schedulePoll() {
		stopPolling();
		timer = window.setTimeout( poll, cfg.interval );
	}

	function handle( result, onSuccess ) {
		if ( result && result.success ) {
			onSuccess( result.data );
			return;
		}
		var message = result && result.data && result.data.message ? result.data.message : cfg.i18n.requestFailed;
		showMessage( message, 'error' );
	}

	function poll() {
		post( 'shift8_woodpecker_status' ).then( function ( result ) {
			handle( result, apply );
		} ).catch( function () {
			showMessage( cfg.i18n.requestFailed, 'error' );
			schedulePoll();
		} );
	}

	function initDeployment() {
		var deploy = el( 's8w-deploy' );
		var cancel = el( 's8w-cancel' );
		if ( ! deploy ) {
			return;
		}

		apply( { pipeline: cfg.pipeline, schedule: cfg.schedule, log: cfg.log } );

		var schedule = el( 's8w-schedule' );
		var runAt = el( 's8w-run-at' );
		if ( schedule && runAt ) {
			schedule.addEventListener( 'click', function () {
				if ( ! runAt.value ) {
					showMessage( cfg.i18n.chooseTime, 'error' );
					return;
				}
				if ( ! window.confirm( cfg.i18n.confirmSchedule.replace( '%s', runAt.value.replace( 'T', ' ' ) ) ) ) {
					return;
				}
				schedule.disabled = true;
				showMessage( '', 'info' );
				post( 'shift8_woodpecker_schedule', { run_at: runAt.value } ).then( function ( result ) {
					handle( result, apply );
				} ).catch( function () {
					showMessage( cfg.i18n.requestFailed, 'error' );
				} ).then( function () {
					schedule.disabled = false;
				} );
			} );
		}

		deploy.addEventListener( 'click', function () {
			if ( ! window.confirm( cfg.i18n.confirmDeploy ) ) {
				return;
			}
			deploy.disabled = true;
			showMessage( '', 'info' );
			post( 'shift8_woodpecker_deploy' ).then( function ( result ) {
				handle( result, function ( data ) {
					showMessage( cfg.i18n.started, 'success' );
					apply( data );
				} );
				if ( ! result || ! result.success ) {
					deploy.disabled = false;
				}
			} ).catch( function () {
				showMessage( cfg.i18n.requestFailed, 'error' );
				deploy.disabled = false;
			} );
		} );

		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				if ( ! window.confirm( cfg.i18n.confirmCancel ) ) {
					return;
				}
				cancel.disabled = true;
				post( 'shift8_woodpecker_cancel' ).then( function ( result ) {
					handle( result, apply );
				} ).catch( function () {
					showMessage( cfg.i18n.requestFailed, 'error' );
				} ).then( function () {
					cancel.disabled = false;
				} );
			} );
		}
	}

	function initSettings() {
		var button = el( 's8w-test' );
		var out = el( 's8w-test-result' );
		if ( ! button || ! out ) {
			return;
		}
		button.addEventListener( 'click', function () {
			button.disabled = true;
			out.className = '';
			out.textContent = cfg.i18n.testing;
			post( 'shift8_woodpecker_test' ).then( function ( result ) {
				out.textContent = '';
				var ok = result && result.success;
				out.className = 'notice inline notice-' + ( ok ? 'success' : 'error' );
				var lines = ok ? result.data.messages : [ result && result.data && result.data.message ? result.data.message : cfg.i18n.requestFailed ];
				lines.forEach( function ( line ) {
					var p = document.createElement( 'p' );
					p.textContent = line;
					out.appendChild( p );
				} );
			} ).catch( function () {
				out.className = 'notice inline notice-error';
				out.textContent = cfg.i18n.requestFailed;
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	}

	initDeployment();
	initSettings();
}() );
