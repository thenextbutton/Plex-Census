<?php
// Error Reporting (Helpful for debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- DEBUG CONFIG ---
// Set this to true to turn on the firehose of debug logging.
// Seriously, run this only when debugging a Plex-related existential crisis.
$debug_output_enabled = false;
// ------------------------

// --- IMAGE CONFIGURATION ---
// Set this to true to enable parallel JPEG output for images.
// for older devices/fallback
$jpeg_output_enabled = true;

// AVIF quality (Plex default is often 30-40, available values -1 .. 127 )
$avif_quality = 30;

// JPEG quality (standard quality is usually 70 for web, available values -1, 127 )
$jpeg_quality = 70;

// ------------------------


// =====================================================================
// Configuration & Command-Line Argument Parsing (The Essentials)
// =====================================================================

// Check for required command-line arguments:
// 1: <plexip:port> (Where the magic happens)
// 2: <plex token> (The secret key to the kingdom)
// 3: website header (For the fancy web interface)
// 4: 'Title1,Title2,...' (The libraries we are actually interested in)
// 5: <web_root> (Where all this madness gets dumped)
// 6: <watch_status_mode> (Optional: Set to '1' to enable 0/1/2 status)
if ($argc < 6) {
    fwrite(STDERR, "Usage: php $argv[0] '<plexip:port>' '<plex token>' '<website header>' 'Title1,Title2,...' '<web_root>' [watch_status_mode (1 or 0)]\n");
    exit(1);
}

$plex_ip_port = $argv[1];
$plex_token = $argv[2];
$website_header = $argv[3];
$library_titles_csv = $argv[4];
$public_web_root = $argv[5];

$calculate_watch_status = (isset($argv[6]) && in_array(strtolower($argv[6]), ['1', 'true', 'on']));

$export_start_time = date('d-m-Y H:i:s');

// Ensure web root ends with a slash for consistent path creation.
// Because nothing breaks a script faster than a missing trailing slash.
if (substr($public_web_root, -1) !== '/') {
    $public_web_root .= '/';
}

// =====================================================================
// File Path Definitions (The Filing Cabinet)
// =====================================================================

$script_dir = __DIR__;
$date_prefix = date('Ymd');
// Error log file changes daily, so we don't accidentally check logs from last week's failure.
$debug_log_file = $script_dir . '/' . $date_prefix . '_debug.log'; // E.g., /20251025_error_log.txt

$output_json_file = $public_web_root . 'data/library.json'; // The final, glorious data dump




// =====================================================================
// Helper Functions - Logging (The Overly Detailed Historian)
// =====================================================================

/**
 * Writes a message to the specified error log file.
 * The only thing we capture more detail on is the server crash itself.
 * @param string $file_path The full path to the log file.
 * @param string $message The message to write.
 * @param bool $is_error Whether the message is an error (appends 'ERROR:').
 */
function write_to_debug_log($file_path, $message, $is_error = false) {
    global $debug_output_enabled;
    // We only care if it's an actual ERROR, or if someone explicitly asked for the noise (debug).
    if (!$is_error && !$debug_output_enabled) {
        return;
    }

    $time_prefix = date('H:i:s') . " - ";
    $log_type_prefix = $is_error ? "ERROR: " : "DEBUG: ";
    // Combine the time, log type, and message
    $full_message = $time_prefix . $log_type_prefix . $message . "\n";
    // FILE_APPEND ensures we don't overwrite history.
    file_put_contents($file_path, $full_message, FILE_APPEND | LOCK_EX);
}

// =====================================================================
// Helper Function - Rating System Discovery (Official Country Code)
// =====================================================================

function get_library_rating_system($plex_ip_port, $plex_token, $library_key) {
    global $debug_log_file;
    
    // The endpoint to fetch library preferences
    $endpoint = "/library/sections/$library_key/prefs";
    $xml_string = fetch_plex_data($endpoint, $plex_ip_port, $plex_token);
    
    if (!$xml_string) {
        write_to_debug_log($debug_log_file, "Could not fetch preferences for key $library_key to determine rating system.", true);
        return 'Unknown';
    }
    
    $xml = simplexml_load_string($xml_string);
    if (!$xml) {
        write_to_debug_log($debug_log_file, "Error parsing preferences XML for key $library_key.", true);
        return 'Unknown';
    }

    $certification_country = 'Unknown';
    
    // Iterate through all <Setting> elements to find the 'country' ID
    foreach ($xml->Setting as $setting) {
        if ((string)$setting['id'] === 'country') {
            // Return the two-letter country code (e.g., 'US', 'GB')
            return strtoupper((string)$setting['value']); 
        }
    }
    
    // Fallback if the 'country' setting is not found (shouldn't happen for movie libraries)
    return 'Unknown';
}

// =====================================================================
// Helper Functions - Plex API Communication (The Diplomat)
// =====================================================================

function fetch_plex_data($endpoint, $plex_ip_port, $plex_token, $is_image = false) {
    global $debug_log_file, $debug_output_enabled;
    // Determine the correct separator for the token (because query strings are complicated)
    $separator = (strpos($endpoint, '?') === false) ? '?' : '&';

    // Construct the full URL (it's always HTTP, because Plex is old school)
    $url = "http://$plex_ip_port$endpoint" . ($is_image ? '' : $separator . "X-Plex-Token=$plex_token");

    // DEBUG: Log the API attempt before execution, because sometimes Plex just ignores us.
    write_to_debug_log($debug_log_file, "Attempting API Call. Endpoint: '$endpoint'. URL: '$url'.");

    $ch = curl_init();
    if ($ch === false) {
        // cURL couldn't even get out of bed. Tragic.
        write_to_debug_log($debug_log_file, "Plex API error on endpoint '$endpoint'. cURL failed to initialize.", true);
        return false;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // Timeout is generous, especially for images, which can sometimes be transcoding chaos.
    curl_setopt($ch, CURLOPT_TIMEOUT, $is_image ? 30 : 90);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code !== 200 || $response === false) {
        // This is where Plex has let us down. Log the entire sad story.
        write_to_debug_log($debug_log_file, "Plex API FAIL. Endpoint: '$endpoint'. URL: '$url'. HTTP Code: $http_code. Curl Error: " . $curl_error . ". Response was: " . ($response === false ? 'FALSE' : 'Not 200'), true);
        return false;
    }

    // DEBUG: Successful API CALL (Non-Image API calls are logged)
    if ($debug_output_enabled && !$is_image) {
        write_to_debug_log($debug_log_file, "API SUCCESS (HTTP 200). Endpoint: '$endpoint'.");
    }

    return $response;
}

// =====================================================================
// Helper Functions - Library Discovery & Mapping (The Librarian)
// =====================================================================

// Function to fetch the key (retained as a fallback, because we don't trust the main discovery function yet)
function get_library_key_by_title($plex_ip_port, $plex_token, $title) {
    global $debug_log_file;
    $xml_string = fetch_plex_data('/library/sections', $plex_ip_port, $plex_token);
    if (!$xml_string) return false;

    $xml = simplexml_load_string($xml_string);
    foreach ($xml->Directory as $directory) {
        if ((string)$directory['title'] === $title) {
            return (string)$directory['key'];
        }
    }
    write_to_debug_log($debug_log_file, "CRITICAL FAIL: Library key for title '$title' not found in Plex. Did you mistype the name?", true);
    return false;
}

/**
 * Discovers all libraries and maps title to type and key.
 * Basically, asks Plex what it actually has, instead of guessing.
 */
function discover_plex_libraries($plex_ip_port, $plex_token) {
    $xml_string = fetch_plex_data('/library/sections', $plex_ip_port, $plex_token);
    if (!$xml_string) return false;

    $xml = simplexml_load_string($xml_string);
    $libraries = [];
    foreach ($xml->Directory as $directory) {
        $title = (string)$directory['title'];
        $plex_type = (string)$directory['type'];

        // Map Plex API type ('artist') to the user's internal processing type ('music_artist')
        // Because 'artist' isn't descriptive enough.
        $internal_type = $plex_type;
        if ($plex_type === 'artist') {
            $internal_type = 'music_artist';
        }

        $libraries[$title] = [
            'key' => (string)$directory['key'],
            'internal_type' => $internal_type
        ];
    }
    return $libraries;
}

// =====================================================================
// Helper Functions - Formatting (The OCD Accountant)
// =====================================================================

function format_duration($duration_ms) {
    $duration_sec = round($duration_ms / 1000);
    // You get H:i:s, or you get nothing.
    return $duration_sec > 0 ? gmdate("H:i:s", $duration_sec) : '00:00:00';
}

function format_file_size_gb($file_size_bytes) {
    // 1073741824 bytes = 1 GB. Dealing with floats because your movie collection is too large for an integer.
    return $file_size_bytes > 0
    ? number_format($file_size_bytes / 1073741824, 2, '.', '')
    : '0.00';
}

function format_file_size_mb($file_size_bytes) {
    // 1048576 bytes = 1 MB (1024 * 1024). For the small-file collection (Music).
    return $file_size_bytes > 0
    ? number_format($file_size_bytes / 1048576, 2, '.', '')
    : '0.00';
}

function classify_resolution($resolution) {
    // Assigning fancy names to numbers so it sounds more professional.
    switch ($resolution) {
        case '480':
        case '576':
            return 'SD';
        case '720':
            return 'HD';
        case '1080':
            return 'Full HD';
        case '2160':
        case '4k':
            return '4K UHD';
        case '4320':
            return '8K UHD';
        default:
            return 'Unknown'; // When in doubt, it's 'Unknown'.
    }
}

function get_high_quality_codec($codec) {
    // Translating technical gibberish into slightly more readable gibberish.
    switch (strtolower($codec)) {
        case 'truehd':
            return 'DOLBY TRUEHD';
        case 'dts':
            return 'DTS';
        case 'dca':
            return 'DTS-HD MA';
        case 'ac3':
            return 'DOLBY DIGITAL';
        case 'eac3':
            return 'DOLBY DIGITAL PLUS';
        case 'flac':
            return 'FLAC';
        case 'aac':
            return 'AAC';
        default:
            return strtoupper($codec);
    }
}

// =====================================================================
// Helper Function - Image Cache (The Hoarder)
// =====================================================================

/**
 * Downloads and caches the cover art (optimized for Artist/Movie Posters)
 * Uses a fixed 300x450 ratio for vertical posters.
*/
function download_and_cache_cover($thumb_path, $plex_ip_port, $plex_token, $covers_base_dir, $covers_base_subfolder, $target_folder, &$used_image_files, $width = 300, $height = 450) {
    global $debug_log_file, $debug_output_enabled, $jpeg_output_enabled, $avif_quality, $jpeg_quality;

    $final_subfolder = $covers_base_subfolder . '/' . $target_folder;
    $final_local_dir = $covers_base_dir . '/' . $target_folder;

    // Ensure the specific target directory exists.
    if (!is_dir($final_local_dir)) {
        if (!mkdir($final_local_dir, 0755, true)) {
            write_to_debug_log($debug_log_file, "Failed to create covers directory: $final_local_dir. Check permissions on web root.", true);
            return 'assets/images/placeholder.webp';
        }
    }

    $md5_filename = md5($thumb_path);
    $downloaded_avif = false;

    // --- Core Caching Logic for AVIF (The Primary Format) ---
    $local_path_avif = $final_local_dir . '/' . $md5_filename . '.avif';

    if (!file_exists($local_path_avif)) {
        // Plex image endpoint URL construction for AVIF:
        $image_endpoint_avif = "/photo/:/transcode?width=$width&height=$height&minSize=1&quality={$avif_quality}&url=" .
            urlencode($thumb_path) .
            "&X-Plex-Token=$plex_token&format=avif";

        write_to_debug_log($debug_log_file, "Attempting AVIF Download for: $md5_filename.avif from '$thumb_path'");
        $image_data_avif = fetch_plex_data($image_endpoint_avif, $plex_ip_port, $plex_token, true);

        if ($image_data_avif === false || empty($image_data_avif)) {
             write_to_debug_log($debug_log_file, "CRITICAL: Plex API Failure during AVIF download. Check debug log for details.", true);
             return 'assets/images/placeholder.webp';
        }

        if (file_put_contents($local_path_avif, $image_data_avif) !== false) {
            echo "Downloaded: $md5_filename.avif into /$target_folder \n";
            $used_image_files[] = $target_folder . '/' . $md5_filename . '.avif';
            $downloaded_avif = true;
        } else {
            write_to_debug_log($debug_log_file, "Failed to write AVIF file to $local_path_avif. Permissions issue?", true);
            return 'assets/images/placeholder.webp';
        }
    } else {
        $used_image_files[] = $target_folder . '/' . $md5_filename . '.avif';
        $downloaded_avif = true;
    }


    // --- Optional Caching Logic for JPEG ---
    if ($jpeg_output_enabled) {
        $local_path_jpeg = $final_local_dir . '/' . $md5_filename . '.jpeg';

        if (!file_exists($local_path_jpeg)) {
            // Plex image endpoint URL construction for JPEG (higher quality for older devices/fallback):
            $image_endpoint_jpeg = "/photo/:/transcode?width=$width&height=$height&minSize=1&quality={$jpeg_quality}&url=" . urlencode($thumb_path) .
                                 "&X-Plex-Token=$plex_token&format=jpeg";

            write_to_debug_log($debug_log_file, "Attempting JPEG Download for: $md5_filename.jpeg from '$thumb_path'");
            $image_data_jpeg = fetch_plex_data($image_endpoint_jpeg, $plex_ip_port, $plex_token, true);

            if ($image_data_jpeg === false || empty($image_data_jpeg)) {
                 write_to_debug_log($debug_log_file, "WARNING: Plex API Failure during JPEG download. Ignoring.", true);
            } else {
                if (file_put_contents($local_path_jpeg, $image_data_jpeg) !== false) {
                    echo "Downloaded: $md5_filename.jpeg into /$target_folder \n";
                    $used_image_files[] = $target_folder . '/' . $md5_filename . '.jpeg';
                } else {
                    write_to_debug_log($debug_log_file, "Failed to write JPEG file to $local_path_jpeg. Permissions issue?", true);
                }
            }
        } else {
            $used_image_files[] = $target_folder . '/' . $md5_filename . '.jpeg';
        }
    }

    // Return the web-relative URL for the primary format (AVIF)
    return $final_subfolder . '/' . $md5_filename . '.avif';
}

/**
 * Downloads and caches a smaller square image (optimized for Album Art)
 * Because Albums need smaller, square, less-crashy images.
 */
function download_and_cache_album_art($thumb_path, $plex_ip_port, $plex_token, $covers_base_dir, $covers_base_subfolder, &$used_image_files) {
    // This is just a wrapper that calls the main function with specific, smaller, square dimensions.
    return download_and_cache_cover(
        $thumb_path,
        $plex_ip_port,
        $plex_token,
        $covers_base_dir,
        $covers_base_subfolder,
        'album', // <-- Saved in the 'album' folder
        $used_image_files, // Pass the array
        150, // Smaller size for album art
        150
    );
}


/**
 * Downloads and caches the music artist image.
 * Uses 450x450, allowing Plex to determine the proportional height.
 */
function download_and_cache_artist_art($thumb_path, $plex_ip_port, $plex_token, $covers_base_dir, $covers_base_subfolder, &$used_image_files) {
    return download_and_cache_cover(
        $thumb_path,
        $plex_ip_port,
        $plex_token,
        $covers_base_dir,
        $covers_base_subfolder,
        'artist', // Saved in the 'artist' folder
        $used_image_files,
        450, 
        450 
    );
}



// =====================================================================
// Helper Function - Cleanup (The Disk Janitor)
// =====================================================================

/**
 * Recursively deletes files in a library's covers folder that are NOT in the $used_image_files list.
 *
 * @param string $covers_local_dir The base directory for this library's covers (e.g., /web/data/covers/films).
 * @param array $used_image_files List of relative paths for files that were used in this run (e.g., ['poster/md5.avif']).
 */
function cleanup_library_covers($covers_local_dir, $used_image_files) {
    global $debug_log_file;
    
    write_to_debug_log($debug_log_file, "Starting cleanup for directory: $covers_local_dir");

    // Use array_flip for faster key lookups (O(1) complexity)
    $used_set = array_flip($used_image_files);
    $files_deleted = 0;

    // Recursive directory iterator to find all files in subdirectories (poster, artist, album)
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($covers_local_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        // Only process .avif and .jpeg files that are not directories 
        $extension = $file->getExtension();
        if ($file->isFile() && ($extension === 'avif' || $extension === 'jpeg')) {
            
            // Get the file's path relative to the base covers directory (e.g., 'poster/md5.avif')
            // This is the key needed to check against our $used_set
        
    $relative_path = substr($file->getPathname(), strlen($covers_local_dir) + 1);
        if (!isset($used_set[$relative_path])) {
                // File is not in the list of used images, so we delete it.
        if (unlink($file->getPathname())) {
                    write_to_debug_log($debug_log_file, "Cleanup: DELETED unused file: $relative_path");
        $files_deleted++;
                } else {
                    write_to_debug_log($debug_log_file, "Cleanup Error: Failed to delete file: $relative_path. Permissions issue?", true);
}
            }
        }
    }
    
    write_to_debug_log($debug_log_file, "Cleanup complete. Total files deleted: $files_deleted");
    echo "Cleanup complete for {$covers_local_dir}, deleted $files_deleted unused images.\n";
}


// =====================================================================
// CORE SCRAPING FUNCTION (The Data Miners)
// =====================================================================

/**
 * Scrapes a single Plex library based on the provided configuration.
 */
function scrape_library($config, $plex_ip_port, $plex_token, $public_web_root) {
    global $debug_log_file, $debug_output_enabled;

    $library_title = $config['title'];
    $library_type_config = $config['type'];
    $library_id = $config['id'];

    // If the library key isn't pre-fetched, we try to look it up (a slow, painful fallback).
    $library_key = $config['key'] ?? get_library_key_by_title($plex_ip_port, $plex_token, $library_title);

    if (!$library_key) {
        // WARNING changed to CRITICAL FAIL
        write_to_debug_log($debug_log_file, "CRITICAL FAIL: Skipping '{$library_title}'. Key not found. Check your command line input.", true);
        // CRITICAL FIX: Stop the entire script, since a requested library cannot be processed.
        fwrite(STDERR, "ERROR: Requested library '{$library_title}' not found on Plex server. Check your input!\n");
        exit(1);
    }

    echo "Processing '{$library_title}'... (This might take a while)\n";

    // DEBUG: LOG START
    if ($debug_output_enabled) {
        write_to_debug_log($debug_log_file, "LIBRARY: STARTED processing: '$library_title'.");
    }

    // Dynamic Paths for this library (e.g., 'data/covers/films' or 'data/covers/music')
    $covers_subfolder = 'data/covers/' . str_replace(' ', '-', strtolower($library_title));
    $covers_local_dir = $public_web_root . $covers_subfolder;
    
    $used_image_files = []; // *** Array to track files used in this library ***

    // Ensure the covers directory exists
    if (!is_dir($covers_local_dir)) {
        if (!mkdir($covers_local_dir, 0755, true)) {
            fwrite(STDERR, "ERROR: Failed to create covers directory: $covers_local_dir. Check permissions on the web root.");
            exit(1);
        }
    }

    $data = [];
    if ($library_type_config === 'movie') {
        $data = process_films($library_key, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, $used_image_files);
    } elseif ($library_type_config === 'show') {
        $data = process_tv_shows($library_key, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, $used_image_files);
    } elseif ($library_type_config === 'music_artist') {
        $data = process_music($library_key, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, $used_image_files);
    }

    // Inject the unique Library ID into every item for later grouping/filtering in the frontend.
    foreach ($data as &$item) {
        $item['libraryId'] = $library_id;
    }
    unset($item); // CRITICAL: Unset the reference to avoid unexpected side effects (classic PHP foot-gun)

    // --- RUN CLEANUP FOR THIS LIBRARY ---
    cleanup_library_covers($covers_local_dir, $used_image_files);

    // DEBUG: LOG END
    if ($debug_output_enabled) {
        write_to_debug_log($debug_log_file, "LIBRARY: ENDED processing: '$library_title'.");
    }

    return $data;
}

// =====================================================================
// Content Rating Sanitization (The Regulator)
// =====================================================================

function sanitize_content_rating(string $raw_rating): string {
    $clean_rating = $raw_rating;

    if (!empty($clean_rating)) {
        // Convert to uppercase (e.g., "gb/12a" -> "GB/12A")
        $clean_rating = strtoupper($clean_rating);
        
        // Replace the forward slash separator (e.g., GB/12A -> GB-12A)
        $clean_rating = str_replace('/', '-', $clean_rating);
        
        // Strip the country code prefix (e.g., "GB-PG" -> "PG", "HK-IIB" -> "IIB")
        $clean_rating = preg_replace('/^[A-Z]{2}-/', '', $clean_rating);
        
        // Replace any remaining invalid file characters (spaces) with hyphens
        // This handles ratings like "NOT RATED" -> "NOT-RATED"
        $clean_rating = preg_replace('/[ ]/', '-', $clean_rating);
    }
    
    return $clean_rating;
}

// =====================================================================
// Scraping Module: Films (The Cinephile)
// =====================================================================

function process_films($library_key, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, &$used_image_files) {
    global $debug_log_file, $debug_output_enabled;
    $xml_string = fetch_plex_data("/library/sections/$library_key/all", $plex_ip_port, $plex_token);
    if (!$xml_string) return [];
    $xml = simplexml_load_string($xml_string);
    $library_data = [];

    write_to_debug_log($debug_log_file, "FILMS: Found " . count($xml->Video) . " films in XML response for key $library_key.");

    foreach ($xml->Video as $video) {

        // --- Data Extraction: Watched Status ---
        global $calculate_watch_status;
        $watched_status = 0; // Default: Not Watched

        if ($calculate_watch_status) {
            $view_count = (int)$video['viewCount'];
            $view_offset = (int)$video['viewOffset'];
            
            if ($view_count > 0 && $view_offset == 0) {
                // ViewCount > 0, but no offset means it was fully watched
                $watched_status = 2; // Fully Watched
            } elseif ($view_offset > 0) {
                // ViewOffset > 0 means viewing stopped mid-way
                $watched_status = 1; // Partially Watched 
            }
        } else {
            // Default behavior when watch status mode is set to 0:
            // Force status to 0 (Not Watched) for all films.
            $watched_status = 0; 
        }
        // ----------------------------------------

        // DEBUG: Log each film being processed, so we know which one Plex is currently bored with.
        write_to_debug_log($debug_log_file, "Processing Film: " . (string)$video['title'] . " (Key: " . (string)$video['ratingKey'] . ")");

        // Initializing variables. Always use float (0.0) for size to avoid the 32-bit integer overflow limit.
        $largest_part_size = 0.0;
        $total_movie_size_sum = 0.0;
        $container = 'N/A';
        $audio_codec = 'N/A';
        $raw_video_resolution = 'N/A';


        // Loop through all <Media> blocks (different file versions/qualities)
        if (isset($video->Media)) {
            foreach ($video->Media as $media) {

                // Loop through all <Part> blocks within this <Media> (split files)
                if (isset($media->Part)) {
                    foreach ($media->Part as $part) {
                        // CRITICAL: Convert size to a float immediately!
                        $part_size_float = (float)(string)$part['size'];
                        $total_movie_size_sum += $part_size_float; // The total size of all parts

                        // Find the LARGEST part to determine the definitive quality/container of the file.
                        if ($part_size_float > $largest_part_size) {
						write_to_debug_log($debug_log_file, "FILMS: Setting highest quality (Size: " . format_file_size_gb($part_size_float) . " GB, Resolution: " . (string)$media['videoResolution'] . ") for " . (string)$video['title']);
                            $largest_part_size = $part_size_float;
                            // Use the quality details from the parent <Media> tag
                            $audio_codec = (string)$media['audioCodec'];
                            $container = (string)$media['container'];
                            $raw_video_resolution = (string)$media['videoResolution'];
                        }
                    }
                }
            }
        }


        // Final Data Processing
        $audio_list = get_high_quality_codec($audio_codec);
        $audio_list = $audio_list ?: 'Unknown'; // If Plex gives us nothing, we call it 'Unknown'.

        $duration_formatted = format_duration((int)$video['duration']);
        $file_size_gb_formatted = format_file_size_gb($total_movie_size_sum);
        $display_resolution = classify_resolution($raw_video_resolution);

        // Image Caching (Finally, the poster)
        $local_thumb_url = download_and_cache_cover(
             (string)$video['thumb'],
             $plex_ip_port,
             $plex_token,
             $covers_local_dir,
             $covers_subfolder,
             'poster', // <-- Saves to the 'poster' subfolder
             $used_image_files // Pass the array
         );

        usleep(5000); // 5ms delay (In case Plex needs a breather)

        $raw_rating = (string)$video['contentRating'];
        write_to_debug_log($debug_log_file, "DEBUG: Raw contentRating: " . $raw_rating);
        $clean_rating = sanitize_content_rating($raw_rating);
        write_to_debug_log($debug_log_file, "DEBUG: Sanitized contentRating: " . $clean_rating);

        // Build Final Data Array (It's beautiful!)
        $library_data[] = [
            'type' => 'movie',
            'title' => (string)$video['title'],
            'tagline' => (string)$video['tagline'],
            'summary' => (string)$video['summary'],
            'year' => (string)$video['year'],
            'studio' => (string)$video['studio'],
            'contentRating' => $clean_rating,
            'duration' => $duration_formatted,
            'fileContainer' => $container,
            'fileSizeGB' => $file_size_gb_formatted,
            'audioFormats' => $audio_list,
            'Resolution' => $display_resolution,
            'thumb_url' => $local_thumb_url,
            'w' => $watched_status // <<--- WATCHED STATUS
        ];

    }

    return $library_data;
}

// =====================================================================
// Scraping Module: TV Shows (The Binge Watcher)
// =====================================================================

function process_tv_shows($library_key, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, &$used_image_files) {
    global $debug_log_file, $debug_output_enabled;
    // Fetch all TV Shows in the library
    $xml_shows_string = fetch_plex_data("/library/sections/$library_key/all", $plex_ip_port, $plex_token);
    
    // CRITICAL: If the main data fetch fails, stop and alert!
    if (!$xml_shows_string) {
        write_to_debug_log($debug_log_file, "CRITICAL FAIL: Failed to fetch ALL TV Shows for key $library_key.", true);
        fwrite(STDERR, "ERROR: Failed to fetch primary data for TV Shows library.\n");
        exit(1);
    }

    $xml_shows = simplexml_load_string($xml_shows_string);
    $library_data = [];
	
	write_to_debug_log($debug_log_file, "TV SHOWS: Found " . count($xml_shows->Directory) . " shows in XML response for key $library_key.");
	
    foreach ($xml_shows->Directory as $show_xml) {
        if ((string)$show_xml['type'] !== 'show') continue;
        
        // --- Data Extraction: Watched Status (Show Level) ---
        global $calculate_watch_status;
        $show_watched_status = 0; // Default: Not Watched

        if ($calculate_watch_status) {
            $viewed_leaf_count = (int)$show_xml['viewedLeafCount'];
            $leaf_count = (int)$show_xml['leafCount'];

        write_to_debug_log($debug_log_file, "WATCH STATUS (SHOW): " . (string)$show_xml['title'] . " - Viewed: $viewed_leaf_count, Total: $leaf_count.");

            if ($viewed_leaf_count > 0 && $viewed_leaf_count === $leaf_count) {
                $show_watched_status = 2; // Fully Watched
            } elseif ($viewed_leaf_count > 0) {
                $show_watched_status = 1; // Partially Watched
            }
        } else {
            // Default behavior when watch status mode is set to 0:
            // Force status to 0 (Not Watched) for all shows.
            $show_watched_status = 0; 
        }
        // ---------------------------------------------------

        // DEBUG: Log each show being processed. We must keep track of their every move.
        write_to_debug_log($debug_log_file, "Processing TV Show: " . (string)$show_xml['title'] . " (Key: " . (string)$show_xml['ratingKey'] . ")");

        $show_key = (string)$show_xml['ratingKey'];
        $total_duration_ms = 0;
        $total_file_size_bytes = 0.0;
        $seasons_data = [];

        // Collect Main Show Metadata (including summary) and cache the poster.
        $main_show_thumb_path = (string)$show_xml['thumb'];
        $local_thumb_url = download_and_cache_cover(
            $main_show_thumb_path,
            $plex_ip_port,
            $plex_token,
            $covers_local_dir,
            $covers_subfolder,
            'poster',
            $used_image_files // Pass the array
        );
		
		usleep(5000); // 5ms delay (In case Plex needs a breather)
		
        $show_summary = (string)$show_xml['summary'];

        // Fetch all Episodes for this specific Show
        // 'allLeaves' gives us the complete episode list, which is far faster than iterating through seasons.
        $xml_episodes_string = fetch_plex_data("/library/metadata/$show_key/allLeaves", $plex_ip_port, $plex_token);
		
		usleep(5000); // 5ms delay (In case Plex needs a breather)
		
        if (!$xml_episodes_string) continue;

        $xml_episodes = simplexml_load_string($xml_episodes_string);

        $seasons_aggregation = [];

        // Process Episodes for file info and aggregation
        foreach ($xml_episodes->Video as $episode_xml) {
            $season_num = (int)$episode_xml['parentIndex'];
            $episode_num = (int)$episode_xml['index'];

            $episode_duration = (int)$episode_xml['duration'];
            $episode_size = 0.0;
            
            // --- Data Extraction: Watched Status (Episode Level) ---
            global $calculate_watch_status;
            $episode_watched_status = 0; // Default: Not Watched
            
            if ($calculate_watch_status) {
                $view_count = (int)$episode_xml['viewCount'];
                $view_offset = (int)$episode_xml['viewOffset'];

                if ($view_count > 0 && $view_offset == 0) {
                    $episode_watched_status = 2; // Fully Watched
                } elseif ($view_offset > 0) {
                    $episode_watched_status = 1; // Partially Watched
                }
            } else {
                // Default behavior when watch status mode is set to 0:
                // Force status to 0 (Not Watched) for all episodes.
                $episode_watched_status = 0; 
            }
            // ---------------------------------------------------


            // Episode size is buried deep in the XML. Dig it out!
            if (isset($episode_xml->Media[0])) {
                $media = $episode_xml->Media[0];
                if (isset($media->Part[0])) {
                    // CRITICAL: Must be float!
                    $episode_size = (float)(string)$media->Part[0]['size'];
                }
            }

            // Aggregate totals
            $total_duration_ms += $episode_duration;
            $total_file_size_bytes += $episode_size;

            // DEBUG: Log each episode being aggregated
            write_to_debug_log($debug_log_file, "Aggregating Episode S{$season_num}E{$episode_num}: " . (string)$episode_xml['title']);

            // Aggregate by season
            $seasons_aggregation[$season_num]['duration_ms'] = ($seasons_aggregation[$season_num]['duration_ms'] ?? 0) + $episode_duration;
            $seasons_aggregation[$season_num]['file_size_bytes'] = ($seasons_aggregation[$season_num]['file_size_bytes'] ?? 0.0) + $episode_size;

            // Build Episode Data (Simplified)
            $episode_data = [
                'episodeNumber' => $episode_num,
                'title' => (string)$episode_xml['title'],
                'duration' => format_duration($episode_duration),
                'fileSizeGB' => format_file_size_gb($episode_size),
                'w' => $episode_watched_status // <<--- WATCHED STATUS
            ];
            // Store detailed episode data back into aggregation
            $seasons_aggregation[$season_num]['episodes'][] = $episode_data;
        }

        // Structure Season Data
        ksort($seasons_aggregation); // Sort seasons numerically (just good practice)
        foreach ($seasons_aggregation as $season_num => $season_agg) {

            $seasons_data[] = [
                'seasonNumber' => $season_num,
                'seasonduration' => format_duration($season_agg['duration_ms']),
                'seasonFileSizeGB' => format_file_size_gb($season_agg['file_size_bytes']),
                'episodes' => $season_agg['episodes'], // Full episode list
            ];
        }

        $raw_rating = (string)$show_xml['contentRating'];
        write_to_debug_log($debug_log_file, "DEBUG: Raw contentRating: " . $raw_rating);
        $clean_rating = sanitize_content_rating($raw_rating);
        write_to_debug_log($debug_log_file, "DEBUG: Sanitized contentRating: " . $clean_rating);
        
        // Build Show Data (The glorious summary)
        $library_data[] = [
            'type' => 'show',
            'title' => (string)$show_xml['title'],
            'tagline' => (string)$show_xml['tagline'],
            'summary' => $show_summary,
            'year' => (string)$show_xml['year'],
            'studio' => (string)$show_xml['studio'],
            'contentRating' => $clean_rating,
            'totalduration' => format_duration($total_duration_ms),
            'totalFileSizeGB' => format_file_size_gb($total_file_size_bytes),
            'totalSeasons' => count($seasons_data),
            'thumb_url' => $local_thumb_url,
            'seasons' => $seasons_data,
            'w' => $show_watched_status // <<--- WATCHED STATUS
        ];

    }

    return $library_data;
}

// =====================================================================
// Scraping Module: Music (The Server Crash Trigger)
// =====================================================================

function process_music($library_key, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, &$used_image_files) {
    global $debug_log_file, $debug_output_enabled;
    // --- Fetch ALL Albums (The quickest way to get all the artists) ---
    $albums_endpoint = "/library/sections/$library_key/albums?X-Plex-Container-Size=0";
    $xml_albums_string = fetch_plex_data($albums_endpoint, $plex_ip_port, $plex_token);
    
    // CRITICAL: If the main data fetch fails, stop and alert!
    if (!$xml_albums_string) {
        write_to_debug_log($debug_log_file, "CRITICAL FAIL: Failed to fetch ALL albums for key $library_key.", true);
        fwrite(STDERR, "ERROR: Failed to fetch primary data for Music library.\n");
        exit(1);
    }

    $xml_albums = simplexml_load_string($xml_albums_string);

    $artists_data = []; // This will be the final, grouped structure.
    echo "Processing 'Albums'... (Hold my drink)\n";


    write_to_debug_log($debug_log_file, "MUSIC: Found " . count($xml_albums->Directory) . " total albums in XML response for key $library_key.");

    // --- Loop through ALL Albums for Grouping and Tracks ---
    foreach ($xml_albums->Directory as $album_xml) {
        if ((string)$album_xml['type'] !== 'album') continue;
        $artist_key = (string)$album_xml['parentRatingKey'];
        $artist_name = (string)$album_xml['parentTitle'];

        // Initialize the artist's totals and structure if this is the first time we see them
        if (!isset($artists_data[$artist_key])) {

            write_to_debug_log($debug_log_file, "Processing Artist: {$artist_name} (Key: {$artist_key})"); // DEBUG: Log artist processing start

            $artists_data[$artist_key] = [
                'type' => 'music_artist',
                'title' => $artist_name,
                'ratingKey' => $artist_key,
                'year' => (string)$album_xml['parentYear'] ?? '',
                'totalDurationMS' => 0,
                'totalFileSizeBytes' => 0.0,
                'totalAlbums' => 0,
                'albums' => [],
                'thumb_url' => 'assets/images/placeholder.webp',
            ];

            // Fetch and cache the Artist Poster once per artist.
            $main_artist_thumb_path = (string)$album_xml['parentThumb'] ?? '';
            if ($main_artist_thumb_path) {
                 $artists_data[$artist_key]['thumb_url'] = download_and_cache_artist_art(
                     $main_artist_thumb_path,
                     $plex_ip_port,
                     $plex_token,
                     $covers_local_dir,
                     $covers_subfolder,
                     $used_image_files
                 );
            }
        }

        // Album details
        $current_album_number = $artists_data[$artist_key]['totalAlbums'] + 1;
        $album_key = (string)$album_xml['ratingKey'];
        $album_title = (string)$album_xml['title'];
		$safe_album_title_for_id = str_replace("'", "\'", $album_title);
        $track_container_id = 'album-tracks-' . $album_key . '-' . $safe_album_title_for_id;
        $album_thumb_path = (string)$album_xml['thumb'];

        // DEBUG: Album log
        $log_message = "Processing Album: {$album_title} (Key: {$album_key}) - Artist: {$artist_name}";
        write_to_debug_log($debug_log_file, $log_message);

        $album_duration_ms = 0;
        $album_file_size_bytes = 0.0;
        $tracks_data = [];

        // Cache Album Art (The album covers are usually fine)
        $local_album_art_url = download_and_cache_album_art($album_thumb_path, $plex_ip_port, $plex_token, $covers_local_dir, $covers_subfolder, $used_image_files);

        // Fetch all Tracks for this Album
        // HIGH-PRECISION DEBUG: Log the tracks API call right before execution.
        write_to_debug_log($debug_log_file, "Attempting Tracks API Call for Album Key: " . $album_key);

        $xml_tracks_string = fetch_plex_data("/library/metadata/$album_key/children?X-Plex-Container-Size=0", $plex_ip_port, $plex_token);
		
        usleep(5000); // 5ms delay (In case Plex needs a breather)

        if (!$xml_tracks_string) {
            write_to_debug_log($debug_log_file, "WARNING: Failed to fetch tracks for album: $album_title by $artist_name. Skipping album.", true);
            echo "WARNING: Failed to fetch tracks for album: $album_title by $artist_name. Skipping album.\n";
            continue;
        }

        $xml_tracks = simplexml_load_string($xml_tracks_string);
        // Process Tracks for aggregation
        foreach ($xml_tracks->Track as $track_xml) {

            // DEBUG: Log each track being processed. Yes, every single one.
            write_to_debug_log($debug_log_file, "Processing Track: " . (string)$track_xml['title'] . " (Track: " . (string)$track_xml['index'] . ")");

            $track_duration = (int)$track_xml['duration'];
            $track_size = 0.0;

            // Extract Disc Number (Plex uses 'parentIndex' on the track metadata)
            $disc_number = (int)$track_xml['parentIndex'] ?: 1;

            if (isset($track_xml->Media[0]->Part[0])) {
                $track_size = (float)(string)$track_xml->Media[0]->Part[0]['size'];
            }

            // Aggregate totals
            $album_duration_ms += $track_duration;
            $album_file_size_bytes += $track_size;

            // Handle track title for "Various Artists" (Compilation) - The unsung heroes get credit here.
            $track_title = (string)$track_xml['title'];
            $track_artist_tag = '';

            // Check if the album is a compilation and try to find the track artist tag.
            $track_title = (string)$track_xml['title'];
            $track_artist_tag = '';

            // Check if the album is a compilation
            if ($artist_name === 'Various Artists') {
    
                // --- Check for the common <Role> tag ---
                if (isset($track_xml->Role[0])) {
                    $track_artist_tag = (string)$track_xml->Role[0]['tag'];
                } 
                // --- Check for the track-level <Artist> tag ---
                elseif (isset($track_xml->Artist[0])) {
                    $track_artist_tag = (string)$track_xml->Artist[0]['tag'];
                }
                // --- Check for the track's grandparentTitle (Common fallback for tracks) ---
                elseif (!empty((string)$track_xml['grandparentTitle']) && (string)$track_xml['grandparentTitle'] !== $artist_name) {
                    // Only use grandparentTitle if it's NOT the same as the main 'Various Artists' name
                    $track_artist_tag = (string)$track_xml['grandparentTitle'];
                }
                // --- Fallback: Check the less common originalTitle ---
                elseif (!empty((string)$track_xml['originalTitle'])) {
                    $track_artist_tag = (string)$track_xml['originalTitle'];
                }
    
                // If we successfully found a track artist, prepend it to the title.
                if (!empty($track_artist_tag)) {
                    // Ensure the artist name doesn't contain the track title already
                    if (stripos($track_title, $track_artist_tag) === false) {
                         $track_title = "$track_artist_tag - $track_title";
                    } else {
                         // If the artist is already in the title, just use the title as is.
                         $track_title = (string)$track_xml['title'];
                    }
                }
            }

            $safe_track_title = htmlspecialchars($track_title, ENT_QUOTES | ENT_HTML5);

            // Build Track Data (using MB for precision on small files)
            $tracks_data[] = [
                'discNumber' => $disc_number,
                'trackNumber' => (int)$track_xml['index'],
                'title' => $safe_track_title,
                'duration' => format_duration($track_duration),
                'fileSizeMB' => format_file_size_mb($track_size),
            ];
			
        }

        // Structure Album Data and add to Artist Group
        $albums_data = [
            'albumNumber' => $current_album_number,
            'albumTitle' => $album_title,
            'year' => (string)$album_xml['year'],
            'albumduration' => format_duration($album_duration_ms),
            'albumFileSizeGB' => format_file_size_gb($album_file_size_bytes),
            'thumb_url' => $local_album_art_url,
            'tracks' => $tracks_data,
        ];

        // Update the grand total counts for the artist
        $artists_data[$artist_key]['albums'][] = $albums_data;
        $artists_data[$artist_key]['totalAlbums']++;
        $artists_data[$artist_key]['totalDurationMS'] += $album_duration_ms;
        $artists_data[$artist_key]['totalFileSizeBytes'] += $album_file_size_bytes;
    }

    // --- Final Artist Formatting ---
    $library_data = [];
    foreach ($artists_data as $artist_key => $artist_data) {

    // ===  SORTING  ===
    usort($artist_data['albums'], function($a, $b) {
    // FUse strnatcmp for a human/numerical sort, which correctly handles 1, 2, 10, 11.
    return strnatcmp($a['albumTitle'], $b['albumTitle']);
    });
    //========================

        $library_data[] = [
            'type' => 'music_artist',
            'title' => $artist_data['title'],
            'year' => $artist_data['year'],
            'totalduration' => format_duration($artist_data['totalDurationMS']),
            'totalFileSizeGB' => format_file_size_gb($artist_data['totalFileSizeBytes']),
            'totalAlbums' => $artist_data['totalAlbums'],
            'thumb_url' => $artist_data['thumb_url'],
            'albums' => $artist_data['albums'],
        ];
        
    }

    return $library_data;
}

// =====================================================================
// Output Functions (The Grand Finalizer)
// =====================================================================

/**
 * Writes the final JSON object, including the library map, to the output file.
 */
function write_json_output($output_json_file, $library_data, $library_map, $website_header, $export_start_time) {
    global $debug_log_file; // <-- FIX: This was missing and caused the errors.
    
    // Create the final combined data structure. All your base are belong to us.
    $final_output = [
	    'websiteHeader' => $website_header,
		'exportDate' => $export_start_time, // When the scraping started (the last known good time)
        'libraries' => $library_map, // The lookup map goes here
        'items' => $library_data    // The main list of items (now using libraryId)
    ];
   // if (file_put_contents($output_json_file, json_encode($final_output, JSON_PRETTY_PRINT)) !== false) {
   if (file_put_contents($output_json_file, json_encode($final_output)) !== false) {
        echo "SUCCESS: Combined data written to $output_json_file. Go forth and bask in your metadata.\n";
    } else {
        write_to_debug_log($debug_log_file, "CRITICAL FAIL: Failed to write JSON file to $output_json_file. Check file permissions on the web folder. Seriously, check them.", true);
        fwrite(STDERR, "ERROR: Failed to write JSON file. Check file permissions on the web folder.");
        exit(1);
    }
}

// =====================================================================
// MAIN EXECUTION (The Choreographer)
// =====================================================================

echo "Starting Plex library synchronization... (Hold tight)\n";
// Discover all libraries and their details on the Plex server
$all_plex_libraries = discover_plex_libraries($plex_ip_port, $plex_token);
    if ($all_plex_libraries === false) {
    fwrite(STDERR, "CRITICAL: Failed to connect to Plex or retrieve library sections. Check IP/Port/Token/Firewall. Is Plex even running?\n");
    exit(1);
    }

    // DEBUG: Log the successful discovery of libraries
    write_to_debug_log($debug_log_file, "Library discovery successful. Found " . count($all_plex_libraries) . " libraries. We know what you have.");

// Prepare the list of requested library titles from the command line argument
$requested_titles = array_map('trim', explode(',', $library_titles_csv));
    $libraries_to_process = [];
    $library_map = []; // Map to store {id: {name, type}} for the frontend lookup
    $library_id_counter = 1;

// Match requested titles to discovered libraries, and build the dynamic config array
foreach ($requested_titles as $title) {
    if (isset($all_plex_libraries[$title])) {
        $details = $all_plex_libraries[$title];
		
		// --- DYNAMIC RATING SYSTEM DISCOVERY ---
        $rating_system = get_library_rating_system(
            $plex_ip_port, 
            $plex_token, 
            $details['key']
        );
		
        $library_id = $library_id_counter++; // Assign a unique, incrementing ID

        // Populate the library lookup map
        $library_map[$library_id] = [
            'name' => $title,
            'type' => $details['internal_type'],
			'certificationCountry' => $rating_system
        ];
        // Build the configuration array for the scraping function
        $libraries_to_process[] = [
            'title' => $title,
            'type' => $details['internal_type'],
            'key' => $details['key'],
            'id' => $library_id // The unique ID
        ];
    } else {
        // DEBUG: Log skipped library
        write_to_debug_log($debug_log_file, "WARNING: Requested library title '$title' not found on Plex server. Skipping. Check your spelling!", true);
        echo "WARNING: Requested library title '$title' not found on Plex server. Skipping.\n";
    }
}

if (empty($libraries_to_process)) {
    fwrite(STDERR, "CRITICAL: No valid Plex libraries were found from the list: '$library_titles_csv'. Check the titles against your Plex server library titles. Read the documentation!\n");
    exit(1);
}

$combined_library_data = [];

// Run the scraping process using the dynamically built config
foreach ($libraries_to_process as $config) {
    $data = scrape_library($config, $plex_ip_port, $plex_token, $public_web_root);
    $combined_library_data = array_merge($combined_library_data, $data);
}

// Write the final JSON, including the map
write_json_output($output_json_file, $combined_library_data, $library_map, $website_header, $export_start_time);
echo "Synchronization complete. Check $output_json_file. Congratulations, you win!\n";
?>