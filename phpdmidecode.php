<?php

// Simple Function to return the output of dmidecode as an arry.
// Copyright 2015 Rob Thomas <xrobau@gmail.com>
//
// Released under the AGPLv3+
// See the LICENCE file in this repository for further information.
// Source repository is https://github.com/xrobau/php-dmidecode

// This also demonstrates a simple and secure way of writing it
// to a temporary file on the filesystem, for use by other services
// that are NOT root-owned.
$out = "/tmp/dmi.json";

// Sanity check. Make sure our target file doesn't exist already.
if (file_exists($out)) {
	// Ooh. It does. The first line better be json
	$current = @json_decode(file_get_contents($out), true);
	if (!is_array($current)) {
		die("The $out file isn't mine. Can't continue");
	}

	// First security check - Make sure nothing else is hardlinked to this file.
	$stat = stat($out);
	if ($stat['nlink'] != 1) {
		die("Output file is hard linked to another file. Can't continue");
	}

	// Second security check - Make sure it's not a symlink.
	if (is_link($out)) {
		die("Output file is a symlink. Can't continue");
	}
}
// If we made it here, it's not a symlink, it's not hard linked to another
// file, and it already contains JSON. We should be pretty safe to clobber it.

// Open and empty the file.
$fh = fopen($out, "w");

// Check to make sure no-one else is using it.
flock($fh, LOCK_EX|LOCK_NB, $wouldblock);
if ($wouldblock) {
	die("can't flock");
}

$output = getDmiArray();
// Now, just save it to the output file
fwrite($fh, json_encode($output));
// And we're done.

function getDmiArray() {
	// If we're root, we can run dmidecode directly
	if (posix_geteuid() === 0) {
		exec("/usr/sbin/dmidecode", $out, $ret);
		if ($ret !== 0) {
			die ("Something happened. Can't run demidecode $ret");
		}
	} else {
		// We need to sudo.
		exec("sudo -n /usr/sbin/dmidecode", $out, $ret);
		if ($ret !== 0) {
			// You need to add something like this to suoders
			// thiusername ALL = (root) NOPASSWD: /usr/sbin/dmidecode
			// Where 'thisusername' is the user you are running this as
			die("Unable to run dmidecode via sudo.");
		}
	}

	$retarr = array();

	// Now squish it into an array
	$current = false;
	$nextishandle = false;
	foreach ($out as $l) {
		if (substr($l, 0, 6) == "Handle") {
			$nextishandle = true;
			continue;
		}

		if ($nextishandle) {
			$current = str_replace(" ", "", $l);
			$nextishandle = false;
			continue;
		}

		if (!$current) {
			continue;
		}

		$line = trim($l);

		if (!$line) {
			continue;
		}

		$tmparr = explode(":", $line);

		if (!isset($tmparr[1])) {
			$retarr[$current][] = $line;
		} else {
			$retarr[$current][$tmparr[0]] = $tmparr[1];
		}
	}

	// Add our flavour
	$retarr['generated-by'] = 'phpdmidecode-1';
	$retarr['timestamp'] = time();

	return $retarr;
}

