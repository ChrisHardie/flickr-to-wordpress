# Flickr Export to WordPress Import

## About

This tool will help you use the official Flickr account files to generate files that can then be imported into WordPress.

This is not a "one click" solution. It requires some manual configuration and general comfort with working on the command line. It may not work for your particular Flickr account or WordPress setup without some changes. It may not work at all.

## Architecture

I designed this tool to facilitate this basic approach:

1. Download all photo files and metadata from Flickr.
2. Use the downloaded information to construct a valid WXR import file and a valid WordPress `uploads` directory.
3. Move the `uploads` directory in to place and import the WXR file in to WordPress.
4. End up with a complete WordPress media library and set of posts, one for each Flickr image.

(The resulting site should have your photos, photo meta data, tags and comments. Some key meta values will also be archived in WordPress post meta for later use, e.g. if you want to find/replace references to Flickr URLs in content on another site to use your new WordPress URLs instead.)

This is probably not the best way to do this, but it was the right way for me. 

Yes, there are an increasing number of tools that make use of the perfectly good Flickr API to access and download photos for use in other environments. In particular the [Keyring Social Importers plugin](https://wordpress.org/plugins/keyring-social-importers/) is a pretty robust way to bring in existing and new Flickr content to WordPress via the Flickr API.

## Requirements and Limitations

For this tool to be useful to you, you'll need:

* Access to [your Flickr account](https://www.flickr.com/account), where you should initiate a request to make a full export of all your Flickr data available, and then download all of the resulting zip files for both the "Account Data" and for the "Photos and Videos".
* A [Flickr API key](https://www.flickr.com/services/apps/create/apply/) (if you want to include commenter names in your WordPress site)
* A local command line environment with PHP installed, the [jhead image manipulation tool](http://www.sentex.net/~mwandel/jhead/), and enough disk space to work with an extracted archive of your Flickr photos, and possibly _two copies_ of that data. I did all this on macOS. (You can get a good idea of how much space that is by going to "You -> Stats" on Flickr and seeing what it says you're using there.) 
* A fresh install of WordPress where you have the ability to run WP CLI commands and to directly upload (probably via SFTP) and manipulate files on the local filesystem.
* A little patience, as there are a number of steps involved.

There are some known limitations with this tool:

* No support for migrating videos or other non-JPG files. Nothing technical preventing this, I just didn't bother yet. The script will output a list of files that need to be handled manually.

### Image Rotation Note

The [jhead image manipulation tool](http://www.sentex.net/~mwandel/jhead/) is listed as a requirement because I could not find an easy way to get Flickr's interaction with image orientation data to reliably translate into WordPress's interaction with image orientation data. The end result was images that were rotated on their sides or upside down.

The easiest solution I could find was to do a one-time fix on the source image files themselves before beginning to import them in to WordPress.

If you're on a Mac and using Brew, setup should be as easy as `brew install jhead`. 

See instructions below for actual image rotation commands.

You may also wish to consider installing the [Fix Image Rotation plugin](https://wordpress.org/plugins/fix-image-rotation/) or similar on your site for future photo uploads; there are still known issues with how rotated images shared from (for example) iOS devices are handled in WordPress.

## Installing and Using

### Generating the WordPress Import

1. Create a working directory for this effort somewhere in your local environment and `cd` to it.
2. Clone this repo using `git clone git@github.com:ChrisHardie/flickr-to-wordpress.git`.
3. Move your zip files downloaded from Flickr in to the working directory and extract them. Leave the photo file directories named as they are (e.g. `data-download-1`) but rename the directory containing the meta data (one JSON file per photo, among other things) to a sub-directory called just `meta`.
4. Create a few more output sub-directories: `mkdir wxr uploads`;
5. In the `config/` directory, copy `example-config.php` to `config.php` and edit the file to fill in the variables defined there. 
6. So, now your directory structure should look something like:

        /flickr-archive/
            /config/config.php
            /generate-wordpress-import.php
            /README.md
            /meta/
                photo_1234.json
                photo_5678.json
                ...
            /wxr/
            /uploads/
            /data-download-1/
                image_1234.jpg
                image_4567.jpg
            /data-download-2/
                image_8910.jpg
                ...
            ...
            
7. Do a one-time rotation of any images needing it. Find the highest numbered data directory containing image files, and then execute this shell command replacing 4 with that highest number:     
    ```for i in {1..4}; do jhead -autorot data-download-$i/*.jpg; done```.
8. Run the import generation script: `php generate-wordpress-import.php`.
9. Confirm that there is a WXR file in the `wxr/` output directory, and that there is a fully formed `uploads/` folder with year and month sub-directories containing images.

If you run into issues with the size of the generated WXR, the script supports the `--split` argument, which will output multiple smaller WXR files instead of one big one.

### Getting Everything Into WordPress

You should now have everything you need to populate your empty WordPress instance with your photos and metadata. Here are the steps I followed for that:

1. Transfer the generated `uploads/` sub-directory into your WordPress instance's `wp-content/` directory. I did this by running `tar -czvf photos-uploads.tgz uploads/` and then using `scp` to copy the tar file to my web server. Once it was there, I untarred it into `wp-content`.
2. Transfer the generated `000-combined.wxr` file to a working directory somewhere accessible from the WordPress instance. 
3. Install and activate the WordPress Importer plugin: `wp plugin install wordpress-importer --activate`
4. Temporarily patch the WordPress Importer plugin to prevent the remote fetch of images mentioned in the import file. I did this by applying an existing Pull Request that has not yet been merged into that plugin:
    ```
    cd wp-content/plugins/wordpress-importer/
    curl -LO https://github.com/WordPress/wordpress-importer/pull/39.patch
    patch < 39.patch
    rm 39.patch
    ```
5. Enable the patch just applied by adding `define( 'IMPORT_PREVENT_REMOTE_FETCH', true );` to your wp-config.php file.
6. Temporarily disable the generation of multiple image sizes during import by adding `add_filter( 'intermediate_image_sizes_advanced', '__return_false' );` to your theme's functions.php file. This will make the initial import much faster.
7. Run the import, using something like `wp import 000-combined.wxr --authors=skip`. You may need to specify a different path to your WXR file depending on where you put it. You may wish to pipe the output to a file for later examination in case of problems. When the import finishes run `wp cache flush`.
8. Test that the import was successful. Visit the posts list, tag list, media library and the site itself to confirm that everything works as expected. Note: the site will not yet have smaller image sizes generated, so things may load slowly until that part is done in the next step.
9. Delete and reinstall the WordPress Importer plugin, and the related wp-config.php entry, if you want to remove the patched functionality.
10. Remove the `add_filter( 'intermediate_image_sizes_advanced', '__return_false' );` line from your functions.php file. Generate other image sizes using `wp media regenerate --only-missing --yes`. This could take quite a while depending on how many images you have and the resources available on your web server.

That's it! You should now have a self-contained copy of your Flickr photos in WordPress!

## Questions and Bugs

Since I only tested this process on my own Flickr account, I'm 99% sure that it will fall apart in some way for other people. If you open an Issue with as many details as possible, I will do my best to offer thoughts on how to further improve this tool to help you. Better yet, if you find a way to make it more broadly useful, or even just to make this documentation more clear, please submit a Pull Request!

## Author

* Chris Hardie
* https://chrishardie.com/
* https://photos.chrishardie.com/
