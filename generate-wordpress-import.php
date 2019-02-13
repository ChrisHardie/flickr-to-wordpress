<?php

/**
 * Generate a WordPress WXR import file from a Flickr photo export.
 * Chris Hardie <chris@chrishardie.com>
 */

// Get config
if ( file_exists( __DIR__ . '/config/config.php' ) ) {
	require_once __DIR__ . '/config/config.php';
} else {
	echo 'No config file found, exiting.' . PHP_EOL;
	exit;
}

// Make sure we have what we need from config file
if ( ! (
	defined( 'PROJECT_DIR' )
	&& defined( 'FLICKR_API_KEY' )
	&& defined( 'MOVE_IMAGES' )
	&& defined( 'WXR_OUTPUT_SIZE_LIMIT' )
) ) {
	echo 'Incomplete config, check config/config.php file.' . PHP_EOL;
	exit;
}

if ( is_dir( PROJECT_DIR ) ) {
	$flickr_data_parent_dir = PROJECT_DIR;
	$flickr_meta_dir        = PROJECT_DIR . '/meta';
	$wp_uploads_dir         = PROJECT_DIR . '/uploads';
	$wp_wxr_dir             = PROJECT_DIR . '/wxr';
} else {
	echo 'The defined project directory does not seem to be valid, exiting.' . PHP_EOL;
	exit;
}

$flickr_user_api_base = 'https://api.flickr.com/services/rest/?method=flickr.people.getInfo&api_key='
	. FLICKR_API_KEY
	. '&format=json&nojsoncallback=1&user_id=';

// Get the paths of the image directories.
// TODO This regex needs help
$flickr_data_directories = glob( $flickr_data_parent_dir . '/data-download-[0-9]' );

// Get the paths of the image meta files.
$flickr_meta_files = glob( $flickr_meta_dir . '/photo_[0-9]*.json' );

// See how many images we might be dealing with.
$image_max_count = count( $flickr_meta_files );

// Set some initial counter values, which will also be used as WordPress record IDs
$post_id = 1;
$attachment_id = (int) ( $post_id + round( $image_max_count + 500, -3 ) );
// Generate the tag IDs as we go, starting with 2 (vanilla WP requires "Uncategorized" w/ID 1)
$tag_id = 2;
$comment_id = 1;

// See "About Origins" in https://github.com/a8cteam51/wordpress-importer-fixers/blob/master/README.md
// But unless you are doing some wacky import cleanup, safe to ignore.
$options = getopt( '', [ 'origin:', 'split' ] );
$origin = ! empty( $options['origin'] ) ? $options['origin'] : '';
if ( empty( $origin ) ) {
	$origin = 'flickrphotos-' . date( 'YmdHis', time() );
}

// Initialize all the things
$warnings = array();
$errors = array();
$all_tags = array();
$all_categories = array();
$post_count = array();
$attachment_count = 0;
$all_attachments = array();
$attachment_ids_by_url = array();
$flickr_user_cache = array();

// Initialize the output that will go into our output files
$content_output = '';
$content_output_array = array();
$tag_output = '';
$attachment_output = '';
$attachment_output_array = array();

// These are fields in the Flickr data structure that we might want to use later.
// For now we'll set them up as post meta fields in each exported post with a prefix indicating their origin.
$fields_to_archive_in_postmeta = array(
	'count_views',
	'count_faves',
	'photopage',
	'original',
	'date_imported',
);

/**
 * ==============================================================================
 */

// For each of the photo file directories we found,
foreach ( $flickr_data_directories as $flickr_data_directory ) {
	// Get all of the files in that directory
	$flickr_files = glob( $flickr_data_directory . '/*.*' );

	// Look for image files
	foreach ( $flickr_files as $flickr_file ) {
		if ( ! preg_match( '/[-_\w+]_(\d+)_o.jpg/', $flickr_file, $matches )
			&& ! preg_match( '/(\d+)_\w+_o.jpg/', $flickr_file, $matches ) ) {
			echo 'File ' . $flickr_file . ' does not appear to be a regular photo, skipping.' . PHP_EOL;
			continue;
		}

		$flickr_id = $matches[ 1 ];

		$wp_image_path = '';
		$image_path_date_filename_part = '';
		$flickr_file_data = '';

		// Construct a pathname for the corresponding meta file
		$meta_file = $flickr_meta_dir . '/photo_' . $flickr_id . '.json';

		// If the meta file exists, read its contents.
		if ( file_exists( $meta_file ) ) {
			$flickr_file_json = file_get_contents( $meta_file );
			$flickr_file_data = json_decode( $flickr_file_json );
		} else {
			echo 'Could not read meta file for ID ' . $flickr_id . ', skipping. ' . PHP_EOL;
			continue;
		}

		// If the meta data looks complete,
		if ( ! empty( $flickr_file_data->date_taken ) && ! empty( $flickr_file_data->original ) ) {

			// Construct a target wp-content/uploads path
			$image_path_date_part = date( 'Y/m', strtotime( $flickr_file_data->date_taken ) );
			$image_path_filename = basename( $flickr_file_data->original );
			if ( ! false == $image_path_date_part ) {
				$image_path_date_filename_part = $image_path_date_part . '/' . $image_path_filename;
				$wp_image_path = $wp_uploads_dir . '/' . $image_path_date_filename_part;
			} else {
				echo $flickr_id . ': Problem converting date taken to image path for WordPress, skipping.' . PHP_EOL;
				continue;
			}

			// If the image exists, we'll skip it.
			if ( file_exists( $wp_image_path ) ) {
				//	echo $flickr_id . ': WP destination file already exists, not trying to create again, skipping. ' . PHP_EOL;
			} else {
				// Create the directory if it doesn't exist.
				if ( ! file_exists( $wp_uploads_dir . '/' . $image_path_date_part ) && is_dir( $wp_uploads_dir ) ) {
					if ( ! ( mkdir( $wp_uploads_dir . '/' . $image_path_date_part, 0777, true ) && is_dir( $wp_uploads_dir . '/' . $image_path_date_part ) ) ) {
						echo 'Problem creating ' . $wp_uploads_dir . '/' . $image_path_date_part . ', skipping image.' . PHP_EOL;
						continue;
					}
				}

				// If we have a good target directory for this image,
				if ( is_dir( $wp_uploads_dir . '/' . $image_path_date_part ) ) {
					// Either move or copy the source Flickr file to the uploads target dir
					if ( true === MOVE_IMAGES ) {
						rename( $flickr_file, $wp_image_path );
					} else {
						copy( $flickr_file, $wp_image_path );
					}
				}
			}
		}

		// Start working on generating the WXR to go with this image.

		// RFC 2822 for pubDate
		$pub_date = date( 'r', strtotime( $flickr_file_data->date_taken ) );
		$post_date = date( 'Y-m-d H:i:s', strtotime( $flickr_file_data->date_taken ) );
		$post_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $flickr_file_data->date_taken ) );

		$post_title = '';
		if ( ! empty( $flickr_file_data->name ) ) {
			$post_title = $flickr_file_data->name;
		} else {
			$post_title = $flickr_id;
		}

		$post_slug = _slugify( $post_title );

		$taxonomy_content = '';
		$comment_content = '';

		// Generate the tag WXR syntax for this item
		foreach ( $flickr_file_data->tags as $tag ) {

			$tag_slug         = _slugify( $tag->tag );

			$taxonomy_content .= sprintf( '<category domain="post_tag" nicename="%s"><![CDATA[%s]]></category>',
				$tag_slug,
				$tag->tag
			);

			// And also add the tag to our global array of all tags for later processing
			$all_tags[ $tag_slug ] = $tag->tag;

		}

		// Might be nice to make these config vars.
		$post_type = 'post';
		$post_format = 'image';
		$post_status = 'publish';
		$post_thumbnail_id = '';

		// If a post format is defined, it means it's something other than "Standard"
		if ( ! empty( $post_format ) ) {

			$post_format_pretty = ucfirst( $post_format );
			$taxonomy_content .= sprintf(
				'<category domain="post_format" nicename="post-format-%s"><![CDATA[%s]]></category>',
				$post_format,
				$post_format_pretty
			);

		}

		// If the photo wasn't public on Flickr, make it private in WordPress.
		if ( ! empty( $flickr_file_data->privacy ) && 'public' !== $flickr_file_data->privacy ) {
			$post_status = 'private';
		}

		// Generate the post meta WXR syntax from a few sources:
		// Old Flickr fields we want to archive - prefix with an underscore to hide in the Custom Fields admin
		$postmeta_content = '';
		foreach ( $fields_to_archive_in_postmeta as $field ) {
			if ( ! empty( $flickr_file_data->$field ) && ( 'null' !== $flickr_file_data->$field ) ) {
				$postmeta_content .= _generate_postmeta_content( '_flickr_' . $field, $flickr_file_data->$field );
			}
		}

		// Exif
		if ( ! empty( $flickr_file_data->exif ) ) {
			$postmeta_content .= _generate_postmeta_content( '_flickr_exif', serialize( (array) $flickr_file_data->exif ) );
		}

		// Albums
		if ( ! empty( $flickr_file_data->albums ) ) {
			$album_ids = array();
			foreach ( $flickr_file_data->albums as $album ) {
				$album_ids[] = $album->id;
			}
			$postmeta_content .= _generate_postmeta_content( '_flickr_album_ids', serialize( $album_ids ) );
		}

		// Comments
		$comment_content = '';
		if ( ! empty( $flickr_file_data->comments ) ) {
			// The Flickr meta files don't come with commenter user names, so we fetch them from the API.
			foreach( $flickr_file_data->comments as $comment ) {
				$flickr_user_api_url = $flickr_user_api_base . $comment->user;
				$flickr_user_json = file_get_contents( $flickr_user_api_url );

				$flickr_user = array();

				// Cache user data to avoid duplicate API requests
				if ( ! empty( $flickr_user_cache[$comment->user] ) ) {
					$flickr_user = $flickr_user_cache[$comment->user];
				} else {
					$flickr_user_api_url = $flickr_user_api_base . $comment->user;
					$flickr_user_json = file_get_contents( $flickr_user_api_url );
					$flickr_user_decoded = json_decode( $flickr_user_json );
					if ( ! empty( $flickr_user_decoded->person ) ) {
						$flickr_user = $flickr_user_decoded->person;
						$flickr_user_cache[$comment->user] = $flickr_user;
					}
				}

				if ( ! empty( $flickr_user->realname->_content ) ) {
					$commenter_name = $flickr_user->realname->_content;
				} elseif ( ! empty( $flickr_user->username->_content ) ) {
					$commenter_name = $flickr_user->username->_content;
				} else {
					$commenter_name = 'Flickr User';
				}

				if ( ! empty( $flickr_user->photosurl->_content ) ) {
					$commenter_url = $flickr_user->photosurl->_content;
				} else {
					$commenter_url = '';
				}

				$comment_date = date( 'Y-m-d H:i:s', strtotime( $comment->date ) ) ;

				$comment_content .= sprintf( '<wp:comment>
    <wp:comment_id>%d</wp:comment_id>
    <wp:comment_author><![CDATA[%s]]></wp:comment_author>
    <wp:comment_author_email/>
    <wp:comment_author_url>%s</wp:comment_author_url>
    <wp:comment_author_IP/>
    <wp:comment_date>%s</wp:comment_date>
    <wp:comment_date_gmt>%s</wp:comment_date_gmt>
    <wp:comment_content><![CDATA[%s]]></wp:comment_content>
    <wp:comment_approved>1</wp:comment_approved>
    <wp:comment_type/>
    <wp:comment_parent>0</wp:comment_parent>
    <wp:comment_user_id>0</wp:comment_user_id>
  </wp:comment>',
					$comment_id,
					$commenter_name,
					$commenter_url,
					$comment_date,
					$comment_date,
					$comment->comment
					);

				$comment_id++;
			}
		}

		// Define what we want in the media library
		$new_attachment_options = array(
			'filename'          => $image_path_filename,
			'source_url'        => $flickr_file_data->original,
			'publish_date'      => $pub_date,
			'parent_post_id'    => $post_id,
		);

		// Add the attachment and get the resulting ID
		$post_thumbnail_id = _add_attachment( $new_attachment_options );

		// Make the image the Featured Image for the post.
		if ( ! empty( $post_thumbnail_id ) ) {
			$postmeta_content .= _generate_postmeta_content( '_thumbnail_id', $post_thumbnail_id );
			$postmeta_content .= _generate_postmeta_content( '_original_thumbnail_id', $post_thumbnail_id );
		}

		// If we've reached the max size of a single content/post output file,
		// push this output string to the all-output array, and start with a new one
		if ( WXR_OUTPUT_SIZE_LIMIT <= strlen( $content_output ) ) {
			$content_output_array[] = $content_output;
			$content_output = '';
		}


		// Generate the main WXR item for this post
		$content_output .= sprintf( "<item>
	<title>%s</title>
	<link>%s</link>
	<description></description>
	<guid isPermaLink=\"false\">%s</guid>
	<pubDate>%s</pubDate>
	<dc:creator><![CDATA[%s]]></dc:creator>
	<excerpt:encoded><![CDATA[]]></excerpt:encoded>
	<content:encoded><![CDATA[%s]]></content:encoded>
	<wp:post_name>%s</wp:post_name>
	<wp:post_id>%d</wp:post_id>
	<wp:post_date_gmt>%s</wp:post_date_gmt>
	<wp:post_parent>0</wp:post_parent>
	%s
	<wp:post_type><![CDATA[%s]]></wp:post_type>
	<wp:is_sticky>0</wp:is_sticky>
	%s
	<wp:ping_status><![CDATA[closed]]></wp:ping_status>
	<wp:post_date>%s</wp:post_date>
	<wp:comment_status><![CDATA[open]]></wp:comment_status>
	<wp:menu_order>0</wp:menu_order>
	<wp:status><![CDATA[%s]]></wp:status>
	%s
</item>
",
			$post_title,
			$flickr_file_data->original,
			$flickr_file_data->original,
			$pub_date,
			'admin',
			$flickr_file_data->description,
			$post_slug,
			$post_id,
			$post_date_gmt,
			$taxonomy_content,
			$post_type,
			$postmeta_content,
			$post_date,
			$post_status,
			$comment_content
		);

		$post_id++;
		@$post_count[ $post_type ] ++;

	}
}

// Make sure we push the last content output string to the all-output array
$content_output_array[] = $content_output;

// Sorted by tag slug just looks nicer
ksort( $all_tags );

// For each unique tag as a key/value pair...
foreach ( $all_tags as $tag_slug => $tag ) {

	if ( empty( $tag_slug ) || empty( $tag ) ) {
		continue;
	}

	// ...generate the tag WXR item
	$tag_output .= sprintf( '<wp:term><wp:term_id>%d</wp:term_id><wp:term_taxonomy>post_tag</wp:term_taxonomy><wp:term_slug>%s</wp:term_slug><wp:term_name><![CDATA[%s]]></wp:term_name></wp:term>',
			$tag_id,
			$tag_slug,
			$tag
		) . "\n";

	$tag_id++;

}

// Put the whole WXR output together
$wxr_header = <<<WXRHEADER
<?xml version="1.0" encoding="UTF-8"?>

<rss version="2.0"
 xmlns:blogChannel="http://backend.userland.com/blogChannelModule"
 xmlns:content="http://purl.org/rss/1.0/modules/content/"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
 xmlns:wfw="http://wellformedweb.org/CommentAPI/"
 xmlns:wp="http://wordpress.org/export/1.2/"
>

<channel>
<title>Flickr Photos</title>
<link>https://photos.test</link>
<description>Flickr Photos</description>
<language>en-US</language>
<pubDate>Tue, 29 Jan 2019 10:35:00 +0000</pubDate>
<generator>https://wordpress.org/?v=5.0</generator>
<wp:base_site_url>https://photos.test/</wp:base_site_url>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:base_blog_url>https://photos.test/</wp:base_blog_url>

WXRHEADER;

$wxr_footer = <<<WXRFOOTER
</channel>
</rss>

WXRFOOTER;

$attachment_output_array = _generate_attachment_wxr( $all_attachments );

$author_wxr_output = <<<WXRAUTHOR
<wp:author>
        <wp:author_id>1</wp:author_id>
        <wp:author_login><![CDATA[admin]]></wp:author_login>
        <wp:author_email><![CDATA[admin@example.com]]></wp:author_email>
        <wp:author_display_name><![CDATA[Admin]]></wp:author_display_name>
        <wp:author_first_name><![CDATA[Admin]]></wp:author_first_name>
        <wp:author_last_name><![CDATA[Admin]]></wp:author_last_name>
</wp:author><wp:author>
WXRAUTHOR;
;

// Let's remove any existing WXR and CSV files so we don't confuse ourselves across multiple runs.
echo 'Cleaning up old export files, if they exist.' . "\n";
array_map( 'unlink', glob( "$wp_wxr_dir/*.{wxr,csv}", GLOB_BRACE ) );

// Time to generate some files
if ( ! isset( $options['split'] ) ) {

	file_put_contents( $wp_wxr_dir . '/000-combined.wxr',
		$wxr_header
		. $author_wxr_output
		. $tag_output
		. implode( $content_output_array )
		. implode( $attachment_output_array )
		. $wxr_footer
	);

} else {

	// One file per type of exported data
	file_put_contents( $wp_wxr_dir . '/001-authors.wxr',
		$wxr_header
		. $author_wxr_output
		. $wxr_footer
	);

	file_put_contents( $wp_wxr_dir . '/002-tags.wxr',
		$wxr_header
		. $tag_output
		. $wxr_footer
	);

	$content_file_count = 1;
	foreach( $content_output_array as $content_output_string ) {
		$output_filename = $wp_wxr_dir . '/003-posts-' . sprintf( '%03d', $content_file_count ) . '.wxr';

		file_put_contents(
			$output_filename,
			$wxr_header
			. $content_output_string
			. $wxr_footer
		);

		$content_file_count++;
	}

	$attachment_file_count = 1;
	foreach( $attachment_output_array as $attachment_output_string ) {
		$output_filename = $wp_wxr_dir . '/004-attachments-' . sprintf( '%03d', $attachment_file_count ) . '.wxr';

		file_put_contents(
			$output_filename,
			$wxr_header
			. $attachment_output_string
			. $wxr_footer
		);

		$attachment_file_count++;
	}
}

// Output some stats
$final_tag_count = $tag_id - 1;
echo sprintf( 'Exported %s, %s tags, %s comments and %s attachment(s).',
		implode( ', ', array_map(
			function( $k, $v ) { return number_format( $v ) . " $k(s)"; },
			array_keys( $post_count ),
			array_values( $post_count )
		)),
		number_format( $final_tag_count ),
		number_format( $comment_id - 1 ),
		number_format( $attachment_count )
	) . "\n";

exit;

/**
 * Generate a crude WordPress-friendly slug from a string of text
 * Based on https://core.trac.wordpress.org/browser/tags/4.8/src//wp-includes/formatting.php#L1876
 * @param $_text
 *
 * @return string
 */
function _slugify( $_text ) {

	$_slug = strtolower( $_text ); // convert to lowercase
	$_slug = preg_replace( '/\s+/', '-', $_slug ); // convert all contiguous whitespace to a single hyphen
	$_slug = preg_replace( '/[^a-z0-9_\-]/', '', $_slug ); // Lowercase alphanumeric characters, dashes and underscores are allowed.
	$_slug = preg_replace( '/-+/', '-', $_slug ); // convert multiple contiguous hyphens to a single hyphen

	return $_slug;
}

// Store the attachment in a global array so we can generate WXR with integrity later.
function _add_attachment( $new_attachment ) {

	global $all_attachments, $attachment_id, $attachment_ids_by_url;

	// If media URL has already been used, re-use that attachment
	if ( ! empty( $new_attachment['source_url'] ) && ! empty( $attachment_ids_by_url[ $new_attachment['source_url'] ] ) ) {
		$new_attachment['attachment_id'] = $attachment_ids_by_url[ $new_attachment['source_url'] ];
	} else {
		$new_attachment['attachment_id'] = $attachment_id;

		$all_attachments[] = $new_attachment;
		$attachment_ids_by_url[ $new_attachment['source_url'] ] = $attachment_id;

		$attachment_id++;
	}

	return $new_attachment['attachment_id'];

}

// Utility to return a postmeta field string so we don't repeat it throughout the main logic
function _generate_postmeta_content ( $meta_key = '', $meta_value = '' ) {

	if ( empty( $meta_key ) ) {
		return false;
	}

	$postmeta_content = sprintf( '<wp:postmeta><wp:meta_key><![CDATA[%s]]></wp:meta_key><wp:meta_value><![CDATA[%s]]></wp:meta_value></wp:postmeta>',
		$meta_key,
		$meta_value
	);

	return $postmeta_content;

}

// Generate all of the attachment WXR based on the array that's been generated using _add_attachment()
function _generate_attachment_wxr( $all_attachments = array() ) {

	global $attachment_count;
	global $output_size_limit;
	global $origin;
	$attachment_output = '';
	$attachment_output_array = array();

	foreach ( $all_attachments as $attachment ) {

		unset( $attachment_id, $title, $source_url, $publish_date, $filename, $parent_post_id, $excerpt );

		/**
		 * @var int $attachment_id
		 * @var string $title
		 * @var string $source_url
		 * @var string $publish_date
		 * @var string $filename
		 * @var int $parent_post_id
		 * @var string $excerpt
		 */
		extract( $attachment, EXTR_OVERWRITE );

		// If we don't have a valid attachment_id and source_url, something went wrong somewhere
		if ( empty( $attachment_id ) || ! is_int( $attachment_id )
			|| empty( $source_url ) || empty( $filename ) ) {
			echo 'Do not have everything we need to generate this attachment, skipping.' . PHP_EOL;
			continue;
		}

		// Create an attachment slug
		// Some attachments with the same filename and same publish date actually have different URLs. :(
		// So we need to make sure each filename is unique.
		$file_slug = _slugify( preg_replace( '/\\.[^.\\s]{3,4}$/', '', $filename ) . '-' . $attachment_id );

		// Preserve the original import URL for possible later import fixup
		$postmeta_content = _generate_postmeta_content( '_original_import_url', $source_url );

		// Add some more meta fields to help with possible later import fixup
		$postmeta_content .= _generate_postmeta_content( '_original_post_id', $attachment_id );

		if ( ! empty( $origin ) ) {
			$postmeta_content .= _generate_postmeta_content( '_original_import_origin', $origin );
		}

		// If we've reached the max size of a single content/post output file,
		// push this output string to the all-output array, and start with a new one
		if ( WXR_OUTPUT_SIZE_LIMIT <= strlen( $attachment_output ) ) {
			$attachment_output_array[] = $attachment_output;
			$attachment_output = '';
		}

		// Generate the attachment WXR item
		$attachment_output .= sprintf( '<item>
		<title>%s</title>
		<link>%s</link>
		<description></description>
		<guid isPermaLink=\"false\">%s</guid>
		<pubDate>%s</pubDate>
		<dc:creator><![CDATA[admin]]></dc:creator>
		<content:encoded><![CDATA[]]></content:encoded>
		<content:excerpt><![CDATA[%s]]></content:excerpt>
		<wp:is_sticky>0</wp:is_sticky>
		<wp:post_type>attachment</wp:post_type>
		<wp:post_parent>%d</wp:post_parent>
		<wp:post_date_gmt>%s</wp:post_date_gmt>
		<wp:post_name>%s</wp:post_name>
		<wp:post_id>%d</wp:post_id>
		<wp:status><![CDATA[publish]]></wp:status>
		<wp:attachment_url>%s</wp:attachment_url>
		%s
		<wp:comment_status><![CDATA[closed]]></wp:comment_status>
		<wp:menu_order>0</wp:menu_order>
		<wp:post_date>%s</wp:post_date>
		<wp:ping_status><![CDATA[closed]]></wp:ping_status>
	</item>
	',
			empty( $title ) ? $file_slug : $title,
			$source_url,
			$source_url,
			$publish_date,
			empty( $excerpt ) ? '' : $excerpt,
			empty( $parent_post_id ) ? 0 : $parent_post_id,
			date( 'Y-m-d H:i:s', strtotime( $publish_date ) ),
			$file_slug,
			$attachment_id,
			$source_url,
			$postmeta_content,
			date( 'Y-m-d H:i:s', strtotime( $publish_date ) )
		);

		$attachment_count++;

	}

	// Make sure we push the last attachment output string to the all-output array
	$attachment_output_array[] = $attachment_output;

	return $attachment_output_array;

}
