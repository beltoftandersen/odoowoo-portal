/*
 * OdooWoo portal: the mapping editor, one editor for the six mapping cards. Plain script. In the
 * browser it sets window.odoowoo.mappingEditor( root, config ); under Node (tests) it sets
 * module.exports. The model (rows, choices, checks, body) has no DOM and is what the tests cover;
 * the glue at the end draws it. Every text from Django is written with textContent.
 *
 * The config is printed by PHP (src/Cards/Mappings.php): route, columns, loaders, saved, unique.
 * Django does the real validation and its refusal is shown as it is: `required` and `unique`
 * here only spare a round trip. A list is saved whole (Django replaces the stored rows).
 */
( function () {
	'use strict';

	var G = typeof window !== 'undefined' ? window : globalThis;
	var core = typeof module !== 'undefined' && module.exports ? require( './core.js' ) : G.odoowoo;

	var EVENT = 'odoowoo:mappings-saved';

	var t = core.translator( {
		addRow: 'Add row',
		removeRow: 'Remove',
		removeRowN: 'Remove row %s',
		save: 'Save',
		noRows: 'No rows yet.',
		rowRepeats: 'Row %1$s repeats row %2$s.',
		rowError: 'Row %1$s: %2$s',
		rowLabel: '%1$s, row %2$s',
		choose: 'Choose…',
		loading: 'Loading…',
		loadedOk: 'Loaded.',
		listLoading: 'Loading the saved rows…',
		fixErrors: 'Fix the marked rows, then save again.',
	} );

	/** Fills %s, %1$s and %2$s. */
	function fmt( template, a, b ) {
		return String( template ).replace( /%(\d\$)?s/g, function ( m, n ) {
			return String( n === '2$' ? b : a );
		} );
	}

	function isObject( v ) {
		return v !== null && typeof v === 'object' && ! Array.isArray( v );
	}

	function isId( v ) {
		return typeof v === 'number' && Number.isInteger( v ) && v > 0;
	}

	function text( v ) {
		return typeof v === 'string' ? v : '';
	}

	function rowLabel( label, index ) {
		return fmt( t( 'rowLabel' ), label, index + 1 );
	}

	function createModel( config ) {
		var columns = Array.isArray( config.columns ) ? config.columns : [];
		var loaders = Array.isArray( config.loaders ) ? config.loaders : [];
		var unique = Array.isArray( config.unique ) ? config.unique : [];
		var pools = {};
		var rows = [];
		var loaded = false;

		function column( key ) {
			return columns.filter( function ( c ) { return c.key === key; } )[ 0 ];
		}

		function blank( col ) {
			if ( col.default !== undefined ) {
				return col.default;
			}

			if ( col.type === 'checkbox' ) {
				return false;
			}

			return col.type === 'select' && col.numeric === true ? null : '';
		}

		/** A stored value read into the column's own type; what cannot be read becomes blank. */
		function read( col, v ) {
			if ( col.type === 'checkbox' ) {
				return v === true;
			}

			if ( col.type === 'select' && col.numeric === true ) {
				return isId( v ) ? v : null;
			}

			if ( col.type === 'number' ) {
				return typeof v === 'number' ? v : null;
			}

			return typeof v === 'string' ? v : ( col.default !== undefined ? col.default : '' );
		}

		function newRow() {
			var row = {};

			columns.forEach( function ( col ) {
				row[ col.key ] = blank( col );

				if ( col.nameField ) {
					row[ col.nameField ] = '';
				}
			} );

			return row;
		}

		function shown( index, key ) {
			var col = column( key );
			var row = rows[ index ];

			if ( ! col || ! row ) {
				return false;
			}

			return ! col.showWhen || col.showWhen.in.indexOf( String( row[ col.showWhen.key ] ) ) !== -1;
		}

		function present( col, v ) {
			if ( col.type === 'select' && col.numeric === true ) {
				return isId( v );
			}

			if ( col.type === 'number' ) {
				return typeof v === 'number' && isFinite( v );
			}

			return typeof v === 'string' && v.trim() !== '';
		}

		function pool( key ) {
			return pools[ key ] || ( pools[ key ] = { saved: [], loaded: [] } );
		}

		/** The column that reads this list (its keys say which field is the id and the name). */
		function columnOfPool( key ) {
			return columns.filter( function ( c ) { return c.source && c.source.route === key; } )[ 0 ];
		}

		/** Valid {id, name, raw} entries of a reply list, in the reply's order. */
		function normalise( list, idKey, nameKey, numeric ) {
			var out = [];
			var seen = {};

			( Array.isArray( list ) ? list : [] ).forEach( function ( item ) {
				if ( ! isObject( item ) ) {
					return;
				}

				var id = item[ idKey ];
				var name = item[ nameKey ];
				var ok = numeric ? isId( id ) : ( typeof id === 'string' && id !== '' );

				if ( ! ok || typeof name !== 'string' || seen[ String( id ) ] ) {
					return;
				}

				seen[ String( id ) ] = true;
				out.push( { id: id, name: name, raw: item } );
			} );

			return out;
		}

		function poolItems( key ) {
			var p = pools[ key ];

			if ( ! p ) {
				return [];
			}

			return p.loaded.length > 0 ? p.loaded : p.saved;
		}

		function passes( item, filter ) {
			return ! filter || Object.keys( filter ).every( function ( k ) {
				return item.raw[ k ] === filter[ k ];
			} );
		}

		function nameOf( col, row, value ) {
			var found = poolItems( col.source.route ).filter( function ( i ) { return i.id === value; } )[ 0 ];

			if ( found ) {
				return found.name;
			}

			var stored = col.nameField ? row[ col.nameField ] : '';

			return typeof stored === 'string' && stored !== '' ? stored : ( col.numeric === true ? '#' + String( value ) : String( value ) );
		}

		/** The choices of a select cell: the row's own value first when the list does not offer it. */
		function options( index, key ) {
			var col = column( key );
			var row = rows[ index ];

			if ( ! col || col.type !== 'select' ) {
				return [];
			}

			if ( Array.isArray( col.options ) ) {
				var fixed = col.options.map( function ( o ) { return { value: o.value, label: o.label }; } );

				if ( row && typeof row[ key ] === 'string' && row[ key ] !== '' && ! fixed.some( function ( o ) { return o.value === row[ key ]; } ) ) {
					fixed.unshift( { value: row[ key ], label: row[ key ] } );
				}

				return fixed;
			}

			var list = poolItems( col.source.route ).filter( function ( i ) { return passes( i, col.source.filter ); } ).map( function ( i ) {
				return { value: String( i.id ), label: i.name };
			} );
			var current = row ? row[ key ] : null;

			if ( row && present( col, current ) && ! list.some( function ( o ) { return o.value === String( current ); } ) ) {
				list.unshift( { value: String( current ), label: nameOf( col, row, current ) } );
			}

			return list;
		}

		/** What a row sends: hidden columns are cleared, text is stripped as Django strips it. */
		function clean( index ) {
			var row = rows[ index ];
			var out = {};

			columns.forEach( function ( col ) {
				var visible = shown( index, col.key );
				var v = row[ col.key ];

				if ( col.type === 'checkbox' ) {
					out[ col.key ] = visible && v === true;
				} else if ( col.type === 'select' && col.numeric === true ) {
					out[ col.key ] = visible && isId( v ) ? v : null;
				} else if ( col.type === 'number' ) {
					out[ col.key ] = visible && typeof v === 'number' ? v : null;
				} else {
					out[ col.key ] = visible ? ( col.type === 'text' ? text( v ).trim() : text( v ) ) : '';
				}

				if ( col.nameField ) {
					out[ col.nameField ] = visible && present( col, out[ col.key ] ) ? text( row[ col.nameField ] ) : '';
				}
			} );

			return out;
		}

		function validate() {
			var errors = [];
			var cleaned = rows.map( function ( r, i ) { return clean( i ); } );

			function add( index, key, message ) {
				errors.push( { row: index, key: key, message: message } );
			}

			rows.forEach( function ( row, i ) {
				columns.forEach( function ( col ) {
					if ( col.required === true && shown( i, col.key ) && ! present( col, row[ col.key ] ) ) {
						add( i, col.key, fmt( t( 'rowError' ), i + 1, text( col.missing ) ) );
					}
				} );
			} );

			unique.forEach( function ( group ) {
				var first = {};

				cleaned.forEach( function ( row, i ) {
					var values = group.map( function ( k ) { return String( row[ k ] === null ? '' : row[ k ] ); } );

					// A row that lacks its own key is already refused above, and is not a repeat of anything.
					if ( group.some( function ( k ) { var c = column( k ); return c && c.required === true && shown( i, k ) && ! present( c, rows[ i ][ k ] ); } ) ) {
						return;
					}

					var id = JSON.stringify( values );

					if ( first[ id ] === undefined ) {
						first[ id ] = i;
						return;
					}

					var shownKeys = group.filter( function ( k ) { return shown( i, k ); } );

					add( i, shownKeys[ shownKeys.length - 1 ] || group[ 0 ], fmt( t( 'rowRepeats' ), i + 1, first[ id ] + 1 ) );
				} );
			} );

			errors.sort( function ( a, b ) { return a.row - b.row; } );

			return errors;
		}

		var model = {
			config: config,
			rows: function () {
				return rows.map( function ( r ) { return Object.assign( {}, r ); } );
			},
			isLoaded: function () {
				return loaded;
			},
			/** The stored rows (Django's list, read as {items}); only the columns of the config are kept. */
			loadRows: function ( list ) {
				rows = [];

				( Array.isArray( list ) ? list : [] ).forEach( function ( item ) {
					if ( ! isObject( item ) ) {
						return;
					}

					var row = {};

					columns.forEach( function ( col ) {
						row[ col.key ] = read( col, item[ col.key ] );

						if ( col.nameField ) {
							row[ col.nameField ] = text( item[ col.nameField ] );
						}
					} );
					rows.push( row );
				} );
				loaded = true;
			},
			addRow: function () {
				rows.push( newRow() );

				return rows.length - 1;
			},
			removeRow: function ( index ) {
				if ( Number.isInteger( index ) && index >= 0 && index < rows.length ) {
					rows.splice( index, 1 );
				}
			},
			/** A value the user chose or typed. A select only takes what it offers. */
			setValue: function ( index, key, raw ) {
				var col = column( key );
				var row = rows[ index ];

				if ( ! col || ! row ) {
					return;
				}

				if ( col.type === 'checkbox' ) {
					row[ key ] = raw === true;
				} else if ( col.type === 'select' ) {
					if ( raw === '' || raw === null ) {
						row[ key ] = col.numeric === true ? null : '';

						if ( col.nameField ) {
							row[ col.nameField ] = '';
						}

						return;
					}

					var choice = options( index, key ).filter( function ( o ) { return o.value === String( raw ); } )[ 0 ];

					if ( ! choice ) {
						return;
					}

					row[ key ] = col.numeric === true ? Number( choice.value ) : choice.value;

					if ( col.nameField ) {
						row[ col.nameField ] = choice.label;
					}
				} else if ( col.type === 'number' ) {
					row[ key ] = raw === '' || raw === null ? null : Number( raw );
				} else {
					row[ key ] = text( raw );
				}
			},
			/** Sets a value as it is, without checking it (a stored value, or a test of a bad one). */
			setRaw: function ( index, key, value ) {
				if ( rows[ index ] && column( key ) ) {
					rows[ index ][ key ] = value;
				}
			},
			shown: shown,
			options: options,
			validate: validate,
			body: function () {
				return { items: rows.map( function ( r, i ) { return clean( i ); } ) };
			},
			/** The names the saved lists hold (shown before anything is loaded). */
			feedSaved: function ( entry, list ) {
				var key = entry.into || entry.route;
				var col = columnOfPool( key );

				pool( key ).saved = normalise( list, entry.idKey, entry.nameKey, !! ( col && col.numeric === true ) );
			},
			/**
			 * What a loader's reply means. A loaded list replaces the choices; an empty or odd one,
			 * a refusal or a failure leaves rows and choices as they were.
			 */
			applyLoader: function ( index, replyObj ) {
				var loader = loaders[ index ] || {};
				var key = loader.into || loader.route;
				var col = columnOfPool( key );

				if ( ! replyObj || replyObj.ok !== true ) {
					if ( core.notConfigured( replyObj ) ) {
						return { kind: 'not-configured', message: replyObj.message, reply: replyObj };
					}

					return { kind: 'failed', message: core.errorText( replyObj ), reply: replyObj };
				}

				var list = col ? normalise( core.listOf( replyObj.data ), col.source.idKey, col.source.nameKey, col.numeric === true ) : [];

				if ( list.length === 0 ) {
					return { kind: 'empty', message: text( loader.empty ) };
				}

				pool( key ).loaded = list;

				return { kind: 'loaded', message: t( 'loadedOk' ), count: list.length };
			},
		};

		return model;
	}

	/** Whether a loader button may be on: reads need a ready tenant, writing ones need the owner of an active plan. */
	function loaderEnabled( loader, perms ) {
		return perms.canRead === true && ( loader.writes !== true || perms.canWrite === true );
	}

	/** Save is on for a viewer who may write and only once the stored rows were read (else it would wipe them). */
	function canSave( model, perms ) {
		return perms.canWrite === true && model.isLoaded();
	}

	/** The column a Django refusal names, with its message; key '' for a general message. */
	function refusalTarget( config, replyObj ) {
		var field = replyObj && typeof replyObj.field === 'string' ? replyObj.field : '';
		var known = ( config.columns || [] ).some( function ( c ) { return c.key === field; } );

		return { key: known ? field : '', message: replyObj && typeof replyObj.message === 'string' ? replyObj.message : '' };
	}

	/**
	 * Reads the stored rows and the saved names with plain reads (never a loader). Only the list
	 * decides success: without it the editor stays unloaded and Save stays off.
	 */
	async function readAll( ctx, model ) {
		var saved = model.config.saved || [];
		var replies = await Promise.all( [ core.api( 'GET', core.dashboardPath( ctx, model.config.route ) ) ].concat( saved.map( function ( s ) {
			return core.api( 'GET', core.dashboardPath( ctx, s.route ) );
		} ) ) );

		saved.forEach( function ( s, i ) {
			if ( replies[ i + 1 ].ok === true ) {
				model.feedSaved( s, core.listOf( replies[ i + 1 ].data ) );
			}
		} );

		if ( replies[ 0 ].ok !== true ) {
			return { ok: false, reply: replies[ 0 ] };
		}

		model.loadRows( core.listOf( replies[ 0 ].data ) );

		return { ok: true, reply: replies[ 0 ] };
	}

	/**
	 * Saves the whole list. Refused here (nothing sent) before the rows were read or when a row
	 * is incomplete; otherwise one PUT, and `notify( route )` after a success only.
	 */
	async function saveList( ctx, model, notify ) {
		if ( ! model.isLoaded() ) {
			return { ok: false, reply: core.notLoadedReply() };
		}

		var errors = model.validate();

		if ( errors.length > 0 ) {
			return { ok: false, errors: errors };
		}

		var replyObj = await core.api( 'PUT', core.dashboardPath( ctx, model.config.route ), model.body() );

		if ( replyObj.ok === true && typeof notify === 'function' ) {
			notify( model.config.route );
		}

		return { ok: replyObj.ok === true, reply: replyObj };
	}

	// ---- DOM glue -----------------------------------------------------------------------------

	function el( tag, attrs, children ) {
		var node = G.document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( attrs[ k ] === true ) {
				node.setAttribute( k, '' );
			} else if ( attrs[ k ] !== false && attrs[ k ] !== undefined && attrs[ k ] !== null ) {
				node.setAttribute( k, String( attrs[ k ] ) );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			node.appendChild( typeof c === 'string' ? G.document.createTextNode( c ) : c );
		} );

		return node;
	}

	function mappingEditor( root, config ) {
		var card = root.closest ? root.closest( '[data-odoowoo-card]' ) : null;
		var holder = card || root;
		var ctx = { licenseId: Number( holder.getAttribute( 'data-license' ) ) || 0, dashboardId: Number( holder.getAttribute( 'data-dashboard' ) ) || 0 };
		var perms = { canWrite: holder.getAttribute( 'data-can-write' ) === '1', canRead: holder.getAttribute( 'data-can-read' ) === '1' };
		var model = createModel( config );
		var form = root.querySelector( 'form' );
		var rowsEl = root.querySelector( '.odoowoo-mapping__rows' );
		var addBtn = root.querySelector( '[data-action="add-row"]' );
		var saveBtn = root.querySelector( '[type="submit"]' );
		var statusEl = root.querySelector( '.odoowoo-form-status' );
		var loaderBtns = Array.prototype.slice.call( root.querySelectorAll( '[data-loader]' ) );
		var busyLoaders = {};
		var marks = [];
		var uid = 'odoowoo-map-' + Math.random().toString( 36 ).slice( 2, 8 );

		function setMarks( errors ) {
			marks = errors;
		}

		function buttons() {
			saveBtn.disabled = ! canSave( model, perms );
			addBtn.disabled = ! perms.canWrite;
			loaderBtns.forEach( function ( b ) {
				var i = Number( b.getAttribute( 'data-loader' ) );

				b.disabled = ! loaderEnabled( config.loaders[ i ], perms ) || busyLoaders[ i ] === true;
			} );
		}

		function cell( col, i, row, visibleColumn ) {
			var id = uid + '-' + i + '-' + col.key;
			var label = rowLabel( col.label, i );
			var input;

			if ( col.type === 'select' ) {
				input = el( 'select', { id: id, 'aria-label': label } );
				input.appendChild( el( 'option', { value: '' }, [ t( 'choose' ) ] ) );
				model.options( i, col.key ).forEach( function ( o ) {
					input.appendChild( el( 'option', { value: o.value }, [ o.label ] ) );
				} );
				input.value = row[ col.key ] === null || row[ col.key ] === undefined ? '' : String( row[ col.key ] );
			} else if ( col.type === 'checkbox' ) {
				input = el( 'input', { id: id, type: 'checkbox', 'aria-label': label } );
				input.checked = row[ col.key ] === true;
			} else {
				input = el( 'input', { id: id, type: col.type === 'number' ? 'number' : 'text', 'aria-label': label, maxlength: col.maxLength, autocomplete: 'off', spellcheck: 'false' } );
				input.value = row[ col.key ] === null || row[ col.key ] === undefined ? '' : String( row[ col.key ] );
			}

			input.setAttribute( 'data-row', String( i ) );
			input.setAttribute( 'data-key', col.key );
			input.disabled = ! perms.canWrite;

			if ( marks.some( function ( m ) { return m.key === col.key && ( m.row === i || m.row === -1 ); } ) ) {
				input.setAttribute( 'aria-invalid', 'true' );
			}

			var td = el( 'td', { 'data-column': col.key }, [ input ] );

			// A column is dropped only when no row shows it; in a row that does not, the cell is empty so the columns stay aligned.
			td.hidden = ! visibleColumn;
			input.hidden = ! model.shown( i, col.key );

			return td;
		}

		function render() {
			var active = G.document.activeElement;
			var focus = active && active.getAttribute && rowsEl.contains( active ) ? [ active.getAttribute( 'data-row' ), active.getAttribute( 'data-key' ) ] : null;
			var rows = model.rows();

			rowsEl.textContent = '';

			if ( rows.length === 0 ) {
				rowsEl.appendChild( el( 'p', { class: 'odoowoo-mapping__empty' }, [ model.isLoaded() ? t( 'noRows' ) : '' ] ) );
			} else {
				var head = el( 'tr' );

				var visible = {};

				config.columns.forEach( function ( col ) {
					visible[ col.key ] = rows.some( function ( r, i ) { return model.shown( i, col.key ); } );

					var th = el( 'th', { scope: 'col', 'data-column': col.key }, [ col.label ] );

					th.hidden = ! visible[ col.key ];

					if ( col.hint ) {
						th.appendChild( el( 'span', { class: 'odoowoo-hint' }, [ col.hint ] ) );
					}

					head.appendChild( th );
				} );
				head.appendChild( el( 'td', {}, [ el( 'span', { class: 'odoowoo-sr' }, [ t( 'removeRow' ) ] ) ] ) );

				var body = el( 'tbody' );

				rows.forEach( function ( row, i ) {
					var tr = el( 'tr' );

					config.columns.forEach( function ( col ) {
						tr.appendChild( cell( col, i, row, visible[ col.key ] ) );
					} );

					var remove = el( 'button', { type: 'button', class: 'odoowoo-button odoowoo-button--secondary', 'data-action': 'remove-row', 'data-row': i, 'aria-label': fmt( t( 'removeRowN' ), i + 1 ) }, [ t( 'removeRow' ) ] );

					remove.disabled = ! perms.canWrite;
					tr.appendChild( el( 'td', {}, [ remove ] ) );
					body.appendChild( tr );
				} );
				rowsEl.appendChild( el( 'table', { class: 'odoowoo-mapping__table' }, [ el( 'thead', {}, [ head ] ), body ] ) );
			}

			var list = marks.filter( function ( m ) { return m.message; } );

			if ( list.length > 0 ) {
				var ul = el( 'ul', { class: 'odoowoo-field-error', role: 'alert' } );

				list.forEach( function ( m ) {
					ul.appendChild( el( 'li', {}, [ m.message ] ) );
				} );
				rowsEl.appendChild( ul );
			}

			if ( focus ) {
				var again = rowsEl.querySelector( '[data-row="' + focus[ 0 ] + '"][data-key="' + focus[ 1 ] + '"]' );

				if ( again ) {
					again.focus();
				}
			}

			buttons();
		}

		function changed( input ) {
			var i = Number( input.getAttribute( 'data-row' ) );
			var key = input.getAttribute( 'data-key' );
			var col = config.columns.filter( function ( c ) { return c.key === key; } )[ 0 ];

			model.setValue( i, key, col.type === 'checkbox' ? input.checked : input.value );
			setMarks( [] );

			if ( col.type === 'text' || col.type === 'number' ) {
				Array.prototype.forEach.call( rowsEl.querySelectorAll( '[aria-invalid]' ), function ( n ) { n.removeAttribute( 'aria-invalid' ); } );
				Array.prototype.forEach.call( rowsEl.querySelectorAll( '[role="alert"]' ), function ( n ) { n.remove(); } );
				return;
			}

			render();
		}

		rowsEl.addEventListener( 'input', function ( e ) {
			if ( e.target && e.target.getAttribute && e.target.getAttribute( 'data-key' ) && e.target.type === 'text' ) {
				changed( e.target );
			}
		} );
		rowsEl.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.getAttribute && e.target.getAttribute( 'data-key' ) && e.target.type !== 'text' ) {
				changed( e.target );
			}
		} );
		rowsEl.addEventListener( 'click', function ( e ) {
			var b = e.target && e.target.closest ? e.target.closest( '[data-action="remove-row"]' ) : null;

			if ( b && ! b.disabled ) {
				model.removeRow( Number( b.getAttribute( 'data-row' ) ) );
				setMarks( [] );
				render();
				( addBtn.disabled ? saveBtn : addBtn ).focus();
			}
		} );
		addBtn.addEventListener( 'click', function () {
			var i = model.addRow();

			setMarks( [] );
			render();

			var first = rowsEl.querySelector( '[data-row="' + i + '"]' );

			if ( first ) {
				first.focus();
			}
		} );

		loaderBtns.forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var i = Number( b.getAttribute( 'data-loader' ) );
				var loader = config.loaders[ i ];
				var label = b.textContent;

				busyLoaders[ i ] = true;
				b.setAttribute( 'aria-busy', 'true' );
				b.textContent = t( 'loading' );
				buttons();
				core.status( statusEl, 'saving', t( 'loading' ) );
				core.api( loader.method, core.dashboardPath( ctx, loader.route ) ).then( function ( replyObj ) {
					var out = model.applyLoader( i, replyObj );

					busyLoaders[ i ] = false;
					b.removeAttribute( 'aria-busy' );
					b.textContent = label;

					if ( out.kind === 'loaded' ) {
						core.status( statusEl, 'saved', out.message );
					} else if ( out.kind === 'not-configured' ) {
						core.status( statusEl, 'error', '' );
						core.showNotConfigured( statusEl, replyObj, holder );
					} else {
						core.status( statusEl, 'error', out.message );
					}

					render();
				} );
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var last = null;

			core.submit( saveBtn, statusEl, async function () {
				last = await saveList( ctx, model, function () {
					root.dispatchEvent( new G.CustomEvent( EVENT, { bubbles: true, detail: { route: config.route } } ) );
				} );

				if ( last.errors ) {
					return { ok: false, code: 'invalid_value', message: t( 'fixErrors' ), status: 0 };
				}

				return last.reply;
			} ).then( function ( replyObj ) {
				if ( last && last.errors ) {
					setMarks( last.errors.map( function ( x ) { return { row: x.row, key: x.key, message: x.message }; } ) );
				} else if ( replyObj.ok !== true ) {
					var target = refusalTarget( config, replyObj );

					setMarks( [ { row: -1, key: target.key, message: '' } ] );

					if ( core.notConfigured( replyObj ) ) {
						core.status( statusEl, 'error', '' );
						core.showNotConfigured( statusEl, replyObj, holder );
					} else {
						// The column is marked; the message is Django's, without the raw field name.
						core.status( statusEl, 'error', target.message !== '' ? target.message : core.errorText( replyObj ) );
					}
				} else {
					setMarks( [] );
				}

				render();
			} );
		} );

		render();

		if ( ctx.dashboardId <= 0 || ! perms.canRead ) {
			return model;
		}

		core.whenVisible( root, function () {
			core.status( statusEl, 'saving', t( 'listLoading' ) );
			readAll( ctx, model ).then( function ( out ) {
				if ( out.ok ) {
					core.status( statusEl, 'saved', '' );
					statusEl.removeAttribute( 'data-state' );
				} else {
					core.status( statusEl, 'error', core.errorText( out.reply ) );
				}

				render();
			} );
		} );

		return model;
	}

	var api_ = {
		createModel: createModel,
		loaderEnabled: loaderEnabled,
		canSave: canSave,
		refusalTarget: refusalTarget,
		readAll: readAll,
		saveList: saveList,
		rowLabel: rowLabel,
		mappingEditor: mappingEditor,
		EVENT: EVENT,
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api_;
	}

	if ( typeof window !== 'undefined' ) {
		window.odoowoo = Object.assign( window.odoowoo || {}, { mappingEditor: mappingEditor, mapping: api_ } );
	}
}() );
