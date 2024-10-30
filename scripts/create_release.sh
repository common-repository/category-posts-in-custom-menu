#!/bin/bash
# Template file: https://unix.stackexchange.com/a/505342

helpFunction()
{
   echo ""
   echo "Usage: $0 -v version"
   echo "Exmaple: $0 -v 2.0.0"
   echo -e "\t-v The new version"
   exit 1 # Exit script after printing help
}

while getopts "v:s:" opt
do
   case "$opt" in
      v ) version="$OPTARG" ;;
      ? ) helpFunction ;; # Print helpFunction in case parameter is non-existent
   esac
done

# Print helpFunction in case parameters are empty
if [ -z "$version" ]
then
   echo "Some or all of the parameters are empty";
   helpFunction
fi

# Begin script in case all parameters are correct
# Create tags folder for SVN commit
DIR="../../tags/$version"
mkdir -p $DIR

# Copy only the required files
cp -r ../src/ $DIR/src/
cp ../category-posts-in-custom-menu.php $DIR/category-posts-in-custom-menu.php
cp ../readme.txt $DIR/readme.txt
cp ../screenshot-1.png $DIR/screenshot-1.png
cp ../screenshot-2.png $DIR/screenshot-2.png
cp ../screenshot-3.png $DIR/screenshot-3.png
