/**
 * Simple Comment Ratings — front-end JS.
 *
 * Turnstile:
 *   The widget is auto-rendered by Cloudflare's script (via .cf-turnstile class
 *   in the footer). When the challenge completes, `scrOnTurnstileCallback` is
 *   called with the token. If the user clicks a vote before the token arrives
 *   the request is queued and fires as soon as it does. After each vote the
 *   widget is reset so a fresh token is available for subsequent votes.
 *
 * No jQuery. No build step. IE11 is not supported.
 */

/* global scrData, turnstile */

( function () {
	'use strict';

	// ── Turnstile state ──────────────────────────────────────────────────────

	/** @type {string|null} */
	let token    = null;
	/** @type {string|null} widget ID returned by turnstile.render() */
	let widgetId = null;
	/** @type {Array<function(string|null): void>} callbacks queued while token is pending */
	const pending = [];

	/**
	 * Called by the Turnstile script once it has finished loading.
	 * render=explicit in the script URL disables the Intersection Observer so
	 * the challenge starts here immediately, before the widget is in view.
	 */
	window.scrOnTurnstileLoad = function () {
		const container = document.getElementById( 'scr-turnstile-container' );
		if ( ! container || ! scrData.sitekey ) return;

		widgetId = turnstile.render( container, {
			sitekey:            scrData.sitekey,
			execution:          'render',            // challenge starts immediately
			appearance:         'interaction-only',  // UI only appears if needed
			callback: function ( t ) {
				token = t;
				while ( pending.length ) pending.shift()( token );
			},
			'expired-callback': function () {
				token = null; // widget auto-refreshes and will call callback again
			},
			'error-callback': function () {
				token = null;
				while ( pending.length ) pending.shift()( null );
			},
		} );
	};

	/**
	 * Obtain the current Turnstile token asynchronously.
	 *
	 * If the token is already available `cb` is called synchronously.
	 * If not yet ready `cb` is queued and called once it arrives.
	 * If Turnstile is not configured `cb` is called immediately with null.
	 *
	 * @param {function(string|null): void} cb
	 */
	function getToken( cb ) {
		if ( ! scrData.sitekey ) {
			cb( null );
			return;
		}
		if ( token ) {
			cb( token );
			return;
		}
		pending.push( cb );
	}

	/** Consume the current token and reset the widget for the next vote. */
	function consumeToken() {
		token = null;
		if ( widgetId !== null && typeof turnstile !== 'undefined' ) {
			turnstile.reset( widgetId );
		}
	}

	// ── Vote logic ───────────────────────────────────────────────────────────

	/** @param {MouseEvent} event */
	function onVoteClick( event ) {
		const btn       = /** @type {HTMLButtonElement} */ ( event.currentTarget );
		const container = btn.closest( '.scr-vote-container' );
		if ( ! container ) return;

		const commentId = container.dataset.commentId;
		const vote      = btn.dataset.vote; // 'up' | 'down'

		// Trigger click pop animation on the clicked button.
		btn.classList.add( 'scr-anim-pop' );
		btn.addEventListener( 'animationend', function handler() {
			btn.classList.remove( 'scr-anim-pop' );
			btn.removeEventListener( 'animationend', handler );
		} );

		// Optimistically disable both buttons to prevent double-submission.
		setDisabled( container, true );

		getToken( function ( t ) {
			const body = new FormData();
			body.append( 'action',      'scr_vote' );
			body.append( 'nonce',       scrData.nonce );
			body.append( 'comment_id',  commentId );
			body.append( 'vote',        vote );
			if ( t ) body.append( 'turnstile_token', t );

			fetch( scrData.ajaxUrl, {
				method:      'POST',
				body:        body,
				credentials: 'same-origin',
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data.success ) {
						applyResult( container, data.data );
						consumeToken();
					} else if ( data.data && data.data.code === 'already_voted' ) {
						// Race condition — another tab voted first.
						applyResult( container, {
							up:        null,
							down:      null,
							sum:       null,
							user_vote: data.data.user_vote,
						} );
					} else {
						// Unexpected error — re-enable so the user can retry.
						setDisabled( container, false );
					}
				} )
				.catch( function () {
					setDisabled( container, false );
				} );
		} );
	}

	/**
	 * Update the widget DOM to reflect a completed vote.
	 *
	 * @param {Element} container
	 * @param {{ up: number|null, down: number|null, sum: number|null, user_vote: number }} result
	 */
	function applyResult( container, result ) {
		// Voted-state classes.
		container.classList.remove( 'scr-voted-up', 'scr-voted-down' );

		if ( result.user_vote === 1 ) {
			container.classList.add( 'scr-voted-up' );
			setActive( container, 'up' );
		} else if ( result.user_vote === -1 ) {
			container.classList.add( 'scr-voted-down' );
			setActive( container, 'down' );
		}

		// Update count labels if the server returned them.
		if ( result.up !== null ) {
			setText( container, '.scr-count-up', result.up );
		}
		if ( result.down !== null ) {
			setText( container, '.scr-count-down', result.down );
		}
		if ( result.sum !== null ) {
			setText( container, '.scr-count-sum', result.sum );
		}

		// Keep buttons permanently disabled — no take-back.
		setDisabled( container, true );
	}

	function setActive( container, direction ) {
		container.querySelectorAll( '.scr-vote-btn' ).forEach( function ( btn ) {
			btn.classList.toggle( 'scr-active', btn.dataset.vote === direction );
		} );
	}

	function setDisabled( container, disabled ) {
		container.querySelectorAll( '.scr-vote-btn' ).forEach( function ( btn ) {
			btn.disabled = disabled;
		} );
	}

	function setText( container, selector, value ) {
		const el = container.querySelector( selector );
		if ( el ) el.textContent = value;
	}

	// ── AJAX count loading ────────────────────────────────────────────────────

	/**
	 * Fetch counts for all comments on the post in one request.
	 * Used when counts_loading === 'ajax'.
	 */
	function loadCountsViaAjax() {
		const containers = document.querySelectorAll( '.scr-vote-container.scr-ajax-counts' );
		if ( ! containers.length ) return;

		const body = new FormData();
		body.append( 'action',  'scr_get_counts' );
		body.append( 'nonce',   scrData.nonce );
		body.append( 'post_id', scrData.postId );

		fetch( scrData.ajaxUrl, {
			method:      'POST',
			body:        body,
			credentials: 'same-origin',
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.success ) return;

				containers.forEach( function ( container ) {
					const id   = container.dataset.commentId;
					const info = data.data[ id ];
					if ( ! info ) return;

					applyResult( container, info );

					// If user hasn't voted, re-enable the buttons
					// (they start disabled while loading).
					if ( info.user_vote === 0 ) {
						setDisabled( container, false );
					}
				} );
			} )
			.catch( function () { /* silently ignore — counts simply won't show */ } );
	}

	// ── Initialisation ────────────────────────────────────────────────────────

	function init() {
		// Attach click handlers to all vote buttons.
		document.querySelectorAll( '.scr-vote-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', onVoteClick );
		} );

		// In AJAX mode, disable buttons until counts arrive, then re-enable
		// for comments the user hasn't voted on.
		if ( scrData.countsLoading === 'ajax' ) {
			// Pre-disable while loading.
			document.querySelectorAll( '.scr-ajax-counts .scr-vote-btn' ).forEach( function ( btn ) {
				btn.disabled = true;
			} );
			loadCountsViaAjax();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

}() );
