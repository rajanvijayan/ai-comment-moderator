/* global AICM */
( function () {
	'use strict';

	if ( typeof AICM === 'undefined' ) {
		return;
	}

	function post( action, data ) {
		const body = new URLSearchParams( Object.assign( { action: action, nonce: AICM.nonce }, data || {} ) );
		return fetch( AICM.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || AICM.i18n.error );
				}
				return json.data;
			} );
	}

	function esc( s ) {
		const d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	/* ---------------- Settings: tabs ---------------- */
	const tabs = document.querySelectorAll( '.aicm-tabs .nav-tab' );
	if ( tabs.length ) {
		const show = function ( hash ) {
			const target = document.querySelector( hash ) ? hash : '#provider';
			tabs.forEach( function ( t ) {
				t.classList.toggle( 'nav-tab-active', t.getAttribute( 'href' ) === target );
			} );
			document.querySelectorAll( '.aicm-panel' ).forEach( function ( p ) {
				p.classList.toggle( 'is-active', '#' + p.id === target );
			} );
			const submit = document.querySelector( '.aicm-submit' );
			if ( submit ) {
				submit.style.display = target === '#playground' ? 'none' : '';
			}
		};
		tabs.forEach( function ( t ) {
			t.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				history.replaceState( null, '', t.getAttribute( 'href' ) );
				show( t.getAttribute( 'href' ) );
			} );
		} );
		show( location.hash || '#provider' );

		// Highlight the active provider block.
		const providerSelect = document.querySelector( 'select[name="aicm_settings[provider]"]' );
		const markProvider = function () {
			document.querySelectorAll( '.aicm-provider' ).forEach( function ( b ) {
				b.classList.toggle( 'is-primary', providerSelect && b.dataset.provider === providerSelect.value );
			} );
		};
		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', markProvider );
			markProvider();
		}
	}

	/* ---------------- Settings: range outputs ---------------- */
	document.querySelectorAll( '.aicm-range' ).forEach( function ( r ) {
		const out = r.nextElementSibling;
		r.addEventListener( 'input', function () {
			if ( out ) {
				out.textContent = Number( r.value ).toFixed( 2 );
			}
		} );
	} );

	/* ---------------- Settings: test connection ---------------- */
	document.querySelectorAll( '.aicm-test' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const block = btn.closest( '.aicm-provider' );
			const result = block.querySelector( '.aicm-test-result' );
			const key = block.querySelector( '.aicm-key' );
			const model = block.querySelector( '.aicm-model' );
			result.className = 'aicm-test-result';
			result.textContent = AICM.i18n.testing;
			btn.disabled = true;
			post( 'aicm_test_provider', {
				provider: btn.dataset.provider,
				key: key ? key.value : '',
				model: model ? model.value : '',
			} )
				.then( function ( d ) {
					result.classList.add( 'is-ok' );
					result.textContent = d.message;
				} )
				.catch( function ( e ) {
					result.classList.add( 'is-err' );
					result.textContent = e.message;
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );

	/* ---------------- Settings: playground ---------------- */
	const pgRun = document.getElementById( 'aicm-pg-run' );
	if ( pgRun ) {
		pgRun.addEventListener( 'click', function () {
			const out = document.getElementById( 'aicm-pg-out' );
			const spinner = pgRun.nextElementSibling;
			spinner.classList.add( 'is-active' );
			pgRun.disabled = true;
			out.innerHTML = '';
			post( 'aicm_playground', {
				text: document.getElementById( 'aicm-pg-text' ).value,
				post: document.getElementById( 'aicm-pg-post' ).value,
			} )
				.then( function ( v ) {
					const pct = function ( n ) {
						return Math.round( ( n || 0 ) * 100 ) + '%';
					};
					out.innerHTML =
						'<div class="aicm-pg-head"><span class="aicm-badge is-' + esc( v.action ) + '">' + esc( v.action ) + '</span> ' +
						'<span>' + esc( v.provider ) + ' / ' + esc( v.model ) + ' · ' + esc( v.latency_ms ) + ' ms · ' + esc( ( v.tokens_in || 0 ) + ( v.tokens_out || 0 ) ) + ' tokens</span></div>' +
						'<table class="widefat striped"><tbody>' +
						'<tr><th>Spam</th><td>' + pct( v.spam_score ) + '</td></tr>' +
						'<tr><th>Toxicity</th><td>' + pct( v.toxicity_score ) + '</td></tr>' +
						'<tr><th>Flags</th><td>' + esc( ( v.categories || [] ).join( ', ' ) || '—' ) + '</td></tr>' +
						'<tr><th>Sentiment</th><td>' + esc( v.sentiment ) + ( v.language ? ' · ' + esc( v.language.toUpperCase() ) : '' ) + '</td></tr>' +
						'<tr><th>Reason</th><td>' + esc( v.reason || v.error ) + '</td></tr>' +
						( v.reply ? '<tr><th>Suggested reply</th><td>' + esc( v.reply ) + '</td></tr>' : '' ) +
						'</tbody></table>';
				} )
				.catch( function ( e ) {
					out.innerHTML = '<div class="notice notice-error inline"><p>' + esc( e.message ) + '</p></div>';
				} )
				.finally( function () {
					spinner.classList.remove( 'is-active' );
					pgRun.disabled = false;
				} );
		} );
	}

	/* ---------------- Comments screen ---------------- */
	function cellFor( el ) {
		const row = el.closest( 'tr' );
		return row ? row.querySelector( '.aicm-cell' ) : null;
	}

	function commentId( el ) {
		const cell = cellFor( el );
		if ( cell ) {
			return cell.dataset.comment;
		}
		const row = el.closest( 'tr[id^="comment-"]' );
		return row ? row.id.replace( 'comment-', '' ) : null;
	}

	function run( el, action, extra ) {
		const cell = cellFor( el );
		const id = commentId( el );
		if ( ! id ) {
			return;
		}
		if ( cell ) {
			cell.classList.add( 'is-busy' );
		}
		post( action, Object.assign( { comment_id: id }, extra || {} ) )
			.then( function ( d ) {
				if ( cell && d.html !== undefined ) {
					cell.innerHTML = d.html;
				}
				if ( d.status ) {
					// Reflect status changes (approve/hold/spam) without a reload.
					const row = el.closest( 'tr' );
					if ( row && ( d.status === 'spam' || d.status === 'trash' ) ) {
						row.style.opacity = '0.4';
					} else if ( row ) {
						row.classList.toggle( 'unapproved', d.status === 'unapproved' );
						row.classList.toggle( 'approved', d.status === 'approved' );
					}
				}
				if ( d.message ) {
					window.alert( d.message ); // eslint-disable-line no-alert
				}
			} )
			.catch( function ( e ) {
				window.alert( e.message ); // eslint-disable-line no-alert
			} )
			.finally( function () {
				if ( cell ) {
					cell.classList.remove( 'is-busy' );
				}
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		const t = e.target;
		if ( ! ( t instanceof Element ) ) {
			return;
		}

		if ( t.matches( '.aicm-recheck' ) ) {
			e.preventDefault();
			run( t, 'aicm_recheck' );
		} else if ( t.matches( '.aicm-reply-generate' ) ) {
			e.preventDefault();
			run( t, 'aicm_reply_generate' );
		} else if ( t.matches( '.aicm-reply-discard' ) ) {
			e.preventDefault();
			run( t, 'aicm_reply_discard' );
		} else if ( t.matches( '.aicm-reply-send' ) ) {
			e.preventDefault();
			// eslint-disable-next-line no-alert
			if ( window.confirm( AICM.i18n.confirm ) ) {
				run( t, 'aicm_reply_send' );
			}
		} else if ( t.matches( '.aicm-reply-edit' ) ) {
			e.preventDefault();
			const box = t.closest( '.aicm-draft' );
			const text = box.querySelector( '.aicm-draft-text' );
			const actions = box.querySelector( '.aicm-draft-actions' );
			const ta = document.createElement( 'textarea' );
			ta.className = 'aicm-draft-editor';
			ta.rows = 4;
			ta.value = text.textContent;
			text.replaceWith( ta );
			actions.innerHTML =
				'<button type="button" class="button button-small button-primary aicm-reply-save">' + esc( AICM.i18n.save ) + '</button> ' +
				'<button type="button" class="button-link aicm-reply-cancel">' + esc( AICM.i18n.cancel ) + '</button>';
			ta.focus();
		} else if ( t.matches( '.aicm-reply-save' ) ) {
			e.preventDefault();
			const ta = t.closest( '.aicm-draft' ).querySelector( '.aicm-draft-editor' );
			run( t, 'aicm_reply_send', { text: ta.value } );
		} else if ( t.matches( '.aicm-reply-cancel' ) ) {
			e.preventDefault();
			location.reload();
		}
	} );

	/* ---------------- Dashboard: batch scan ---------------- */
	document.querySelectorAll( '.aicm-scan' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const box = document.querySelector( '.aicm-scan-progress' );
			const buttons = document.querySelectorAll( '.aicm-scan' );
			let total = 0;
			let rounds = 0;
			const counts = {};
			buttons.forEach( function ( b ) {
				b.disabled = true;
			} );

			const finish = function ( msg ) {
				box.textContent = msg;
				buttons.forEach( function ( b ) {
					b.disabled = false;
				} );
			};

			const step = function () {
				box.textContent = AICM.i18n.working + ' ' + total;
				post( 'aicm_scan', { status: btn.dataset.status } )
					.then( function ( d ) {
						total += d.processed;
						Object.keys( d.actions || {} ).forEach( function ( k ) {
							counts[ k ] = ( counts[ k ] || 0 ) + d.actions[ k ];
						} );
						rounds++;
						if ( d.processed > 0 && rounds < 100 ) {
							step();
						} else {
							const detail = Object.keys( counts ).map( function ( k ) {
								return k + ': ' + counts[ k ];
							} ).join( ', ' );
							finish( AICM.i18n.scanDone.replace( '%d', total ) + ( detail ? ' (' + detail + ')' : '' ) );
						}
					} )
					.catch( function ( e ) {
						finish( e.message );
					} );
			};
			step();
		} );
	} );
}() );
