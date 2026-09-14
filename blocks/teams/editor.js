/**
 * Editor UI for the Teams block.
 *
 * No build step, on purpose. Plain ES5-compatible JavaScript using wp.element
 * directly rather than JSX, so this file is the file that runs: somebody
 * debugging it in a browser is looking at the same source that is in the repo,
 * and a fork does not need node installed to change a label.
 *
 * The block is server-rendered, so the editor preview comes from ServerSideRender
 * and is byte-identical to what a visitor gets. There is no second rendering
 * implementation to drift out of agreement with the real one.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;

	var settings = window.lawpBlockData || { events: [], capabilities: {} };

	function eventOptions() {
		var options = [ { label: __( 'Choose an event', 'leagueapps-wp' ), value: '' } ];

		settings.events.forEach( function ( event ) {
			options.push( { label: event.label, value: event.key } );
		} );

		return options;
	}

	function capabilities( eventKey ) {
		return settings.capabilities[ eventKey ] || {};
	}

	/**
	 * Say what the data is doing, in the editor, before anyone publishes.
	 *
	 * An editor who cannot tell a stale list from a fresh one will publish a
	 * stale one. This is the cheapest place to tell them.
	 */
	function statusNotice( eventKey ) {
		if ( ! eventKey ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'Choose a configured event in the block settings.', 'leagueapps-wp' )
			);
		}

		var caps = capabilities( eventKey );

		if ( ! caps.hasSynced ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'This event has no successful sync yet. Run a dry run, then apply it.', 'leagueapps-wp' )
			);
		}

		return el(
			Notice,
			{ status: caps.isStale ? 'warning' : 'success', isDismissible: false },
			caps.isStale
				? __( 'Data is behind schedule. Visitors see the last validated list.', 'leagueapps-wp' ) + ' ' + caps.lastSync
				: __( 'Data current.', 'leagueapps-wp' ) + ' ' + caps.lastSync
		);
	}

	wp.blocks.registerBlockType( 'leagueapps-wp/teams', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var caps = capabilities( a.eventKey );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Data', 'leagueapps-wp' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Event', 'leagueapps-wp' ),
							value: a.eventKey,
							options: eventOptions(),
							onChange: function ( value ) { set( { eventKey: value } ); },
							help: __( 'Events are configured under Settings. This block reads the validated local cache and never calls LeagueApps from a visitor’s browser.', 'leagueapps-wp' )
						} ),
						statusNotice( a.eventKey )
					),
					el(
						PanelBody,
						{ title: __( 'Display', 'leagueapps-wp' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Heading', 'leagueapps-wp' ),
							value: a.heading,
							onChange: function ( value ) { set( { heading: value } ); },
							help: __( 'Leave empty if the page already has one.', 'leagueapps-wp' )
						} ),
						el( SelectControl, {
							label: __( 'Heading level', 'leagueapps-wp' ),
							value: String( a.headingLevel ),
							options: [
								{ label: 'H2', value: '2' },
								{ label: 'H3', value: '3' },
								{ label: 'H4', value: '4' }
							],
							onChange: function ( value ) { set( { headingLevel: parseInt( value, 10 ) } ); },
							help: __( 'Division headings sit one level below this.', 'leagueapps-wp' )
						} ),
						el( ToggleControl, {
							label: __( 'Division jump links', 'leagueapps-wp' ),
							checked: a.showJumpLinks,
							onChange: function ( value ) { set( { showJumpLinks: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show last updated', 'leagueapps-wp' ),
							checked: a.showLastUpdated,
							onChange: function ( value ) { set( { showLastUpdated: value } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show empty divisions', 'leagueapps-wp' ),
							checked: a.showEmpty,
							onChange: function ( value ) { set( { showEmpty: value } ); }
						} )
					),
					/*
					 * Controls appear only for data this event actually has.
					 *
					 * A toggle that renders a blank column is worse than no toggle:
					 * it makes an editor think they configured something wrong.
					 */
					el(
						PanelBody,
						{ title: __( 'Available for this event', 'leagueapps-wp' ), initialOpen: false },
						caps.captain
							? el( ToggleControl, {
								label: __( 'Show manager name', 'leagueapps-wp' ),
								checked: a.showCaptain,
								onChange: function ( value ) { set( { showCaptain: value } ); },
								help: caps.captainCoverage || ''
							} )
							: el( 'p', null, __( 'No manager names in this event’s data.', 'leagueapps-wp' ) ),
						caps.rosterCounts
							? el( ToggleControl, {
								label: __( 'Show player counts', 'leagueapps-wp' ),
								checked: a.showCounts,
								onChange: function ( value ) { set( { showCounts: value } ); },
								help: caps.rosterHelp || ''
							} )
							: el( 'p', null, __( 'Every team in this event has a single registration, so a player count would read 1 for all of them.', 'leagueapps-wp' ) ),
						el( 'p', null, __( 'Schedules and standings are not available: LeagueApps publishes no endpoint for them.', 'leagueapps-wp' ) )
					),
					el(
						PanelBody,
						{ title: __( 'Source link', 'leagueapps-wp' ), initialOpen: false },
						el( TextControl, {
							label: __( 'LeagueApps URL', 'leagueapps-wp' ),
							value: a.sourceUrl,
							type: 'url',
							onChange: function ( value ) { set( { sourceUrl: value } ); },
							help: __( 'Optional. Links visitors to the official registration page.', 'leagueapps-wp' )
						} ),
						el( TextControl, {
							label: __( 'Link text', 'leagueapps-wp' ),
							value: a.sourceLabel,
							onChange: function ( value ) { set( { sourceLabel: value } ); }
						} )
					)
				),
				el(
					'div',
					useBlockProps(),
					a.eventKey
						? el( ServerSideRender, {
							block: 'leagueapps-wp/teams',
							attributes: a
						} )
						: el(
							'p',
							{ className: 'lawp-teams-placeholder' },
							__( 'LeagueApps Teams: choose an event in the block settings.', 'leagueapps-wp' )
						)
				)
			);
		},

		// Server-rendered, so nothing is stored in post content but the attributes.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
