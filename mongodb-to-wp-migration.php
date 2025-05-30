<?php

namespace MongoWPMigration;

/**
 * MongoDB to WordPress Migration Script
 * 
 * This script migrates articles from a MongoDB database to a WordPress installation with WPML support.
 * It handles multilingual content, image downloads, and category mappings.
 * 
 * Enhanced features:
 * - Uses hardcoded mapping arrays for category assignment
 * - Tags imported posts with custom meta field for easy identification
 * - Filters imports by creation date
 * - Properly handles MongoDB date conversion
 * - Ensures posts are visible in WordPress admin dashboard
 * - Includes all required WordPress fields
 */

// Check if MongoDB extension is loaded
if (!extension_loaded('mongodb')) {
    die("MongoDB extension is not loaded. Please install the MongoDB PHP extension.");
}

// Require Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Verify MongoDB\Client class exists
if (!class_exists('\MongoDB\Client')) {
    die("MongoDB\Client class not found. Please run 'composer install' to install dependencies.");
}

// Load the mapping arrays
require_once __DIR__ . '/mapping_arrays.php';

// Ensure script execution time is sufficient for migration
ini_set('max_execution_time', 3600); // 1 hour
ini_set('memory_limit', '512M');     // 512MB memory limit

// Configuration - Edit these values
$config = [
    // MongoDB connection
    'mongo_uri' => 'mongodb://193.203.191.187:27017',
    'auth_source' => 'future-project',
    'mongo_db' => 'future-project',
    'mongo_user' => 'ed_sfm',
    'mongo_pass' => 'b84m9FjK1n3phU9HdsoJA86QrLXqwePJOqH1YHAiJU5Ee5EgnFjTts6faXUrrBIl',
    'mongo_collection' => 'articles',

    // WordPress database connection
    'wp_host' => '193.203.191.187:3000',
    'wp_db' => 'default',
    'wp_user' => 'mariadb',
    'wp_pass' => 'AnD0HD6sxBy4PEMmj4zs1A0jBXYLB8NLwV2giHJR8nzOVICP9tfJruIM8FxIYqjA',
    'wp_prefix' => 'wp_',

    // WordPress admin user ID for post author
    'wp_author_id' => 1,

    // Image handling
    'old_image_domain' => 'storage.xposuredevlabs.com',
    'new_image_domain' => 'storage.sfuturem.org',
    'image_download_path' => '/tmp/wp-migration-images/',
    'wp_uploads_dir' => '/var/www/html/wp-content/uploads/', // WordPress uploads directory (absolute path)
    'wp_uploads_url' => 'https://sfuturem.org/wp-content/uploads/', // WordPress uploads URL base

    // Languages
    'languages' => ['en', 'ar'],
    'default_language' => 'en',

    // Reporting
    'report_file' => 'migration_report.csv',
    'log_file' => 'migration_log.txt',
    'verbose' => true,

    // Import tagging
    'import_tag_meta_key' => '_mongodb_sfm_imported',
    'import_tag_meta_value' => 'imported_' . date('Y-m-d'),

    // Date filtering
    'import_date_filter' => '2025-03-11', // Only import posts created on or after this date (YYYY-MM-DD)
    'use_date_filter' => true, // Set to false to import all posts regardless of date
];

// Initialize global variables
$report = [];
$log = [];
$wpdb = null;
$mongoClient = null;

/**
 * Main execution function
 */
function main()
{
    global $config, $report, $log;

    // Start time for performance tracking
    $startTime = microtime(true);

    try {
        // Initialize connections
        initializeConnections();

        // Verify mapping arrays are loaded
        verifyMappingArrays();

        // Create image download directory if it doesn't exist
        if (!file_exists($config['image_download_path'])) {
            mkdir($config['image_download_path'], 0755, true);
        }

        // Start migration process
        logMessage("Starting migration process...");
        if ($config['use_date_filter']) {
            logMessage("Date filter enabled: Only importing posts created on or after {$config['import_date_filter']}");
        }
        migrateArticles();

        // Generate final report
        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 2);

        logMessage("Migration completed in {$executionTime} seconds.");
        generateReport();

        echo "Migration completed successfully. See {$config['report_file']} for details.\n";
    } catch (\Exception $e) {
        logError("Fatal error: " . $e->getMessage());
        echo "Migration failed: " . $e->getMessage() . "\n";
    }
}

/**
 * Initialize database connections
 */
function initializeConnections()
{
    global $config, $wpdb, $mongoClient;

    try {
        // Connect to MongoDB
        logMessage("Connecting to MongoDB...");
        $mongoClient = new \MongoDB\Client($config['mongo_uri'], [
            'authSource' => $config['auth_source'],
            'username' => $config['mongo_user'] ?? null,
            'password' => $config['mongo_pass'] ?? null,
        ]);
        logMessage("MongoDB connection established.");

        // Connect to WordPress database
        logMessage("Connecting to WordPress database...");
        $wpdb = new \mysqli(
            $config['wp_host'],
            $config['wp_user'],
            $config['wp_pass'],
            $config['wp_db']
        );

        if ($wpdb->connect_error) {
            throw new \Exception("WordPress database connection failed: " . $wpdb->connect_error);
        }

        logMessage("WordPress database connection established.");
    } catch (\Exception $e) {
        throw new \Exception("Connection initialization failed: " . $e->getMessage());
    }
}

/**
 * Verify that mapping arrays are loaded
 */
function verifyMappingArrays()
{
    global $officeMapping, $departmentMapping;

    if (!isset($officeMapping) || !is_array($officeMapping) || count($officeMapping) === 0) {
        throw new \Exception("Office mapping array is not properly loaded. Check mapping_arrays.php file.");
    }

    if (!isset($departmentMapping) || !is_array($departmentMapping) || count($departmentMapping) === 0) {
        throw new \Exception("Department mapping array is not properly loaded. Check mapping_arrays.php file.");
    }

    logMessage("Mapping arrays loaded successfully: " . count($officeMapping) . " offices and " . count($departmentMapping) . " departments.");
}

/**
 * Migrate articles from MongoDB to WordPress
 */
function migrateArticles()
{
    global $config, $mongoClient, $report;

    $collection = $mongoClient->{$config['mongo_db']}->{$config['mongo_collection']};

    // Build query with date filter if enabled
    $query = ['state' => 'published'];
    if ($config['use_date_filter'] && !empty($config['import_date_filter'])) {
        $dateFilter = new \MongoDB\BSON\UTCDateTime(strtotime($config['import_date_filter']) * 1000);
        $query['dateOfPublished'] = ['$gte' => $dateFilter];
        logMessage("Applied date filter: dateOfPublished >= {$config['import_date_filter']}");
    }

    $cursor = $collection->find($query);
    $totalArticles = $collection->countDocuments($query);

    logMessage("Found $totalArticles articles to migrate.");

    $processed = 0;
    $successful = 0;
    $failed = 0;

    foreach ($cursor as $article) {
        $processed++;
        $articleId = (string)$article['_id'];

        logMessage("Processing article $processed/$totalArticles: $articleId");

        try {
            // Check if article has required fields
            validateArticle($article);

            // Process article
            $result = processArticle($article);

            if ($result['success']) {
                $successful++;
                logMessage("Successfully migrated article: $articleId");
            } else {
                $failed++;
                logMessage("Failed to migrate article: $articleId - " . $result['message']);
            }

            // Add to report
            $report[] = [
                'mongo_id' => $articleId,
                'wp_id_en' => $result['wp_id_en'] ?? '',
                'wp_id_ar' => $result['wp_id_ar'] ?? '',
                'status' => $result['success'] ? 'Success' : 'Failed',
                'message' => $result['message'],
                'title_en' => $article['title']['en'] ?? '',
                'title_ar' => $article['title']['ar'] ?? '',
            ];
        } catch (\Exception $e) {
            $failed++;
            logError("Error processing article $articleId: " . $e->getMessage());

            // Add to report
            $report[] = [
                'mongo_id' => $articleId,
                'wp_id_en' => '',
                'wp_id_ar' => '',
                'status' => 'Failed',
                'message' => $e->getMessage(),
                'title_en' => $article['title']['en'] ?? '',
                'title_ar' => $article['title']['ar'] ?? '',
            ];
        }

        // Progress update every 10 articles
        if ($processed % 10 === 0) {
            logMessage("Progress: $processed/$totalArticles articles processed. Success: $successful, Failed: $failed");
        }
    }

    logMessage("Migration completed. Total: $totalArticles, Success: $successful, Failed: $failed");
}

/**
 * Validate article has required fields
 * 
 * @param array $article MongoDB article document
 * @throws \Exception If article is missing required fields
 */
function validateArticle($article)
{
    $requiredFields = [
        'title',
        'slug',
        'description',
        'dateOfPublished'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($article[$field])) {
            throw new \Exception("Article is missing required field: $field");
        }
    }

    // Check language fields
    foreach (['title', 'slug', 'description'] as $field) {
        if (!isset($article[$field]['en']) || !isset($article[$field]['ar'])) {
            throw new \Exception("Article is missing language version for field: $field");
        }
    }
}

/**
 * Process a single article
 * 
 * @param array $article MongoDB article document
 * @return array Result with success status and message
 */
function processArticle($article)
{
    global $config;

    try {
        // Start with default language (English)
        $defaultLang = $config['default_language'];

        // Create post in default language
        $postIdDefault = createWordPressPost($article, $defaultLang);

        if (!$postIdDefault) {
            return [
                'success' => false,
                'message' => "Failed to create post in $defaultLang language"
            ];
        }

        $result = [
            'success' => true,
            'message' => "Successfully migrated article",
            'wp_id_' . $defaultLang => $postIdDefault
        ];

        // Create translations
        foreach ($config['languages'] as $lang) {
            if ($lang === $defaultLang) {
                continue;
            }

            $translationId = createWordPressTranslation($article, $lang, $postIdDefault, $defaultLang);

            if ($translationId) {
                $result['wp_id_' . $lang] = $translationId;
            } else {
                $result['message'] .= " (Warning: Failed to create $lang translation)";
            }
        }

        return $result;
    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Convert MongoDB date to MySQL datetime format
 * 
 * @param mixed $mongoDate MongoDB date object
 * @return string MySQL datetime string
 */
function convertMongoDateToMysql($mongoDate)
{
    if (!isset($mongoDate['$date'])) {
        throw new \Exception("Invalid MongoDB date format");
    }

    // Check if it's milliseconds timestamp (integer) or ISO string
    if (is_int($mongoDate['$date'])) {
        // It's a milliseconds timestamp
        $timestamp = floor($mongoDate['$date'] / 1000); // Convert milliseconds to seconds
    } else {
        // It's an ISO date string
        $timestamp = strtotime($mongoDate['$date']);
    }

    if (!$timestamp) {
        throw new \Exception("Failed to parse MongoDB date: " . json_encode($mongoDate));
    }

    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Create WordPress post from MongoDB article
 * 
 * @param array $article MongoDB article document
 * @param string $language Language code (en/ar)
 * @return int|false WordPress post ID or false on failure
 */
function createWordPressPost($article, $language)
{
    global $wpdb, $config, $officeMapping, $departmentMapping;

    try {
        // Begin transaction
        $wpdb->query('START TRANSACTION');

        // Prepare post data with proper date conversion
        try {
            $postDate = convertMongoDateToMysql($article['dateOfPublished']);
            $postModified = convertMongoDateToMysql($article['updatedAt']);
        } catch (\Exception $e) {
            logError("Date conversion error: " . $e->getMessage() . ". Using current date instead.");
            $postDate = date('Y-m-d H:i:s');
            $postModified = date('Y-m-d H:i:s');
        }

        $postData = [
            'post_title' => $article['title'][$language],
            'post_content' => $article['description'][$language],
            'post_excerpt' => '', // Add empty post_excerpt
            'post_name' => $article['slug'][$language],
            'post_date' => $postDate,
            'post_date_gmt' => $postDate,
            'post_modified' => $postModified,
            'post_modified_gmt' => $postModified,
            'post_status' => 'publish',
            'post_author' => $config['wp_author_id'],
            'post_type' => 'post',
            'comment_status' => 'open',
            'ping_status' => 'open',
        ];

        // Insert post
        $wpdb->query("INSERT INTO {$config['wp_prefix']}posts 
            (post_title, post_content, post_excerpt, to_ping, pinged, post_content_filtered, post_name, post_date, post_date_gmt, 
             post_modified, post_modified_gmt, post_status, post_author, 
             post_type, comment_status, ping_status)
            VALUES 
            ('{$wpdb->escape_string($postData['post_title'])}', 
             '{$wpdb->escape_string($postData['post_content'])}', 
             '', /* Add empty post_excerpt */
             '', /* Add empty to_ping */
             '', /* Add empty pinged */
             '', /* Add empty post_content_filtered */
             '{$wpdb->escape_string($postData['post_name'])}', 
             '{$postData['post_date']}', 
             '{$postData['post_date_gmt']}', 
             '{$postData['post_modified']}', 
             '{$postData['post_modified_gmt']}', 
             '{$postData['post_status']}', 
             {$postData['post_author']}, 
             '{$postData['post_type']}', 
             '{$postData['comment_status']}', 
             '{$postData['ping_status']}')");

        $postId = $wpdb->insert_id;

        if (!$postId) {
            throw new \Exception("Failed to insert post: " . $wpdb->last_error);
        }

        // Update GUID (WordPress uses this for permalinks)
        $guid = "https://sfuturem.org/?p=$postId"; // Replace with actual site URL if available
        $wpdb->query("UPDATE {$config['wp_prefix']}posts SET guid = '$guid' WHERE ID = $postId");

        // Add custom meta field to tag imported posts
        $wpdb->query("INSERT INTO {$config['wp_prefix']}postmeta 
            (post_id, meta_key, meta_value) 
            VALUES 
            ($postId, '{$config['import_tag_meta_key']}', '{$config['import_tag_meta_value']}')");

        // Add MongoDB ID as meta for reference
        $mongoId = (string)$article['_id'];
        $wpdb->query("INSERT INTO {$config['wp_prefix']}postmeta 
            (post_id, meta_key, meta_value) 
            VALUES 
            ($postId, '_mongodb_id', '{$mongoId}')");

        // Set WPML language
        setPostLanguage($postId, $language);

        // Process featured image
        if (isset($article['image'][$language])) {
            $imageUrl = $article['image'][$language];
            $imageId = processAndAttachImage($imageUrl, $postId, $language, $postDate);

            if ($imageId) {
                // Set as featured image
                $wpdb->query("INSERT INTO {$config['wp_prefix']}postmeta 
                    (post_id, meta_key, meta_value) 
                    VALUES 
                    ($postId, '_thumbnail_id', $imageId)");
            }
        }

        // Process categories (offices and departments)
        $termIds = [];

        // Add offices using the hardcoded mapping array
        if (isset($article['offices']) && is_array($article['offices'])) {
            foreach ($article['offices'] as $office) {
                $officeId = (string)$office['$oid'];
                if (isset($officeMapping[$officeId][$language]) && $officeMapping[$officeId][$language]) {
                    $termIds[] = $officeMapping[$officeId][$language];
                    logMessage("Added office term ID {$officeMapping[$officeId][$language]} for office $officeId in $language");
                } else {
                    logMessage("No mapping found for office $officeId in $language");
                }
            }
        }

        // Add departments using the hardcoded mapping array
        if (isset($article['departments']) && is_array($article['departments'])) {
            foreach ($article['departments'] as $department) {
                $departmentId = (string)$department['$oid'];
                if (isset($departmentMapping[$departmentId][$language]) && $departmentMapping[$departmentId][$language]) {
                    $termIds[] = $departmentMapping[$departmentId][$language];
                    logMessage("Added department term ID {$departmentMapping[$departmentId][$language]} for department $departmentId in $language");
                } else {
                    logMessage("No mapping found for department $departmentId in $language");
                }
            }
        }

        // Assign categories
        foreach ($termIds as $termId) {
            if ($termId) {
                // Get term_taxonomy_id
                $result = $wpdb->get_row("SELECT term_taxonomy_id FROM {$config['wp_prefix']}term_taxonomy 
                    WHERE term_id = $termId AND taxonomy = 'category'");

                if ($result && isset($result->term_taxonomy_id)) {
                    $termTaxonomyId = $result->term_taxonomy_id;

                    // Insert term relationship
                    $wpdb->query("INSERT INTO {$config['wp_prefix']}term_relationships 
                        (object_id, term_taxonomy_id, term_order) 
                        VALUES 
                        ($postId, $termTaxonomyId, 0)");

                    // Update term count
                    $wpdb->query("UPDATE {$config['wp_prefix']}term_taxonomy 
                        SET count = count + 1 
                        WHERE term_taxonomy_id = $termTaxonomyId");
                }
            }
        }

        // Commit transaction
        $wpdb->query('COMMIT');

        return $postId;
    } catch (\Exception $e) {
        // Rollback transaction on error
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

/**
 * Create WordPress translation post
 * 
 * @param array $article MongoDB article document
 * @param string $language Language code for translation
 * @param int $originalPostId Original post ID
 * @param string $originalLang Original language code
 * @return int|false WordPress post ID or false on failure
 */
function createWordPressTranslation($article, $language, $originalPostId, $originalLang)
{
    // Create post in translation language
    $translationId = createWordPressPost($article, $language);

    if ($translationId) {
        // Link translations
        linkTranslations($originalPostId, $translationId, $originalLang, $language);
    }

    return $translationId;
}

/**
 * Set post language in WPML
 * 
 * @param int $postId WordPress post ID
 * @param string $language Language code
 * @return bool Success status
 */
function setPostLanguage($postId, $language)
{
    global $wpdb, $config;

    try {
        // Check if language is already set
        $exists = mysqli_num_rows($wpdb->query("SELECT COUNT(*) FROM {$config['wp_prefix']}icl_translations 
            WHERE element_id = $postId AND element_type = 'post_post'"));

        if ($exists) {
            // Update existing language
            $wpdb->query("UPDATE {$config['wp_prefix']}icl_translations 
                SET language_code = '$language' 
                WHERE element_id = $postId AND element_type = 'post_post'");
        } else {
            // Insert new language entry
            $wpdb->query("INSERT INTO {$config['wp_prefix']}icl_translations 
                (element_type, element_id, trid, language_code, source_language_code) 
                VALUES 
                ('post_post', $postId, NULL, '$language', NULL)");
        }

        return true;
    } catch (\Exception $e) {
        logError("Failed to set post language: " . $e->getMessage());
        return false;
    }
}

/**
 * Link translations in WPML
 * 
 * @param int $postId1 First post ID
 * @param int $postId2 Second post ID
 * @param string $lang1 First language code
 * @param string $lang2 Second language code
 * @return bool Success status
 */
function linkTranslations($postId1, $postId2, $lang1, $lang2)
{
    global $wpdb, $config;

    try {
        // Get translation row ID (trid) for first post
        $trid = mysqli_num_rows($wpdb->query("SELECT trid FROM {$config['wp_prefix']}icl_translations 
            WHERE element_id = $postId1 AND element_type = 'post_post'"));

        if (!$trid) {
            // Create new trid
            $wpdb->query("UPDATE {$config['wp_prefix']}icl_translations 
                SET trid = (SELECT MAX(trid) + 1 FROM {$config['wp_prefix']}icl_translations) 
                WHERE element_id = $postId1 AND element_type = 'post_post'");

            $trid = mysqli_num_rows($wpdb->query("SELECT trid FROM {$config['wp_prefix']}icl_translations 
                WHERE element_id = $postId1 AND element_type = 'post_post'"));
        }

        // Update second post with same trid and source language
        $wpdb->query("UPDATE {$config['wp_prefix']}icl_translations 
            SET trid = $trid, source_language_code = '$lang1' 
            WHERE element_id = $postId2 AND element_type = 'post_post'");

        return true;
    } catch (\Exception $e) {
        logError("Failed to link translations: " . $e->getMessage());
        return false;
    }
}

/**
 * Process and attach image to post
 * 
 * @param string $imageUrl Image URL
 * @param int $postId WordPress post ID
 * @param string $language Language code
 * @param string $postDate Post date (used for uploads directory structure)
 * @return int|false WordPress attachment ID or false on failure
 */
function processAndAttachImage($imageUrl, $postId, $language, $postDate)
{
    global $wpdb, $config;

    try {
        // Transform image URL
        $imageUrl = str_replace($config['old_image_domain'], $config['new_image_domain'], $imageUrl);

        // Extract filename from URL
        $filename = basename($imageUrl);
        $localPath = $config['image_download_path'] . $filename;

        // Download image
        if (!downloadFile($imageUrl, $localPath)) {
            throw new \Exception("Failed to download image: $imageUrl");
        }

        // Create WordPress uploads directory structure based on post date
        $dateObj = new \DateTime($postDate);
        $yearMonth = $dateObj->format('Y/m');
        $uploadsRelPath = $yearMonth;
        $uploadsAbsPath = $config['wp_uploads_dir'] . $uploadsRelPath;

        // Create directory if it doesn't exist
        if (!file_exists($uploadsAbsPath)) {
            if (!mkdir($uploadsAbsPath, 0755, true)) {
                throw new \Exception("Failed to create uploads directory: $uploadsAbsPath");
            }
            logMessage("Created uploads directory: $uploadsAbsPath");
        }

        // Move file to WordPress uploads directory
        $wpFilePath = $uploadsAbsPath . '/' . $filename;
        if (!copy($localPath, $wpFilePath)) {
            throw new \Exception("Failed to copy file to WordPress uploads directory: $wpFilePath");
        }
        logMessage("Copied image to WordPress uploads: $wpFilePath");

        // Set the relative path for WordPress
        $relativeFilePath = $uploadsRelPath . '/' . $filename;

        // Get file info
        $fileType = wp_check_filetype($filename);
        $attachment = [
            'post_mime_type' => $fileType['type'],
            'post_title' => sanitize_file_name($filename),
            'guid' => $config['wp_uploads_url'] . $relativeFilePath
        ];

        // Get image dimensions if possible
        $imageDimensions = getImageDimensions($wpFilePath);
        $width = $imageDimensions['width'] ?? 800;  // Default if can't determine
        $height = $imageDimensions['height'] ?? 600; // Default if can't determine

        // Insert attachment
        $wpdb->query("INSERT INTO {$config['wp_prefix']}posts 
            (post_title, post_content, post_excerpt, post_mime_type, post_status, post_type, post_parent, guid) 
            VALUES 
            ('{$wpdb->escape_string($attachment['post_title'])}', 
             '{$attachment['post_content']}', 
             '{$attachment['post_excerpt']}', 
             '{$attachment['post_mime_type']}', 
             '{$attachment['post_status']}', 
             'attachment', 
             $postId, 
             '{$attachment['guid']}')");

        $wpdb->query("INSERT INTO {$config['wp_prefix']}posts 
            (post_title, post_content, post_excerpt, to_ping, pinged, post_content_filtered, post_name, post_date, post_date_gmt, 
             post_modified, post_modified_gmt, post_status, post_author, 
             post_type, comment_status, ping_status, post_mime_type, post_parent, guid)
            VALUES 
            ('{$wpdb->escape_string($attachment['post_title'])}', 
             '', 
             '', /* post_excerpt */
             '', /* to_ping */
             '', /* pinged */
             '', /* post_content_filtered */
             '', /* post_name */
             '{$postDate}', 
             '{$postDate}', 
             '{$postDate}', 
             '{$postDate}', 
             'inherit', 
             {$config['wp_author_id']}, 
             'attachment', 
             'closed', 
             'closed',
             '{$attachment['post_mime_type']}', 
             $postId, 
             '{$attachment['guid']}')");

        $attachmentId = $wpdb->insert_id;

        if (!$attachmentId) {
            throw new \Exception("Failed to insert attachment: " . $wpdb->last_error);
        }

        // Add attachment metadata with proper WordPress format
        $attachMeta = [
            '_wp_attached_file' => $relativeFilePath,
            '_wp_attachment_metadata' => serialize([
                'width' => $width,
                'height' => $height,
                'file' => $relativeFilePath,
                'sizes' => [
                    'thumbnail' => [
                        'file' => $filename,
                        'width' => min(150, $width),
                        'height' => min(150, $height),
                        'mime-type' => $fileType['type'],
                    ],
                    'medium' => [
                        'file' => $filename,
                        'width' => min(300, $width),
                        'height' => min(300 * ($height / $width), $height),
                        'mime-type' => $fileType['type'],
                    ],
                ]
            ])
        ];

        foreach ($attachMeta as $key => $value) {
            $wpdb->query("INSERT INTO {$config['wp_prefix']}postmeta 
                (post_id, meta_key, meta_value) 
                VALUES 
                ($attachmentId, '$key', '{$wpdb->escape_string($value)}')");
        }

        // Tag attachment as imported
        $wpdb->query("INSERT INTO {$config['wp_prefix']}postmeta 
            (post_id, meta_key, meta_value) 
            VALUES 
            ($attachmentId, '{$config['import_tag_meta_key']}', '{$config['import_tag_meta_value']}')");

        // Set WPML language for attachment
        setPostLanguage($attachmentId, $language);

        return $attachmentId;
    } catch (\Exception $e) {
        logError("Failed to process image: " . $e->getMessage());
        return false;
    }
}

/**
 * Get image dimensions
 * 
 * @param string $path Path to image file
 * @return array Array with width and height
 */
function getImageDimensions($path)
{
    $dimensions = [
        'width' => 800,  // Default
        'height' => 600, // Default
    ];

    if (function_exists('getimagesize')) {
        $imageInfo = @getimagesize($path);
        if ($imageInfo && isset($imageInfo[0]) && isset($imageInfo[1])) {
            $dimensions['width'] = $imageInfo[0];
            $dimensions['height'] = $imageInfo[1];
        }
    }

    return $dimensions;
}

/**
 * Download file from URL
 * 
 * @param string $url URL to download
 * @param string $path Local path to save file
 * @return bool Success status
 */
function downloadFile($url, $path)
{
    logMessage("Downloading file from $url to $path");

    $ch = curl_init($url);
    $fp = fopen($path, 'wb');

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        logError("cURL error: " . curl_error($ch));
        $result = false;
    }

    curl_close($ch);
    fclose($fp);

    return $result && file_exists($path) && filesize($path) > 0;
}

/**
 * Generate migration report
 */
function generateReport()
{
    global $config, $report, $log;

    // Write CSV report
    $fp = fopen($config['report_file'], 'w');

    // Write header
    fputcsv($fp, [
        'MongoDB ID',
        'WordPress ID (EN)',
        'WordPress ID (AR)',
        'Status',
        'Message',
        'Title (EN)',
        'Title (AR)'
    ]);

    // Write data rows
    foreach ($report as $row) {
        fputcsv($fp, [
            $row['mongo_id'],
            $row['wp_id_en'],
            $row['wp_id_ar'],
            $row['status'],
            $row['message'],
            $row['title_en'],
            $row['title_ar']
        ]);
    }

    fclose($fp);

    // Write log file
    file_put_contents($config['log_file'], implode("\n", $log));

    logMessage("Report generated: {$config['report_file']}");
    logMessage("Log file: {$config['log_file']}");
}

/**
 * Log a message
 * 
 * @param string $message Message to log
 */
function logMessage($message)
{
    global $config, $log;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";

    $log[] = $logEntry;

    if ($config['verbose']) {
        echo $logEntry . "\n";
    }

    // Write log file
    file_put_contents($config['log_file'], implode("\n", $log));
}

/**
 * Log an error
 * 
 * @param string $message Error message to log
 */
function logError($message)
{
    global $config, $log;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] ERROR: $message";

    $log[] = $logEntry;

    // Always output errors
    echo $logEntry . "\n";

    // Write log file
    file_put_contents($config['log_file'], implode("\n", $log));
}

/**
 * Helper function to sanitize file name
 * 
 * @param string $filename File name to sanitize
 * @return string Sanitized file name
 */
function sanitize_file_name($filename)
{
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    return $filename;
}

/**
 * Helper function to check file type
 * 
 * @param string $filename File name
 * @return array File type info
 */
function wp_check_filetype($filename)
{
    $mimes = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'gif' => 'image/gif',
        'png' => 'image/png',
        'bmp' => 'image/bmp',
        'tif|tiff' => 'image/tiff',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
    ];

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $type = '';

    foreach ($mimes as $exts => $mime) {
        if (preg_match('!^(' . $exts . ')$!i', $ext)) {
            $type = $mime;
            break;
        }
    }

    return ['ext' => $ext, 'type' => $type];
}

/**
 * Helper function to find imported posts in WordPress
 * 
 * This function can be used to find all posts imported by this script
 * 
 * @param string $importTag Import tag value (defaults to current import tag)
 * @return array Array of post IDs
 */
function findImportedPosts($importTag = null)
{
    global $wpdb, $config;

    if ($importTag === null) {
        $importTag = $config['import_tag_meta_value'];
    }

    $query = "SELECT post_id FROM {$config['wp_prefix']}postmeta 
        WHERE meta_key = '{$config['import_tag_meta_key']}' 
        AND meta_value = '$importTag'";

    $results = $wpdb->get_results($query);

    $postIds = [];
    foreach ($results as $row) {
        $postIds[] = $row->post_id;
    }

    return $postIds;
}

/**
 * Helper function to delete all imported posts
 * 
 * This function can be used to delete all posts imported by this script
 * 
 * @param string $importTag Import tag value (defaults to current import tag)
 * @return int Number of posts deleted
 */
function deleteImportedPosts($importTag = null)
{
    global $wpdb, $config;

    if ($importTag === null) {
        $importTag = $config['import_tag_meta_value'];
    }

    $postIds = findImportedPosts($importTag);
    $count = 0;

    foreach ($postIds as $postId) {
        // Delete post and all its meta
        $wpdb->query("DELETE FROM {$config['wp_prefix']}posts WHERE ID = $postId");
        $wpdb->query("DELETE FROM {$config['wp_prefix']}postmeta WHERE post_id = $postId");

        // Delete from WPML tables
        $wpdb->query("DELETE FROM {$config['wp_prefix']}icl_translations WHERE element_id = $postId AND element_type = 'post_post'");

        // Delete term relationships
        $wpdb->query("DELETE FROM {$config['wp_prefix']}term_relationships WHERE object_id = $postId");

        $count++;
    }

    return $count;
}

// Run the script
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    // Only run main() if this script is being executed directly, not included
    main();
}
