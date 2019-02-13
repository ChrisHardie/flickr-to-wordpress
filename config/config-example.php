<?php

/**
 * Config file for Flickr to WordPress migration
 */

// The main directory where the Flickr export files are stored, and where WordPress import files will be generated
// No trailing slash
define( 'PROJECT_DIR', '/your/path/here' );

// A valid Flickr API key, generated at https://www.flickr.com/services/apps/create/apply/
define( 'FLICKR_API_KEY', 'your_key_here' );

// Should the tool move the Flickr images into the WordPress uploads directory (true), or just copy them (false)?
define( 'MOVE_IMAGES', false );

// What's the max file size in bytes of the generated WXR files, assuming they aren't combined into one?
define( 'WXR_OUTPUT_SIZE_LIMIT', 2097152 );
