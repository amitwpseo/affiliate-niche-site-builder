<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in redirects from the previous site. Generate this file with scripts/redirect_map.py, review it, then paste.
 * 'rules': each old path => new path (301) or code 410 for pages that are gone with no equivalent.
 * 'same' : old paths that keep the same address on the new site (no rule needed; listed for the record).
 */
return array(
	'rules' => array(
		// array( 'from' => '/old-page/', 'to' => '/new-page/', 'code' => 301, 'note' => 'Same topic' ),
		// array( 'from' => '/old-tool/', 'to' => null, 'code' => 410, 'note' => 'Removed, no equivalent' ),
	),
	'same'  => array(),
);
