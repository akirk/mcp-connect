	( function () {
		var tabs = document.querySelectorAll( '.mcp-oauth-tab' );
		var key = 'mcp-oauth-tab';
		function show( id ) {
			tabs.forEach( function ( tab ) {
				var on = tab.dataset.target === id;
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				var panel = document.getElementById( tab.dataset.target );
				if ( panel ) { panel.hidden = ! on; }
			} );
			try { window.localStorage.setItem( key, id ); } catch ( e ) {}
		}
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () { show( tab.dataset.target ); } );
		} );
		if ( tabs.length ) {
			var saved = null;
			try { saved = window.localStorage.getItem( key ); } catch ( e ) {}
			show( saved && document.getElementById( saved ) ? saved : tabs[0].dataset.target );
		}
		function filterAbilities() {
			var search = document.getElementById( 'mcp-oauth-ability-search' );
			var filter = document.getElementById( 'mcp-oauth-ability-filter' );
			if ( ! search || ! filter ) { return; }
			var query = search.value.trim().toLowerCase();
			var matches = 0;
			document.querySelectorAll( '.mcp-oauth-ability' ).forEach( function ( row ) {
				var hidden = row.classList.contains( 'is-hidden' );
				row.hidden = row.dataset.search.indexOf( query ) === -1 || ( filter.value === 'available' && hidden ) || ( filter.value === 'hidden' && ! hidden );
				if ( ! row.hidden ) { matches++; }
			} );
			document.getElementById( 'mcp-oauth-no-abilities' ).hidden = matches > 0;
		}
		[ 'mcp-oauth-ability-search', 'mcp-oauth-ability-filter' ].forEach( function ( id ) {
			var control = document.getElementById( id );
			if ( control ) { control.addEventListener( 'input', filterAbilities ); }
		} );
		document.querySelectorAll( '.mcp-oauth-eye' ).forEach( function ( eye ) {
			eye.addEventListener( 'click', function () {
				var hide = eye.dataset.visible === '1';
				eye.disabled = true;
				window.fetch( mcpConnectAdmin.visibilityUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': mcpConnectAdmin.nonce },
					body: JSON.stringify( { ability: eye.dataset.ability, hide: hide } )
				} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } ).then( function ( data ) {
					var row = eye.closest( 'tr' );
					eye.dataset.visible = data.visible ? '1' : '0';
					eye.setAttribute( 'aria-pressed', data.visible ? 'true' : 'false' );
					eye.title = data.visible ? mcpConnectAdmin.visibleTitle : mcpConnectAdmin.hiddenTitle;
					eye.innerHTML = data.visible ? '<span class="dashicons dashicons-visibility" style="color:#00a32a"></span>' : '<span class="dashicons dashicons-hidden"></span>';
					row.classList.toggle( 'is-hidden', ! data.visible );
					filterAbilities();
					var reason = row.querySelector( '.mcp-oauth-reason' );
					reason.textContent = data.reason;
					reason.classList.toggle( 'is-override', !! data.override );
					var count = document.getElementById( 'mcp-oauth-visible-count' );
					if ( count ) { count.textContent = data.count; }
					// A hidden ability is unreachable, so it cannot be a tool of its own either.
					var tool = row.querySelector( '.mcp-oauth-tool' );
					if ( tool ) {
						tool.disabled = ! data.visible;
						tool.title = data.visible ? mcpConnectAdmin.toolTitle : mcpConnectAdmin.hiddenToolTitle;
					}
				} ).catch( function () {
					window.alert( mcpConnectAdmin.visibilityError );
				} ).then( function () { eye.disabled = false; } );
			} );
		} );
		document.querySelectorAll( '.mcp-oauth-tool' ).forEach( function ( tool ) {
			tool.addEventListener( 'change', function () {
				var expose = tool.checked;
				tool.disabled = true;
				window.fetch( mcpConnectAdmin.toolUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': mcpConnectAdmin.nonce },
					body: JSON.stringify( { ability: tool.dataset.ability, expose: expose } )
				} ).then( function ( r ) { return r.ok ? r.json() : Promise.reject( r ); } ).then( function ( data ) {
					tool.checked = data.expose;
					var count = document.getElementById( 'mcp-oauth-direct-count' );
					if ( count ) { count.textContent = data.direct; }
				} ).catch( function () {
					tool.checked = ! expose;
					window.alert( mcpConnectAdmin.toolError );
				} ).then( function () { tool.disabled = false; } );
			} );
		} );

		document.querySelectorAll( '.mcp-oauth-copy' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var label = button.textContent;
				navigator.clipboard.writeText( button.dataset.copy ).then( function () {
					button.textContent = mcpConnectAdmin.copied;
					setTimeout( function () { button.textContent = label; }, 1500 );
				} );
			} );
		} );
	} )();
