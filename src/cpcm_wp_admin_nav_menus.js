(function($) {
		
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

	$(document).ready(function(){

		var toggle = function(){
			var checked = $(this).is(':checked');
			var container = $(this).parent().parent();
			var items = $(this).parents('.cpcm-description').find('p.description:not(.field-cpcm-unfold)');

			if(checked) {
				container.css('margin-bottom', '');
				items.removeClass('d-none');
			} else {
				container.css('margin-bottom', 0);
				items.addClass('d-none');
			}
		};

		var init = function(){
			$('.cpcm-description p.description:not(.field-cpcm-unfold)').addClass('d-none');
			$('.cpcm-description > .field-cpcm-unfold input[type="checkbox"]').off('change', toggle).on('change', toggle).trigger('change');
		};

		$(document).on('menu-item-added', function(){
			init();
		});

		init();
		wpNavMenu.menusChanged = false;
	});

})(jQuery); // Fully reference jQuery after this point.
