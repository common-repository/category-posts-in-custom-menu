<?php
/*
 * Plugin Name: Category Posts in Custom Menu - Free 3.0
 * Plugin URI: https://wordpress.telodelic.nl/category-posts-in-custom-menu
 * Description: Dynamic menus: List all posts from a category in your menu. Also works for tags, custom taxonomies as well as pages and custom post types.
 * Version: 3.0.2
 * Author: Diana van de Laarschot
 * Author URI: https://wordpress.telodelic.nl
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.4
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Text Domain: category-posts-in-custom-menu
 */

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

// Load all the nav menu interface functions
require_once(ABSPATH . 'wp-admin/includes/nav-menu.php');

require('src/cpcm-functions.php');

class CPCM_Manager_Free
{
	const OPTION_NAME = 'cpcm_options';
	const VERSION = '3.0.0';
	const CSS_VERSION = '3.0.0';
	const JS_VERSION = '3.0.0';

	protected $options = null;
	protected $defaults = array('version' => self::VERSION);

	public function __construct()
	{
		add_filter('wp_nav_menu_objects', array(&$this, 'cpcm_nav_menu_objects'), 1, 2);

		// https://make.wordpress.org/core/2020/02/25/wordpress-5-4-introduces-new-hooks-to-add-custom-fields-to-menu-items/
		// For use in Appearance > Menus:
		add_action('admin_enqueue_scripts', array(&$this, 'cpcm_wp_admin_nav_menus_css'));
		add_action('admin_enqueue_scripts', array(&$this, 'cpcm_wp_admin_nav_menus_js'));
		add_action('wp_nav_menu_item_custom_fields', array(&$this, 'cpcm_wp_nav_menu_item_custom_fields'), 10, 4);
		add_action('wp_update_nav_menu_item', array(&$this, 'cpcm_update_nav_menu_item'), 1, 3);

		// For use via Theme > Customize:
		add_action('customize_controls_enqueue_scripts', array(&$this, 'cpcm_customize_controls_enqueue_scripts_css'));
		add_action('customize_controls_enqueue_scripts', array(&$this, 'cpcm_customize_controls_enqueue_scripts_js'));
		add_action('wp_nav_menu_item_custom_fields_customize_template', array(&$this, 'cpcm_wp_nav_menu_item_custom_fields_customize_template'), 10, 0);
		add_filter('wp_setup_nav_menu_item', array(&$this, 'cpcm_wp_setup_nav_menu_item'), 10, 1);
		add_action('customize_register', array(&$this, 'cpcm_customize_register'), 10, 1);
		add_action('customize_save_after', array(&$this, 'cpcm_customize_save_after'), 10, 1);

		// For use via Block Editor:
		add_action('enqueue_block_editor_assets', array(&$this, 'cpcm_enqueue_block_editor_assets_js'));
		add_action('block_core_navigation_render_inner_blocks', array(&$this, 'cpcm_block_core_navigation_render_inner_blocks'));

		// Add button to plugin description
		add_filter('plugin_row_meta', array(&$this, 'cpcm_plugin_row_meta'), 10, 2);

		// Auto-update if a new plugin version is available via central wordpress.org repository
		add_filter('auto_update_plugin', array(&$this, 'cpcm_auto_update_plugin'), 10, 2);
	} // function

	function CPCM_Manager_Free()
	{
		$this->get_options();
		$this->__construct();
	} // function

	// Build a reverse lookup to find the taxonomy name for a given block type
	// https://core.trac.wordpress.org/ticket/60631#ticket 
	function cpcm_get_block_taxonomy_name(&$taxonomies, $block_type) {
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				$variation = build_variation_for_navigation_link( $taxonomy, 'taxonomy' );
				if ($variation && $variation["name"] == $block_type) {
					return $taxonomy->name;
				}
			}
		}
	
		return null;
	}

	function cpcm_block_core_navigation_render_inner_blocks_recursive(&$result_parent, &$original_parent, &$original_item, &$menu_item_parent_map, &$taxonomies)
	{
		// We need to work on the inner array. (Array methods will throw if it's a WP_Block)
		if (is_a($original_item, 'WP_Block')) {
			$original_item = $original_item->parsed_block;
		}
		$result_item = array_merge($original_item, array("innerBlocks" => array()));
		if (array_key_exists("attrs", $original_item)) {
			$get_attribute_value = fn($i, $k, $def) => is_array($i) && array_key_exists("attrs", $i) && array_key_exists($k, $i["attrs"]) ? $i["attrs"][$k] : $def;
			// Augment taxonomy object with a list of its posts: Append posts to $result
			if ($get_attribute_value($original_item, "cpcm_unfold", false)) {
				$taxonomy_name = $this->cpcm_get_block_taxonomy_name($taxonomies, $get_attribute_value($original_item, "type", null));
				if (!$taxonomy_name){
					return;
				}

				$id = $get_attribute_value($original_item, "id", null);
				$cpcm_item_count = $get_attribute_value($original_item, "cpcm_item_count", 10);
				$cpcm_item_count = ($cpcm_item_count <= 0 || $cpcm_item_count >= 10) ? 10 : $cpcm_item_count;
				$cpcm_orderby = $get_attribute_value($original_item, "cpcm_orderby", "none");
				$cpcm_orderby = ($cpcm_orderby == "none" || $cpcm_orderby == "title" || $cpcm_orderby == "date" || $cpcm_orderby == "menu_order") ? $cpcm_orderby : "date";
				$cpcm_order = ($cpcm_orderby == "date" ? "DESC" : "ASC");
			
				$query_arr = array();

				// Example:  Array ( [0] => Array ( [taxonomy] => category [field] => id [terms] => 3 ) ), i.e. get a category by id, where id = 3
				$query_arr['tax_query'] = array(
					'relation' => 'AND',
					array(
						'taxonomy' => $taxonomy_name,
						'field' => 'id',
						'terms' => $id
					)
				);

				// If _cpcm-unfold is true, the following custom fields exist:
				$query_arr['order'] = $cpcm_order;
				$query_arr['orderby'] = $cpcm_orderby;
				$query_arr['numberposts'] = $cpcm_item_count; // default value of -1 returns all posts
				$query_arr['offset'] = 0; // default value of 0 skips no posts

				// Support for custom post types
				$tag = get_taxonomy($taxonomy_name);
				if ($tag) {
					$query_arr['post_type'] = $tag->object_type;
				}

				$posts = get_posts($query_arr);

				// Replace taxonomy by its posts
				$result_item = (new CPCM_Functions_Free)->cpcm_block_editor_replace_taxonomy_by_posts($result_parent, $original_parent, $original_item, $posts, $menu_item_parent_map);
			} else {
				// Other objects may have a parent that has been removed by cpcm. Fix that here.
				// Set the menu_item_parent for the menu_item: If the parent item was removed, go up a level
				$parent_id = $get_attribute_value($original_parent, "id", null);
				while (array_key_exists(strval($parent_id), $menu_item_parent_map) == 1) {
					$result_parent = &$menu_item_parent_map[$parent_id];
					$parent_id = $get_attribute_value($result_parent, "id", null);
				}

				// Append to correct parent
				$result_parent["innerBlocks"][] = &$result_item;
			}
	
			if (is_array($original_item["innerBlocks"])) {
				foreach ($original_item["innerBlocks"] as $key => &$innerBlock) {
					$this->cpcm_block_core_navigation_render_inner_blocks_recursive($result_item, $original_item, $innerBlock, $menu_item_parent_map, $taxonomies);
				}
			}
		}
	}

	// Workaround. Dropdown carets are not generated for parent items
	// Same problem as call to _wp_menu_item_classes_by_context in function cpcm_nav_menu_objects
	// Reported here: https://core.trac.wordpress.org/ticket/60572#ticket
	function decorate_block_recursive(&$menu_item)
	{
		$menu_item = new WP_Block($menu_item, array("showSubmenuIcon" => 1)); // This is the actual fix, 'activate' the context attribute to make WordPress honor it.

		if (count($menu_item->parsed_block["innerBlocks"]) > 0) {
			foreach ($menu_item->parsed_block["innerBlocks"] as $key => &$innerBlock) {
				$this->decorate_block_recursive($innerBlock);
			}
		}
	}

	function cpcm_block_core_navigation_render_inner_blocks($items)
	{
		$this->get_options();
		
		$result = array("innerBlocks" => array());
		$menu_item_parent_map = array(); // Holds, for each menu item I that was removed, a link to the item that should become the new parent P of menu items under I

		$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'objects' );
		foreach ($items as $key => $item) {
			$this->cpcm_block_core_navigation_render_inner_blocks_recursive($result, $result, $item->parsed_block, $menu_item_parent_map, $taxonomies);
		}
		
		unset($menu_item_parent_map);

		// Workaround. Dropdown carets are not generated for parent items
		// Same problem as call to _wp_menu_item_classes_by_context in function cpcm_nav_menu_objects
		// Reported here: https://core.trac.wordpress.org/ticket/60572#ticket
		foreach ($result["innerBlocks"] as $key => &$innerBlock) {
			$this->decorate_block_recursive($innerBlock);
		}

		return new WP_Block_List($result["innerBlocks"]);
	}

	function cpcm_auto_update_plugin($update, $item)
	{
		return ($item->slug == 'category-posts-in-custom-menu') ? true : $update;
	} // function

	// Update from no versioning or version 1.1 to 1.2.0
	private function Update120()
	{
		/*
		 * Upgrade to 1.2.0
		 * Update all custom fields: They should have a starting underscore
		 */
		$args = array('post_type' => 'nav_menu_item');
		$all_nav_menu_items = get_posts($args);

		foreach ($all_nav_menu_items as $nav_menu_item) {
			$cpcm_unfold = get_post_meta($nav_menu_item->ID, "cpcm-unfold", true);
			if ($cpcm_unfold !== '') {
				update_post_meta($nav_menu_item->ID, "_cpcm-unfold", $cpcm_unfold);
			}
			$cpcm_orderby = get_post_meta($nav_menu_item->ID, "cpcm-orderby", true);
			if ($cpcm_orderby !== '') {
				update_post_meta($nav_menu_item->ID, "_cpcm-orderby", $cpcm_orderby);
				update_post_meta($nav_menu_item->ID, "_cpcm-order", ($cpcm_orderby == "date" ? "DESC" : "ASC"));
			}
			$cpcm_item_count = get_post_meta($nav_menu_item->ID, "cpcm-item-count", true);
			if ($cpcm_item_count !== '') {
				update_post_meta($nav_menu_item->ID, "_cpcm-item-count", $cpcm_item_count);
				update_post_meta($nav_menu_item->ID, "_cpcm-item-skip", "0");
			}
			$cpcm_item_titles = get_post_meta($nav_menu_item->ID, "cpcm-item-titles", true);
			if ($cpcm_item_titles !== '') {
				update_post_meta($nav_menu_item->ID, "_cpcm-item-titles", $cpcm_item_titles);
			}
			update_post_meta($nav_menu_item->ID, "_cpcm-remove-original-item", "always");
			update_post_meta($nav_menu_item->ID, "_cpcm-subcategories", "include");
		}

		// Delete old custom fields
		delete_metadata('nav_menu_item', null, 'cpcm-unfold', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-orderby', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-order', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-item-count', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-item-skip', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-item-titles', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-remove-original-item', '', true);
		delete_metadata('nav_menu_item', null, 'cpcm-subcategories', '', true);
		/* End upgrade to 1.2.0 */
	}

	private function get_options()
	{
		// already did the checks
		if (isset($this->options)) {
			return $this->options;
		}

		// first call, get the options
		$options = get_option(self::OPTION_NAME);

		// options exist
		if ($options !== false) {
			$version_11 = version_compare($options['version'], "1.1", "==");
			$new_version = version_compare($options['version'], self::VERSION, '<');
			$desync = array_diff_key($this->defaults, $options) !== array_diff_key($options, $this->defaults);

			// update options if version changed, or we have missing/extra (out of sync) option entries 
			if ($new_version || $desync) {
				// I made a mistake in version 1.1, resulting in a version number but no upgrade performed.
				// If user comes from version 1.1, perform upgrade after all.
				if ($version_11) {
					$this->Update120();
				}

				$new_options = array();

				// check for new options and set defaults if necessary
				foreach ($this->defaults as $option => $value) {
					$new_options[$option] = isset($options[$option]) ? $options[$option] : $value;
				}

				// update version info
				$new_options['version'] = self::VERSION;

				update_option(self::OPTION_NAME, $new_options);
				$this->options = $new_options;
			} else // no update required
			{
				$this->options = $options;
			}
		} else // either new install or version from before versioning existed 
		{
			$this->Update120(); // update to first version with (proper) versioning

			update_option(self::OPTION_NAME, $this->defaults);
			$this->options = $this->defaults;
		}

		return $this->options;
	}

	static function cpcm_uninstall()
	{
		// We're uninstalling, so delete all custom fields on nav_menu_items that the CPCM plugin added
		// Unless the premium version is active
		$installed_plugins = get_plugins();
		if (!(array_key_exists( 'category-posts-in-custom-menu-premium', $installed_plugins ) || in_array( 'category-posts-in-custom-menu-premium', $installed_plugins, true ))) {
			// Premium version not installed, safe to delete all fields
			delete_metadata('nav_menu_item', null, '_cpcm-unfold', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-orderby', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-order', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-item-count', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-item-skip', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-item-titles', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-remove-original-item', '', true);
			delete_metadata('nav_menu_item', null, '_cpcm-subcategories', '', true);
		}
	} // function

	/* 
	 * Add JS for div.cpcm-description and other CPCM fields to nav-menus.php (Appearance > Menus)
	 */
	function cpcm_wp_admin_nav_menus_js($hook)
	{
		// Check the hook so that the .js is only added to the .php file where we need it
		if ('nav-menus.php' != $hook)
			return;

		wp_enqueue_script(
			'cpcm_wp_admin_nav_menus_js',
			plugins_url('src/cpcm_wp_admin_nav_menus.js', __FILE__),
			['jquery'],
			self::JS_VERSION,
			true
		);
	} // function

	/* 
	 * Add CSS for div.cpcm-description and other CPCM css classes to nav-menus.php (in Appearance > Menus)
	 */
	function cpcm_wp_admin_nav_menus_css($hook)
	{
		// Check the hook so that the .css is only added to the .php file where we need it
		if ('nav-menus.php' != $hook)
			return;

		wp_enqueue_style(
			'cpcm_wp_admin_nav_menus_css',
			plugins_url('src/cpcm.css', __FILE__),
			array(),
			self::CSS_VERSION
		);
	} // function

	/* 
	 * Add JS for div.cpcm-description and other CPCM fields customize.php (Appearance > Customize, the theme customizer)
	 */
	function cpcm_customize_controls_enqueue_scripts_js( /* no $hook parameter in this case */)
	{
		wp_enqueue_script(
			'cpcm_customize_controls_enqueue_scripts_js',
			plugins_url('src/cpcm_customize_controls_enqueue_scripts.js', __FILE__),
			['jquery'],
			self::JS_VERSION,
			true
		);
	} // function

	/* 
	 * Add CSS for div.cpcm-description and other CPCM css classes to customize.php (Appearance > Customize, the theme customizer)
	 */
	function cpcm_customize_controls_enqueue_scripts_css( /* no $hook parameter in this case */)
	{
		wp_enqueue_style(
			'cpcm_customize_controls_enqueue_scripts_css',
			plugins_url('src/cpcm.css', __FILE__),
			array(),
			self::CSS_VERSION
		);
	} // function

	/* 
	 * Build the menu structure for display: Augment taxonomies (category, tags or custom taxonomies) that have been marked as such, by their posts. Optionally: remove original menu item.
	 */
	function cpcm_nav_menu_objects($sorted_menu_items, $args)
	{
		$this->get_options();
		$result = array();
		$delta_posts = 0;

		$menu_item_parent_map = array(); // Holds, for each menu item I that was removed, a link to the item that should become the new parent P of menu items under I
		foreach ((array) $sorted_menu_items as $key => $menu_item) {
			$menu_item->menu_order = $menu_item->menu_order + $delta_posts;

			// Augment taxonomy object with a list of its posts: Append posts to $result
			// Optional: Remove the taxonomy object/original menu item itself.
			if ($menu_item->type == 'taxonomy' && (get_post_meta($menu_item->db_id, "_cpcm-unfold", true) == '1')) {
				$query_arr = array();

				// Example:  Array ( [0] => Array ( [taxonomy] => category [field] => id [terms] => 3 ) ), i.e. get a category by id, where id = 3
				$query_arr['tax_query'] = array(
					'relation' => 'AND',
					array(
						'taxonomy' => $menu_item->object,
						'field' => 'id',
						'terms' => $menu_item->object_id
					)
				);

				// If _cpcm-unfold is true, the following custom fields exist:
				$cpcm_orderby = get_post_meta($menu_item->db_id, "_cpcm-orderby", true);
				$cpcm_orderby = ($cpcm_orderby == "none" || $cpcm_orderby == "title" || $cpcm_orderby == "date" || $cpcm_orderby == "menu_order") ? $cpcm_orderby : "date";
				$query_arr['order'] = ($cpcm_orderby == "date" ? "DESC" : "ASC");
				$query_arr['orderby'] = $cpcm_orderby;
				$cpcm_item_count = get_post_meta($menu_item->db_id, "_cpcm-item-count", true);
				$cpcm_item_count = ($cpcm_item_count <= 0 || $cpcm_item_count >= 10) ? 10 : $cpcm_item_count;
				$query_arr['numberposts'] = $cpcm_item_count;
				$query_arr['offset'] = 0;

				// Support for custom post types
				$tag = get_taxonomy($menu_item->object);
				$query_arr['post_type'] = $tag->object_type;

				$posts = get_posts($query_arr);
				$delta_posts += (new CPCM_Functions_Free)->cpcm_replace_taxonomy_by_posts($result, $menu_item, $posts, $menu_item_parent_map);
			} else {
				// Other objects may have a parent that has been removed by cpcm. Fix that here.
				// Set the menu_item_parent for the menu_item: If the parent item was removed, go up a level
				$current_parent_id = $menu_item->menu_item_parent;
				while (array_key_exists(strval($current_parent_id), $menu_item_parent_map) == 1) {
					$current_parent_id = $menu_item_parent_map[$current_parent_id];
				}
				$menu_item->menu_item_parent = $current_parent_id;

				// Treat other objects as usual, but note that the position 
				// of elements in the array changes.
				array_push($result, $menu_item);
			}
		}

		unset($sorted_menu_items);
		unset($menu_item_parent_map);

		// Apply _wp_menu_item_classes_by_context not only to the $posts array, but to the whole result array so that the classes for the original menu items are regenerated as well. Solves: http://wordpress.org/support/topic/issue-with-default-wordpress-sidebar-menu and http://wordpress.org/support/topic/menu-do-not-include-the-current-menu-parent-class
		_wp_menu_item_classes_by_context($result);

		return $result;
	} // function

	/*
	 * Add a button to the plugin description on the plugins page
	 */
	function cpcm_plugin_row_meta($links, $file)
	{
		if (plugin_basename(__FILE__) == $file) {
			$row_meta = array(
				'docs' => '<a href="' . esc_url('https://wordpress.telodelic.nl/category-posts-in-custom-menu/') . '" target="_blank" aria-label="' . esc_attr__('Go Premium', 'category-posts-in-custom-menu') . '" style="color:green;">' . esc_html__('Go Premium', 'category-posts-in-custom-menu') . '</a>'
			);
			return array_merge($links, $row_meta);
		}

		return $links;
	}

	/*
	 * Store the entered data in nav-menus.php by inspecting the $_POST variable again.
	 */
	function cpcm_update_nav_menu_item($menu_id = 0, $menu_item_db_id = 0, $menu_item_data = array())
	{
		// Only inspect the values if the $_POST variable contains data (the wp_update_nav_menu_item filter is applied in three other places, without a $_POST action)
		if (!empty($_POST['menu-item-db-id'])) {
			// Only process nav_menu_items that actually had the CPCM checkbox option
			if ($menu_item_data['menu-item-type'] == 'taxonomy') {
				update_post_meta($menu_item_db_id, '_cpcm-unfold', (!empty($_POST['menu-item-cpcm-unfold'][$menu_item_db_id])));
				$cpcm_orderby = (empty($_POST['menu-item-cpcm-orderby'][$menu_item_db_id]) ? "none" : $_POST['menu-item-cpcm-orderby'][$menu_item_db_id]);
				update_post_meta($menu_item_db_id, '_cpcm-orderby', $cpcm_orderby);
				update_post_meta($menu_item_db_id, '_cpcm-order', ($cpcm_orderby == "date" ? "DESC" : "ASC"));
				$cpcm_item_count = (int) (empty($_POST['menu-item-cpcm-item-count'][$menu_item_db_id]) ? "10" : $_POST['menu-item-cpcm-item-count'][$menu_item_db_id]);
				$cpcm_item_count = ($cpcm_item_count <= 0 || $cpcm_item_count >= 10) ? 10 : $cpcm_item_count;
				update_post_meta($menu_item_db_id, '_cpcm-item-count', $cpcm_item_count);
				update_post_meta($menu_item_db_id, '_cpcm-item-skip', (int) "0");
				update_post_meta($menu_item_db_id, '_cpcm-item-titles', (empty($_POST['menu-item-cpcm-item-titles'][$menu_item_db_id]) ? "%post_title" : $_POST['menu-item-cpcm-item-titles'][$menu_item_db_id]));
				update_post_meta($menu_item_db_id, '_cpcm-remove-original-item', "always");
				update_post_meta($menu_item_db_id, '_cpcm-subcategories', "flatten");
			}
		} // if 
	} // function

	/*
	 * Add input fields to the Appearance > Menus page
	 */
	function cpcm_wp_nav_menu_item_custom_fields($item_id, $item, $depth, $args)
	{
		$this->get_options();
		$item_id = esc_attr($item->ID);

		/* BEGIN CATEGORY POSTS IN CUSTOM MENU */
		if ($item->type == 'taxonomy'): ?>
			<div class="cpcm-description">
				<p class="field-cpcm-unfold description description-wide">
					<label for="edit-menu-item-cpcm-unfold-<?php echo $item_id; ?>">
						<input type="checkbox" id="edit-menu-item-cpcm-unfold-<?php echo $item_id; ?>"
							class="edit-menu-item-cpcm-unfold" name="menu-item-cpcm-unfold[<?php echo $item_id; ?>]" <?php checked(get_post_meta($item_id, "_cpcm-unfold", true), true) ?> />

						<?php esc_html_e('Replace by posts' . (('Category' == $item->type_label) ? ' in this category.' : ((('Tag' == $item->type_label) || ('Post Tag' == $item->type_label)) ? ' with this tag.' : ' in this taxonomy.')), 'category-posts-in-custom-menu'); ?>
					</label>
				</p>
				<div>
					<p class="field-cpcm-item-count description description-thin">
						<label for="edit-menu-item-cpcm-item-count-<?php echo $item_id; ?>">
							<?php _e('Number of Posts', 'category-posts-in-custom-menu'); ?> (max 10)<br />

							<select id="edit-menu-item-cpcm-item-count-<?php echo $item_id; ?>"
								class="widefat code edit-menu-item-cpcm-item-count"
								name="menu-item-cpcm-item-count[<?php echo $item_id; ?>]">
								<?php
								$cpcm_item_count = get_post_meta($item_id, "_cpcm-item-count", true);
								$cpcm_item_count = ($cpcm_item_count <= 0 || $cpcm_item_count >= 10) ? 10 : $cpcm_item_count;
								?>
								<option value="1" <?php selected($cpcm_item_count, "1") ?>>1</option>
								<option value="2" <?php selected($cpcm_item_count, "2") ?>>2</option>
								<option value="3" <?php selected($cpcm_item_count, "3") ?>>3</option>
								<option value="4" <?php selected($cpcm_item_count, "4") ?>>4</option>
								<option value="5" <?php selected($cpcm_item_count, "5") ?>>5</option>
								<option value="6" <?php selected($cpcm_item_count, "6") ?>>6</option>
								<option value="7" <?php selected($cpcm_item_count, "7") ?>>7</option>
								<option value="8" <?php selected($cpcm_item_count, "8") ?>>8</option>
								<option value="9" <?php selected($cpcm_item_count, "9") ?>>9</option>
								<option value="10" <?php selected($cpcm_item_count, "10") ?>>10</option>
							</select>
						</label>
					</p>
					<p class="field-cpcm-orderby description description-thin">
						<label for="edit-menu-item-cpcm-orderby-<?php echo $item_id; ?>">
							<?php esc_html_e('Order By', 'category-posts-in-custom-menu'); ?><br />
							<select id="edit-menu-item-cpcm-orderby-<?php echo $item_id; ?>"
								class="widefat edit-menu-item-cpcm-orderby" name="menu-item-cpcm-orderby[<?php echo $item_id; ?>]">
								<option value="none" <?php selected(get_post_meta($item_id, "_cpcm-orderby", true), "none") ?>>
									<?php esc_html_e('None', 'category-posts-in-custom-menu'); ?></option>
								<option value="title" <?php selected(get_post_meta($item_id, "_cpcm-orderby", true), "title") ?>>
									<?php esc_html_e('Title', 'category-posts-in-custom-menu'); ?></option>
								<option value="date" <?php selected(get_post_meta($item_id, "_cpcm-orderby", true), "date") ?>>
									<?php esc_html_e('Date', 'category-posts-in-custom-menu'); ?></option>
								<option value="menu_order" <?php selected(get_post_meta($item_id, "_cpcm-orderby", true), "menu_order") ?>><?php esc_html_e('Menu Order', 'category-posts-in-custom-menu'); ?></option>
							</select>
						</label>
					</p>
				</div>

				<p class="field-cpcm-item-titles description description-wide">
					<label for="edit-menu-item-cpcm-item-titles-<?php echo $item_id; ?>">
						<?php esc_html_e('Navigation Label', 'category-posts-in-custom-menu'); ?> <br />
						<textarea id="edit-menu-item-cpcm-item-titles-<?php echo $item_id; ?>"
							class="widefat code edit-menu-item-cpcm-item-titles"
							name="menu-item-cpcm-item-titles[<?php echo $item_id; ?>]" rows="4"><?php $item_titles = get_post_meta($item_id, "_cpcm-item-titles", true);
							   echo $item_titles != '' ? esc_attr($item_titles) : '%post_title' ?></textarea>
						<span class="description">
							<?php esc_html_e('The navigation label may be customized using wildcards such as %post_title. See documentation.', 'category-posts-in-custom-menu'); ?>
						</span>
					</label>
				</p>

			</div>

		<?php endif;
		/* CATEGORY POSTS IN CUSTOM MENU END */
	}

	function get_customizer_posted_values($unsanitized_post_values, $setting_id)
	{
		$result = array(
			'type' => 'unknown',
			'cpcm_unfold' => false,
			'cpcm_orderby' => "none",
			'cpcm_item_count' => 10,
			'cpcm_item_titles' => '%post_title'
		);

		if (!array_key_exists($setting_id, $unsanitized_post_values)) {
			return $result;
		}

		$unsanitized_post_value = $unsanitized_post_values[$setting_id];

		if (!is_array($unsanitized_post_value) || !array_key_exists('type', $unsanitized_post_value)) {
			return $result;
		}

		$type = $unsanitized_post_value['type'];
		$cpcm_unfold = array_key_exists('cpcm_unfold', $unsanitized_post_value) ? $unsanitized_post_value['cpcm_unfold'] : '';
		$cpcm_orderby = array_key_exists('cpcm_orderby', $unsanitized_post_value) ? $unsanitized_post_value['cpcm_orderby'] : '';

		$cpcm_item_count = array_key_exists('cpcm_item_count', $unsanitized_post_value) ? $unsanitized_post_value['cpcm_item_count'] : '';
		$cpcm_item_count = (int) (is_numeric($cpcm_item_count) ? $cpcm_item_count : "10");
		$cpcm_item_count = ($cpcm_item_count <= 0 || $cpcm_item_count >= 10) ? 10 : $cpcm_item_count;

		$cpcm_item_titles = array_key_exists('cpcm_item_titles', $unsanitized_post_value) ? $unsanitized_post_value['cpcm_item_titles'] : '';

		return array(
			'type' => isset($type) ? $type : 'unknown',
			'cpcm_unfold' => isset($cpcm_unfold) && !empty($cpcm_unfold),
			'cpcm_orderby' => (isset($cpcm_orderby) && !empty($cpcm_orderby)) ? $cpcm_orderby : "none",
			'cpcm_item_count' => $cpcm_item_count,
			'cpcm_item_titles' => (isset($cpcm_item_titles) && !empty($cpcm_item_titles)) ? $cpcm_item_titles : '%post_title'
		);
	}

	/*
	 * Implement preview functionality for the Theme Customizer (for existing menu items, doesn't work for new ones)
	 */
	function cpcm_customize_register($wp_customize)
	{
		if ($wp_customize->settings_previewed()) {
			// Note: We should be able to iterate over $wp_customize->settings()
			// but apparently during preview this returns an empty array.
			// Instead, iterate the raw data
			$unsanitized_post_values = $wp_customize->unsanitized_post_values();
			foreach ($unsanitized_post_values as $key => $setting) {

				$posted_values = $this->get_customizer_posted_values($unsanitized_post_values, $key);
				$setting_id = (int) preg_replace('/[^0-9]/', '', $key); // Example: 'nav_menu_item[33]', parse out the id

				// Add a filter for get_post_meta for each settings object and augment it with the retrieved data.
				add_filter(
					'get_post_metadata',
					static function ($value, $object_id, $meta_key) use ($posted_values, $setting_id) {
						$result = $value;
						if ($object_id === $setting_id) {
							switch ($meta_key) {
								case '_cpcm-unfold':
									$result = $posted_values['cpcm_unfold'];
									break;
								case '_cpcm-orderby':
									$result = $posted_values['cpcm_orderby'];
									break;
								case '_cpcm-item-count':
									$result = $posted_values['cpcm_item_count'];
									break;
								case '_cpcm-item-titles':
									$result = $posted_values['cpcm_item_titles'];
									break;
								default:
									break;
							}
						}

						return $result;
					},
					10,
					3
				);
			}
		}
	}

	/*
	 * Save additional fields when user saves in the Theme Customizer
	 */
	function cpcm_customize_save_after($wp_customize)
	{
		foreach ($wp_customize->settings() as $setting) {
			if ($setting instanceof WP_Customize_Nav_Menu_Item_Setting) {
				$posted_values = $this->get_customizer_posted_values($setting->manager->unsanitized_post_values(), $setting->id);
				if ($posted_values['type'] !== 'taxonomy') {
					return;
				}

				update_post_meta($setting->post_id, '_cpcm-unfold', (!empty($posted_values['cpcm_unfold'])));
				$cpcm_orderby = (empty($posted_values['cpcm_orderby']) ? "none" : $posted_values['cpcm_orderby']);
				update_post_meta($setting->post_id, '_cpcm-orderby', $cpcm_orderby);
				update_post_meta($setting->post_id, '_cpcm-order', ($cpcm_orderby == "date" ? "DESC" : "ASC"));
				$cpcm_item_count = (int) (empty($posted_values['cpcm_item_count']) ? "10" : $posted_values['cpcm_item_count']);
				$cpcm_item_count = ($cpcm_item_count <= 0 || $cpcm_item_count >= 10) ? 10 : $cpcm_item_count;
				update_post_meta($setting->post_id, '_cpcm-item-count', (($cpcm_item_count > 0 && $cpcm_item_count <= 10) ? $cpcm_item_count : 10));
				update_post_meta($setting->post_id, '_cpcm-item-skip', (int) "0");
				update_post_meta($setting->post_id, '_cpcm-item-titles', (empty($posted_values['cpcm_item_titles']) ? "%post_title" : $posted_values['cpcm_item_titles']));
				update_post_meta($setting->post_id, '_cpcm-remove-original-item', "always");
				update_post_meta($setting->post_id, '_cpcm-subcategories', "flatten");
			}
		}
	}

	/*
	 * cpcm_wp_nav_menu_item_custom_fields_customize_template depends on this
	 * Add extra data to the menu items, so that the data can be read in cpcm_wp_nav_menu_item_custom_fields_customize_template
	 */
	function cpcm_wp_setup_nav_menu_item($menu_item)
	{
		if (is_object($menu_item) && isset($menu_item->ID)) {
			$item_id = $menu_item->ID;
			$menu_item->cpcm_unfold = get_post_meta($item_id, "_cpcm-unfold", true);
			$menu_item->cpcm_item_count = (int) get_post_meta($item_id, "_cpcm-item-count", true);
			$menu_item->cpcm_orderby = get_post_meta($item_id, "_cpcm-orderby", true);
			$menu_item->cpcm_item_titles = get_post_meta($item_id, "_cpcm-item-titles", true);
		}
		return $menu_item;
	}

	/*
	 * Add input fields to Appearance > Customize (The Theme Customizer)
	 * This is basically the same as cpcm_wp_nav_menu_item_custom_fields, except the mechanism is different
	 */
	function cpcm_wp_nav_menu_item_custom_fields_customize_template()
	{
		$this->get_options();

		?>
		<div class="cpcm-description">
			<p class="field-cpcm-unfold description description-wide">
				<label for="edit-menu-item-cpcm-unfold-{{ data.menu_item_id }}">
					<input type="checkbox" id="edit-menu-item-cpcm-unfold-{{ data.menu_item_id }}"
						class="edit-menu-item-cpcm-unfold" name="menu-item-cpcm-unfold[{{ data.menu_item_id }}]" />

					<?php esc_html_e('Replace by posts', 'category-posts-in-custom-menu'); ?>
				</label>
			</p>
			<div>
				<p class="field-cpcm-item-count description description-thin">
					<label for="edit-menu-item-cpcm-item-count-{{ data.menu_item_id }}">
						<?php esc_html_e('Number of Posts', 'category-posts-in-custom-menu'); ?> (max 10)<br />

						<select id="edit-menu-item-cpcm-item-count-{{ data.menu_item_id }}"
							class="widefat code edit-menu-item-cpcm-item-count"
							name="menu-item-cpcm-item-count[{{ data.menu_item_id }}]">
							<option value="1">1</option>
							<option value="2">2</option>
							<option value="3">3</option>
							<option value="4">4</option>
							<option value="5">5</option>
							<option value="6">6</option>
							<option value="7">7</option>
							<option value="8">8</option>
							<option value="9">9</option>
							<option value="10">10</option>
						</select>
					</label>
				</p>
				<p class="field-cpcm-orderby description description-thin">
					<label for="edit-menu-item-cpcm-orderby-{{ data.menu_item_id }}">
						<?php esc_html_e('Order By', 'category-posts-in-custom-menu'); ?><br />
						<select id="edit-menu-item-cpcm-orderby-{{ data.menu_item_id }}"
							class="widefat edit-menu-item-cpcm-orderby" name="menu-item-cpcm-orderby[{{ data.menu_item_id }}]">
							<option value="none">
								<?php esc_html_e('None', 'category-posts-in-custom-menu'); ?>
							</option>
							<option value="title">
								<?php esc_html_e('Title', 'category-posts-in-custom-menu'); ?>
							</option>
							<option value="date">
								<?php esc_html_e('Date', 'category-posts-in-custom-menu'); ?>
							</option>
							<option value="menu_order">
								<?php esc_html_e('Menu Order', 'category-posts-in-custom-menu'); ?>
							</option>
						</select>
					</label>
				</p>
			</div>

			<p class="field-cpcm-item-titles description description-wide">
				<label for="edit-menu-item-cpcm-item-titles-{{ data.menu_item_id }}">
					<?php esc_html_e('Navigation Label', 'category-posts-in-custom-menu'); ?><br />
					<textarea id="edit-menu-item-cpcm-item-titles-{{ data.menu_item_id }}"
						class="widefat code edit-menu-item-cpcm-item-titles"
						name="menu-item-cpcm-item-titles[{{ data.menu_item_id }}]" rows="4"></textarea>
					<span class="description">
						<?php esc_html_e('The navigation label may be customized using wildcards such as %post_title. See documentation.', 'category-posts-in-custom-menu'); ?>
					</span>
				</label>
			</p>
		</div>
		<?php
	}

	/* 
	 * Add JS for div.cpcm-description and other CPCM fields site-editor.php (Appearance > Editor, the block editor)
	 */
	function cpcm_enqueue_block_editor_assets_js( /* no $hook parameter in this case */)
	{
		wp_enqueue_script(
			'cpcm_enqueue_block_editor_assets_core_navigation_link_js',
			plugins_url('src/blocks/cpcm_core_navigation_link/build/index.js', __FILE__),
			['wp-blocks'],
			self::JS_VERSION,
			true
		);
	} // function
} // class

$cpcm_manager_free = new CPCM_Manager_Free;

// Register the uninstall hook. Should be done after the class has been defined.
register_uninstall_hook(__FILE__, array('CPCM_Manager_Free', 'cpcm_uninstall'));

?>