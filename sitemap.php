<?php
/*
Sitemap Generator by Slava Knyazev

Website: https://www.knyz.org/
I also live on GitHub: https://github.com/knyzorg
Contact me: Slava@KNYZ.org
*/

//Make sure to use the latest revision by downloading from github: https://github.com/knyzorg/Sitemap-Generator-Crawler

/* Usage
Usage is pretty strait forward:
- Configure the crawler
- Select the file to which the sitemap will be saved
- Select URL to crawl
- Configure blacklists, accepts the use of wildcards (example: http://example.com/private/* and *.jpg)
- Generate sitemap
- Either send a GET request to this script or simply point your browser
- Submit to Google
- Setup a CRON Job to send web requests to this script every so often, this will keep the sitemap.xml file up to date

It is recommended you don't remove the above for future reference.
*/

// Add PHP CLI support
if (php_sapi_name() === 'cli') {
    parse_str(implode('&', array_slice($argv, 1)), $args);
}

//Site to crawl
$site = "https://www.knyz.org" . "/";

//Location to save file
$file = "sitemap.xml";

//How many layers of recursion are you on, my dude?
$max_depth = 0;

//These two are relative. It's pointless to enable them unless if you intend to modify the sitemap later.
$enable_frequency = false;
$enable_priority = false;

//Tells search engines the last time the page was modified according to your software
//Unreliable: disabled by default
$enable_modified = false;

//Some sites have misconfigured but tolerable SSL. Disable this for those cases.
$curl_validate_certificate = true;

//Relative stuff, ignore it
$freq = "daily";
$priority = "1";

//The pages will not be crawled and will not be included in sitemap
//Use this list to exlude non-html files to increase performance and save bandwidth
$blacklist = array(
    "*.jpg",
    "*/secrets/*",
    "https://www.knyz.org/supersecret"
);


/* NO NEED TO EDIT BELOW THIS LINE */

$debug = Array(
    "add" => true,
    "reject" => true,
    "warn" => true
);

function logger($message, $type){
    global $debug;
    switch ($type) {
    case 0:
        //add
        echo $debug["add"] ? "[+] $message \n" : "";
        break;
    case 1:
        //reject
        echo $debug["reject"] ? "[-] $message \n" : "";
        break;
    case 2:
        //manipulate
        echo $debug["warn"] ? "[!] $message \n" : "";
        break;
    }
}

function is_scanned($url){
    global $scanned;
    if (in_array($url, $scanned)){
        return true;
    }
    $url = ends_with($url, "?") ? explode("?", $url)[0] : $url;
    if (in_array($url, $scanned)){
        return true;
    }
    
    $url = ends_with($url, "/") ? explode("/", $url)[0] : $url . "/";
    if (in_array($url, $scanned)){
        return true;
    }
    return false;
}

function ends_with($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}

//I don't remember what this function does and why. Please help.
function get_path($p)
{
    $a = explode("/", $p);
    $len = strlen($a[count($a) - 1]);
    return (substr($p, 0, strlen($p) - $len));
}

function domain_root($href) {
    $url_parts = explode('/', $href);
    return $url_parts[0].'//'.$url_parts[2].'/';
}

$ch = curl_init();
function get_data($url)
{
    global $curl_validate_certificate, $ch;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $curl_validate_certificate);
    $data = curl_exec($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    if ($redirect_url){
        logger("URL is a redirect.", 1);
        scan_url($redirect_url);
    }
    $html = ($http_code != 200 || (!stripos($content_type, "html"))) ? false : $data;
    $timestamp = curl_getinfo($ch, CURLINFO_FILETIME);
    $modified = date('c', strtotime($timestamp));
    return array($html, $modified);
}


function check_blacklist($uri)
{
    global $blacklist;
    if (is_array($blacklist)) {
        $string = $uri;
        foreach ($blacklist as $illegal) {
            if (fnmatch($illegal,$string)) {
                return false;
            }
        }
    }
    return true;
}

function scan_url($url)
{
    global $scanned, $pf, $freq, $priority, $enable_modified, $enable_priority, $enable_frequency, $max_depth, $depth, $site;
    $depth++;
    
    $proceed = true;
    logger("Scanning $url", 2);
    
    if (is_scanned($url)){
        logger("URL has already been scanned. Rejecting.", 1);
        $proceed = false;
    }
    array_push($scanned, $url);
    list($html, $modified) = get_data($url);
    if (!$html){
        logger("Invalid Document. Rejecting.", 1);
        $proceed = false;
    }

    elseif (!($depth <= $max_depth || $max_depth == 0)){
        logger("Maximum depth exceeded. Rejecting.", 1);
        $proceed = false;
    }
    if ($proceed) {

        
        if (!$enable_modified) unset($modified);

        $map_row = "<url>\n";
        $map_row .= "<loc>$url</loc>\n";
        if ($enable_frequency) $map_row .= "<changefreq>$freq</changefreq>\n";
        if ($enable_priority) $map_row .= "<priority>$priority</priority>\n";
        if (!empty($modified)) $map_row .= "   <lastmod>$modified</lastmod>\n";
        $map_row .= "</url>\n";
        fwrite($pf, $map_row);

        logger("Added: " . $url . ((!empty($modified)) ? " [Modified: " . $modified . "]" : ''), 0);

        $regexp = "<a\s[^>]*href=(\"|'??)([^\" >]*?)\\1[^>]*>(.*)<\/a>";
        if (preg_match_all("/$regexp/siU", $html, $matches)) {
            if ($matches[2]) {
                $links = $matches[2];
                foreach ($links as $href) {
                    logger("Found $href", 2);
                    if (strpos($href, '?') !== false) list($href, $query_string) = explode('?', $href);
                    else $query_string = '';

                    if (strpos($href, "#") !== false){
                        logger("Dropping pound.", 2);
                        $href = strtok($href, "#");
                    }
                    if ((substr($href, 0, 7) != "http://") && (substr($href, 0, 8) != "https://")) {
                        // Link does not call (potentially) external page
                        
                        if ($href == '/') {
                            logger("$href is domain root", 2);
                            $href = $site . $href;
                        }
                        elseif (substr($href, 0, 1) == '/') {
                            logger("$href is relative to root, convert to absolute", 2);
                            $href = domain_root($site) . substr($href, 1);
                        } else {
                            logger("$href is relative, convert to absolute", 2);
                            $href = get_path($url) . $href;
                        }
                    }
                        logger("Result: $href", 2);
                        //Assume that URL is okay until it isn't
                        $valid = true;

                        if (!filter_var($href, FILTER_VALIDATE_URL)) {
                            logger("URL is not valid. Rejecting.", 1);
                            $valid = false;
                        }

                        if (substr($href, 0, strlen($site)) != $site){
                            logger("URL is not part of the target domain. Rejecting.", 1);
                            $valid = false;
                        }
                        if (is_scanned($href . ($query_string?'?'.$query_string:''))){
                            logger("URL has already been scanned. Rejecting.", 1);
                            $valid = false;
                        }
                        if (!check_blacklist($href)){
                            logger("URL is blacklisted. Rejecting.", 1);
                            $valid = false;
                        }
                        if ($valid) {

                            $href = $href . ($query_string?'?'.$query_string:'');

                            
                            scan_url($href);
                        }

                }
            }
        }
    }
    $depth--;
}
header("Content-Type: text/plain");
if (isset($args['file'])) $file = $args['file'];
if (isset($args['site'])) $site = $args['site'];
if (isset($args['max_depth'])) $max_depth = $args['max_depth'];
if (isset($args['enable_frequency'])) $enable_frequency = $args['enable_frequency'];
if (isset($args['enable_priority'])) $enable_priority = $args['enable_priority'];
if (isset($args['enable_modified'])) $enable_modified = $args['enable_modified'];
if (isset($args['freq'])) $freq = $args['freq'];
if (isset($args['priority'])) $priority = $args['priority'];
if (isset($args['blacklist'])) $blacklist = $args['blacklist'];
if (isset($args['debug'])) $debug = $args['debug'];

$start = microtime(true);
$pf = fopen($file, "w");
if (!$pf) {
    logger("Error: Could not create file - $file", 1);
    exit;
}
fwrite($pf, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<urlset
      xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"
      xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
      xsi:schemaLocation=\"http://www.sitemaps.org/schemas/sitemap/0.9
            http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd\">
");
$depth = 0;
$scanned = array();
scan_url($site);
fwrite($pf, "</urlset>\n");
fclose($pf);
$time_elapsed_secs = microtime(true) - $start;
echo "[+] Sitemap has been generated in " . $time_elapsed_secs . " second" . ($time_elapsed_secs >= 1 ? 's' : '') . ".\n";