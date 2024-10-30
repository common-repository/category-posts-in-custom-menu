/*  Copyright 2024 Diana van de Laarschot (email : mail@telodelic.nl)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

function cpcm_free_update_field_display( control ) {
	const type = control.setting().type;

	if (type !== 'taxonomy')
	{
		// All fields, including checkbox, should be invisible
		control.cpcmFields.addClass('d-none');
		return;
	}

	const cpcm_unfold = !!control.setting().cpcm_unfold || false;
	const cpcm_item_count = control.setting().cpcm_item_count || 10;
	const cpcm_orderby = control.setting().cpcm_orderby || 'none';
	const cpcm_item_titles = control.setting().cpcm_item_titles || '%post_title';

	// Set field visibility
	const fields = control.cpcmFields.filter(':not(.field-cpcm-unfold)');
	if(!!cpcm_unfold) {
		control.cpcmContainer.css('margin-bottom', '');
		fields.removeClass('d-none');
	} else {
		control.cpcmContainer.css('margin-bottom', 0);
		fields.addClass('d-none');
	}

	// Set the values
	control.cpcmUnfoldCheckbox.prop( 'checked', !!cpcm_unfold );
	control.cpcmFields.filter('.field-cpcm-item-count').find('select').val(cpcm_item_count);
	control.cpcmFields.filter('.field-cpcm-orderby').find('select').val(cpcm_orderby);
	control.cpcmFields.filter('.field-cpcm-item-titles').find('textarea').val(cpcm_item_titles);
}

/*
 * Initialize 
 */
function cpcm_free_custom_fields( control ) {
    control.cpcmUnfoldCheckbox = control.container.find( '.cpcm-description > .field-cpcm-unfold input[type="checkbox"]' );
	control.cpcmContainer = control.container.find('.cpcm-description');
	control.cpcmFields = control.container.find( '.cpcm-description p.description' );

    // Set the initial UI state.
    cpcm_free_update_field_display( control );

    // Update the UI state when the setting changes programmatically.
    control.setting.bind( function () {
        cpcm_free_update_field_display( control );
    } );

    // Update the setting when the inputs are modified.
    control.cpcmUnfoldCheckbox.on( 'change', function () {
		control.setting.set(Object.assign({}, _.clone( control.setting() ), { cpcm_unfold: !!this.checked }));
    } );

	control.cpcmFields.filter('.field-cpcm-item-count').find('select').on('change', function(){
		control.setting.set(Object.assign({}, _.clone( control.setting() ), { cpcm_item_count: this.value }));
	});
	control.cpcmFields.filter('.field-cpcm-orderby').find('select').on('change', function(){
		control.setting.set(Object.assign({}, _.clone( control.setting() ), { cpcm_orderby: this.value }));
	});
	control.cpcmFields.filter('.field-cpcm-item-titles').find('textarea').on('change', function(){
		control.setting.set(Object.assign({}, _.clone( control.setting() ), { cpcm_item_titles: this.value }));
	});
}

( function( wp ) {
	wp.customize.control.bind( 'add', function ( control ) {
		if ( control.extended( wp.customize.Menus.MenuItemControl ) ) {
			control.deferred.embedded.done( function () {
				cpcm_free_custom_fields( control );
			} );
		}
	} );
} )( wp );
