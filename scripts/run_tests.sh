#!/bin/bash
# Template file: https://unix.stackexchange.com/a/505342

helpFunction()
{
   echo ""
   echo "Usage: $0 -v vvvroot"
   echo "Exmaple: $0 -v C:/Users/<user>/WP-CPCM/VVV/ -s wordpress-php74"
   echo -e "\t-v Full path to the VVV root directory"
   echo -e "\t-s VVV site to run the tests against. E.g. wordpress-php74"
   exit 1 # Exit script after printing help
}

while getopts "v:s:" opt
do
   case "$opt" in
      v ) vvvroot="$OPTARG" ;;
      s ) site="$OPTARG" ;;
      ? ) helpFunction ;; # Print helpFunction in case parameter is non-existent
   esac
done

# Print helpFunction in case parameters are empty
if [ -z "$vvvroot" ] || [ -z "$site" ]
then
   echo "Some or all of the parameters are empty";
   helpFunction
fi

# Begin script in case all parameters are correct
# Enter VVV root directory. Vagrant must be running to run tests
cd "$vvvroot"

vagrant ssh << EOF
cd /srv/www/$site
cd public_html/wp-content/plugins/category-posts-in-custom-menu-premium/
./phpunit tests/cpcm_replace_dates.test.php
./phpunit tests/cpcm_replace_placeholders.test.php
./phpunit tests/cpcm_replace_taxonomy_by_posts.test.php
./phpunit tests/cpcm_wp_nav_menu_item_custom_fields.test.php
exit
EOF

echo "$vvvroot"
echo "$site"