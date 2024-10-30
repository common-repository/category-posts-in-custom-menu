<?php

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

class CPCM_Functions_Free
{
	function cpcm_replace_dates($post, $string)
	{
		if (!isset($post) || !isset($string) || empty($string)) {
			return "";
		}
	
		$format_pattern = "([a-zA-Z\s\\\\:\/,]*)";
	
		// PHP 5.3 and upwards compatible, use preg_replace_callback for regular expressions with /e parameter instead of preg_replace
		// http://wordpress.org/support/topic/php-55-preg_replace-e-modifier-depricated?replies=1
		$post_date_gmt = $post->post_date_gmt;
		$string = preg_replace("/\%post_date_gmt\(\)/", mysql2date('F jS, Y', $post_date_gmt), $string);
		$callback =
			function ($matches) use ($post_date_gmt) {
				return mysql2date($matches[1], $post_date_gmt);
			};
		$string = preg_replace_callback("/\%post_date_gmt\(" . $format_pattern . "\)/", $callback, $string);
		$string = str_replace("%post_date_gmt", $post_date_gmt, $string);
	
		$post_date = $post->post_date;
		$string = preg_replace("/\%post_date\(\)/", mysql2date('F jS, Y', $post_date), $string);
		$callback =
			function ($matches) use ($post_date) {
				return mysql2date($matches[1], $post_date);
			};
		$string = preg_replace_callback("/\%post_date\(" . $format_pattern . "\)/", $callback, $string);
		$string = str_replace("%post_date", $post_date, $string);
	
		return $string;
	}
	

	function cpcm_replace_placeholders($post, $string)
	{
		$string = wp_strip_all_tags($string, true);

		$userdata = get_userdata($post->post_author);
		$string = str_replace("%post_author", $userdata ? $userdata->data->display_name : '', $string);
		$string = str_replace("%post_title", $post->post_title, $string);

		$string = $this->cpcm_replace_dates($post, $string);

		// Remove remaining %post_ occurrences.
		$pattern = "/" . "((\((?P<lbrack>(\S*))))?" . "\%post_[-\w]*(?P<brackets>(\(((?P<inner>[^\(\)]*)|(?P>brackets))\)))" . "(((?P<rbrack>(\S*))\)))?" . "/";
		$string = preg_replace($pattern, '', $string);

		$pattern = "/%post_[-\w]*(?P<brackets>(\(((?P<inner>[^\(\)]*)|(?P>brackets))\)))?/";
		$string = preg_replace($pattern, '', $string);

		$pattern = "/%post_[-\w]*(\([-\w]*\))?/";
		$string = preg_replace($pattern, '', $string);

		return $string;
	}

	function cpcm_replace_taxonomy_by_posts(&$result, $menu_item, $posts, $menu_item_parent_map)
	{
		$posts_count = count($posts);
		$delta_posts = 0;

		// Remove original menu item
		if (empty($posts)) {
			$delta_posts -= 1;
			$menu_item_parent_map[$menu_item->db_id] = $menu_item->menu_item_parent;
		} else if ($posts_count == 1) {
			// If the menu-item should be removed, but it has exactly one post, then use this post as new parent for any menu items down the line.
			// Because we can't use posts as menu items (they don't have a db_id), reuse the menu_item object and transfer the post properties to the menu_item in the foreach loop
			// See {note 1} in foreach
			array_push($result, $menu_item);
		} else {
			$delta_posts -= 1;
			$menu_item_parent_map[$menu_item->db_id] = $menu_item->menu_item_parent;
		}

		if ($posts_count == 0) {
			return $delta_posts;
		}

		// Set the menu_item_parent for the menu_item: If the parent item was removed, go up a level
		$current_parent_id = $menu_item->menu_item_parent;
		while (array_key_exists(strval($current_parent_id), $menu_item_parent_map) == 1) {
			$current_parent_id = $menu_item_parent_map[$current_parent_id];
		}
		$menu_item->menu_item_parent = $current_parent_id;

		foreach ((array) $posts as $pkey => $post) {
			$post = wp_setup_nav_menu_item($post);

			// Set the menu_item_parent for the post: If the parent item was removed, go up a level
			$current_parent_id = $menu_item->db_id;
			while (array_key_exists(strval($current_parent_id), $menu_item_parent_map) == 1) {
				$current_parent_id = $menu_item_parent_map[$current_parent_id];
			}
			$post->menu_item_parent = $current_parent_id;

			// Transfer properties from the old menu item to the new one
			$post->target = $menu_item->target;
			//$post->classes = $menu_item->classes; // Don't copy the classes, because this will also copy the 'active' CSS class to all siblings of the selected menu item. http://wordpress.org/support/topic/active-css-class
			$post->classes = array_merge($post->classes, (array) get_post_meta($menu_item->db_id, "_menu_item_classes", true)); // copy custom css classes that the user specified under "CSS Classes (optional)"

			$post->xfn = $menu_item->xfn;
			$post->description = $menu_item->description;

			// Set the title of the new menu item
			$post->title = get_post_meta($menu_item->db_id, "_cpcm-item-titles", true);

			// Replace the placeholders in the title by the properties of the post
			$post->title = $this->cpcm_replace_placeholders($post, $post->title);

			$delta_posts += 1;

			$post->menu_order = $menu_item->menu_order + $delta_posts;
		}

		// Solve https://wordpress.org/support/topic/works-with-41-as-far-as-i-can-tell?replies=5, regenerate all classes for the posts, and copy those classes to the menu_item that we're reusing.
		// Extend the items with classes.
		_wp_menu_item_classes_by_context($posts);

		if (!isset($menu_item->classes)) {
			$menu_item->classes = array();
		}

		// Decorate the posts with the required data for a menu-item.
		if ($posts_count == 1) {
			// {note 1}
			// Do not use the post, but re-use the menu item instead.
			$menu_item->title = get_post_meta($menu_item->db_id, "_cpcm-item-titles", true);
			$menu_item->title = $this->cpcm_replace_placeholders($post, $menu_item->title);
			$menu_item->url = get_permalink($post->ID);
			array_merge($menu_item->classes, $posts[0]->classes);
		} else {
			// Append the new menu_items to the menu array that we're building.
			$result = array_merge($result, $posts);

			if ($posts_count > 0) {
				// Fix for https://wordpress.org/support/topic/not-working-on-mobile-devices-16/ and https://wordpress.org/support/topic/mobile-sub-menu-3/
				// Ensure that menu items that has more than one child receives the correct CSS classes
				// Apparently the calls to _wp_menu_item_classes_by_context do not ensure this.
				$menu_item->classes[] = 'menu-item-has-children';
			}
		}

		return $delta_posts;
	}

	function cpcm_block_editor_replace_taxonomy_by_posts(&$result_parent, &$original_parent, &$original_item, $posts, $menu_item_parent_map)
	{
		$get_attribute_value = fn($i, $k, $def) => is_array($i) && array_key_exists("attrs", $i) && array_key_exists($k, $i["attrs"]) ? $i["attrs"][$k] : $def;

		$result_item = array_merge($original_item, array("innerBlocks" => array()));

		$id = $get_attribute_value($original_item, "id", null);
		$cpcm_remove_original_item = $get_attribute_value($original_item, "cpcm_remove_original_item", "always");
		$cpcm_item_titles = $get_attribute_value($original_item, "cpcm_item_titles", "%post_title");

		// Decide whether the original item needs to be preserved.
		switch ($cpcm_remove_original_item) {
			case "always":
				$menu_item_parent_map[$id] = &$parent_menu_item;
				$result_item = &$original_parent;
				break;
			case "only if empty":
				if (empty($posts)) {
					$menu_item_parent_map[$id] = &$parent_menu_item;
					$result_item = &$original_parent;
				} else {
					$result_parent["innerBlocks"][] = &$result_item;
				}
				break;
			case "never":
				$result_parent["innerBlocks"][] = &$result_item;
				break;
		}

		$posts_count = count($posts);
		if ($posts_count == 0) {
			return;
		}

		foreach ((array) $posts as $pkey => $post) {
			$child = array(
				"blockName" => 'core/navigation-link',
				"attrs" => array(
					"type" => "post",
					"kind" => "post-type",
					"id" => $post->ID,
					// Set the label of the new menu item. Replace the placeholders in the title by the properties of the post
					"label" => $this->cpcm_replace_placeholders($post, $cpcm_item_titles),
					"url" => $post->guid, // First permalink is stored as guid
					// Skip className attribute for now. (See classic menu implementation for caveats)
					"className" => $get_attribute_value($original_item, "className", '')
				),
				"innerBlocks" => array()
			);

			$result_item["innerBlocks"][] = $child;
		}
		$result_item["blockName"] = 'core/navigation-submenu';

		return $result_item;
	}
}

?>