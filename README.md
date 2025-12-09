# Plex Census Scraper (`plex_census.php`)

A robust PHP command-line script designed to extract comprehensive metadata (a "census") from specific libraries on your Plex Media Server. The script processes this data and outputs it as a structured **JSON file** and accompanying optimized **image files** for use in a custom web interface or data analysis.

---

## 🛠️ Environment & Prerequisites

This script was developed and tested on the following setup:

* **PHP:** Version **7.3.3** (or later)
* **Platform:** Synology DSM 7.1
* **Execution Method:** Ran via Synology **Task Scheduler**, using a User-defined script.
* **Plex Authentication Token:** Required for API access.

---

## 🚀 Usage

The script is executed via the command line, requiring at least five positional arguments to define the connection, target libraries, and output location.

Place the contents of **<web>** in the web **<WEB_ROOT_PATH>** location.

### Command Syntax

php '<script_location>/plex_census.php' '<PLEX_IP:PORT>' '<PLEX_TOKEN>' '<WEBSITE_HEADER>' '<LIBRARY_TITLES_CSV>' '<WEB_ROOT_PATH>' [<WATCH_STATUS_MODE>]

| Argument | Description | Required? | Example Value |
| :--- | :--- | :--- | :--- |
| **1. `<PLEX_IP:PORT>`** | The IP address and port of your Plex Media Server. | **Yes** | `'10.0.1.105:32400'` |
| **2. `<PLEX_TOKEN>`** | Your Plex authentication token. | **Yes** | `'abcd1234efgh5678'` |
| **3. `<WEBSITE_HEADER>`** | The main title string to be included in the output JSON. | **Yes** | `'My Plex Catalogue'` |
| **4. `<LIBRARY_TITLES_CSV>`** | A **comma-separated list** of the *exact titles* of the Plex libraries to process (e.g., `Films,TV,Music`). | **Yes** | `'Films,TV,Music'` |
| **5. `<WEB_ROOT_PATH>`** | The absolute path where the output files and data directories will be created. | **Yes** | `'/volume1/web/plex-list/'` |
| **6. `<WATCH_STATUS_MODE>`** | **Optional** flag. Set to **`1`** to include watch status (0/1/2) in the output data. | **No** | `1` |

### Full Example (Synology Task Scheduler)

php '/volume1/Scripts/plex_census/plex_census.php' '10.0.1.105:32400' '<YOUR_TOKEN>' 'My Plex Catalogue' 'Films,TV,Music' '/volume1/web/plex-list/' 1

### Finding Your Token

For a guide on securely obtaining your Plex Authentication Token (`X-Plex-Token`), please refer to the official Plex support documentation:

[Finding an Authentication Token](https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/)

---

## 📂 Output Structure

The script generates the following key outputs within the specified `<WEB_ROOT_PATH>`:

1.  **Metadata JSON:**
    * **File:** `<WEB_ROOT_PATH>/data/library.json`
    * **Content:** The core JSON data containing all scraped metadata.

2.  **Image Files:**
    * **Location:** `<WEB_ROOT_PATH>/data/covers/<library-name>/`
    * **Content:** Optimized poster and background images in both **AVIF** and, optionally, **JPEG** formats.

3.  **Log File:**
    * **File:** `debug.log`
    * **Location:** Saved in the same directory as the `plex_census.php` script itself (not the web root). Contains warnings and debug information if enabled.

---

## ⚙️ Advanced Configuration

You can modify several variables directly at the beginning of the `plex_census.php` script for fine-tuning:

| Variable | Default Value | Description |
| :--- | :--- | :--- |
| `$debug_output_enabled` | `false` | Set to `true` to enable verbose logging to the `debug.log` file. |
| `$jpeg_output_enabled` | `true` | Set to `true` to enable parallel JPEG output for images (useful for older device support/fallback). |
| `$avif_quality` | `30` | The AVIF quality setting to request from Plex (Available values: `-1` to `127`). |
| `$jpeg_quality` | `70` | The JPEG quality setting to request from Plex (Available values: `-1` to `127`). |