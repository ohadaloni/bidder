<?php
/*------------------------------------------------------------*/
define('M_DIR', "/var/www/vhosts/M.theora.com");
define('TAS_DIR', "/var/www/vhosts/tas.theora.com");
require_once(TAS_DIR."/conf/dbCredentials.php");
define('M_DBNAME', 'bidder');
/*------------------------------------------------------------*/
define('M_MEMCACHE_HOST', 'mem1');
/*------------------------------------------------------------*/
// optimize placements only for the last this many days 
define('PLACEMENT_OPT_WINDOW', 14);
// minimum wins in the last PLACEMENT_OPT_WINDOW to trust this placement
define('PLACEMENT_MIN_WINS', 7);
/*------------------------------------------------------------*/
define('BANNER_DIR', '/var/www/vhosts/bidder.theora.com/banners');
define('BANNER_SERVER', 'bidder.theora.com');
/*------------------------------------------------------------*/
define('WIN_SERVER', 'sink.bidder.theora.com');
define('CLICK_SERVER', 'sink.bidder.theora.com');
define('VIEW_SERVER', 'sink.bidder.theora.com');
define('CPA_SERVER', 'sink.bidder.theora.com');
/*------------------------------------------------------------*/
