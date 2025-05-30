<?php


// Check if MongoDB extension is loaded
if (!extension_loaded('mongodb')) {
    die("MongoDB extension is not loaded. Please install the MongoDB PHP extension.");
}

// Require Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Verify MongoDB\Client class exists
if (!class_exists('MongoDB\Client')) {
    die("MongoDB\Client class not found. Please run 'composer install' to install dependencies.");
}


/**
 * MongoDB to WordPress Migration Script
 * 
 * This script migrates articles from a MongoDB database to a WordPress installation with WPML support.
 * It handles multilingual content, image downloads, and category mappings.
 * 
 */

// Ensure script execution time is sufficient for migration
ini_set('max_execution_time', 3600); // 1 hour
ini_set('memory_limit', '512M');     // 512MB memory limit

// Configuration - Edit these values
$config = [
    // MongoDB connection
    'mongo_uri' => 'mongodb://ed_sfm:b84m9FjK1n3phU9HdsoJA86QrLXqwePJOqH1YHAiJU5Ee5EgnFjTts6faXUrrBIl@193.203.191.187:27017',
    'mongo_db' => 'future-project',
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

    // Languages
    'languages' => ['en', 'ar'],
    'default_language' => 'en',

    // Reporting
    'report_file' => 'migration_report.csv',
    'log_file' => 'migration_log.txt',
    'verbose' => true,
];

// Initialize global variables
$report = [];
$log = [];
$officeMapping = [];
$departmentMapping = [];
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
        // Initialize connections and mappings
        initializeConnections();
        loadCategoryMappings();

        // Create image download directory if it doesn't exist
        if (!file_exists($config['image_download_path'])) {
            mkdir($config['image_download_path'], 0755, true);
        }

        // Start migration process
        logMessage("Starting migration process...");
        migrateArticles();

        // Generate final report
        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 2);

        logMessage("Migration completed in {$executionTime} seconds.");
        generateReport();

        echo "Migration completed successfully. See {$config['report_file']} for details.\n";
    } catch (Exception $e) {
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
        $mongoClient = new MongoDB\Client($config['mongo_uri']);
        logMessage("MongoDB connection established.");

        // Connect to WordPress database
        logMessage("Connecting to WordPress database...");
        $wpdb = new mysqli(
            $config['wp_host'],
            $config['wp_user'],
            $config['wp_pass'],
            $config['wp_db']
        );

        if ($wpdb->connect_error) {
            throw new Exception("WordPress database connection failed: " . $wpdb->connect_error);
        }

        logMessage("WordPress database connection established.");
    } catch (Exception $e) {
        throw new Exception("Connection initialization failed: " . $e->getMessage());
    }
}

/**
 * Load category mappings from CSV files
 */
function loadCategoryMappings()
{
    global $officeMapping, $departmentMapping;

    logMessage("Loading category mappings...");

    // Load office mappings
    $officeMapping = loadMappingFromCSV('future-project.offices.csv', 'wp_terms.csv');
    logMessage("Loaded " . count($officeMapping) . " office mappings.");

    // Load department mappings
    $departmentMapping = loadMappingFromCSV('future-project.departments.csv', 'wp_terms.csv');
    logMessage("Loaded " . count($departmentMapping) . " department mappings.");
}

/**
 * Load mapping from CSV files
 * 
 * 
 * @param string $sourceFile MongoDB source CSV file
 * @param string $wpTermsFile WordPress terms CSV file
 * @return array Mapping between MongoDB IDs and WordPress term IDs
 */
function loadMappingFromCSV($sourceFile, $wpTermsFile)
{
    $mapping = [];
    $wpTerms = [];

    // Load WordPress terms
    if (($handle = fopen($wpTermsFile, "r")) !== false) {
        // Skip header row
        fgetcsv($handle);

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) >= 2) {
                $termId = $data[0];
                $termName = $data[1];
                $wpTerms[strtolower($termName)] = $termId;
            }
        }
        fclose($handle);
    } else {
        throw new Exception("Could not open WordPress terms file: $wpTermsFile");
    }

    // Load source mappings
    if (($handle = fopen($sourceFile, "r")) !== false) {
        // Skip header row
        fgetcsv($handle);

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) >= 3) {
                $mongoId = $data[0];
                $titleAr = $data[1];
                $titleEn = $data[2];

                // Find matching WordPress term IDs
                $termIdAr = $wpTerms[strtolower($titleAr)] ?? null;
                $termIdEn = $wpTerms[strtolower($titleEn)] ?? null;

                if ($termIdAr || $termIdEn) {
                    $mapping[$mongoId] = [
                        'ar' => $termIdAr,
                        'en' => $termIdEn,
                        'title_ar' => $titleAr,
                        'title_en' => $titleEn
                    ];
                }
            }
        }
        fclose($handle);
    } else {
        throw new Exception("Could not open source mapping file: $sourceFile");
    }

    return $mapping;
}

/**
 * Migrate articles from MongoDB to WordPress
 */
function migrateArticles()
{
    global $config, $mongoClient, $report;

    $collection = $mongoClient->{$config['mongo_db']}->{$config['mongo_collection']};
    $cursor = $collection->find(['state' => 'published']);

    $totalArticles = $collection->countDocuments(['state' => 'published']);
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
        } catch (Exception $e) {
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
 * @throws Exception If article is missing required fields
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
            throw new Exception("Article is missing required field: $field");
        }
    }

    // Check language fields
    foreach (['title', 'slug', 'description'] as $field) {
        if (!isset($article[$field]['en']) || !isset($article[$field]['ar'])) {
            throw new Exception("Article is missing language version for field: $field");
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
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
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

        // Prepare post data
        $postDate = date('Y-m-d H:i:s', strtotime($article['dateOfPublished']['$date']));

        $postData = [
            'post_title' => $article['title'][$language],
            'post_content' => $article['description'][$language],
            'post_name' => $article['slug'][$language],
            'post_date' => $postDate,
            'post_date_gmt' => $postDate,
            'post_modified' => date('Y-m-d H:i:s', strtotime($article['updatedAt']['$date'])),
            'post_modified_gmt' => date('Y-m-d H:i:s', strtotime($article['updatedAt']['$date'])),
            'post_status' => 'publish',
            'post_author' => $config['wp_author_id'],
            'post_type' => 'post',
            'comment_status' => 'open',
            'ping_status' => 'open',
        ];

        // Insert post
        $wpdb->query("INSERT INTO {$config['wp_prefix']}posts 
            (post_title, post_content, post_name, post_date, post_date_gmt, 
             post_modified, post_modified_gmt, post_status, post_author, 
             post_type, comment_status, ping_status)
            VALUES 
            ('{$wpdb->escape($postData['post_title'])}', 
             '{$wpdb->escape($postData['post_content'])}', 
             '{$wpdb->escape($postData['post_name'])}', 
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
            throw new Exception("Failed to insert post: " . $wpdb->last_error);
        }

        // Set WPML language
        setPostLanguage($postId, $language);

        // Process featured image
        if (isset($article['image'][$language])) {
            $imageUrl = $article['image'][$language];
            $imageId = processAndAttachImage($imageUrl, $postId, $language);

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

        // Add offices
        if (isset($article['offices']) && is_array($article['offices'])) {
            foreach ($article['offices'] as $office) {
                $officeId = (string)$office['$oid'];
                if (isset($officeMapping[$officeId][$language])) {
                    $termIds[] = $officeMapping[$officeId][$language];
                }
            }
        }

        // Add departments
        if (isset($article['departments']) && is_array($article['departments'])) {
            foreach ($article['departments'] as $department) {
                $departmentId = (string)$department['$oid'];
                if (isset($departmentMapping[$departmentId][$language])) {
                    $termIds[] = $departmentMapping[$departmentId][$language];
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

                    // Update count
                    $wpdb->query("UPDATE {$config['wp_prefix']}term_taxonomy 
                        SET count = count + 1 
                        WHERE term_taxonomy_id = $termTaxonomyId");
                }
            }
        }

        // Commit transaction
        $wpdb->query('COMMIT');

        return $postId;
    } catch (Exception $e) {
        // Rollback on error
        $wpdb->query('ROLLBACK');
        logError("Error creating WordPress post: " . $e->getMessage());
        return false;
    }
}

/**
 * Create WordPress translation for a post
 * 
 * @param array $article MongoDB article document
 * @param string $language Language code to create
 * @param int $originalPostId Original post ID
 * @param string $originalLang Original language code
 * @return int|false WordPress post ID of translation or false on failure
 */
function createWordPressTranslation($article, $language, $originalPostId, $originalLang)
{
    // Create the post in the target language
    $translationId = createWordPressPost($article, $language);

    if (!$translationId) {
        return false;
    }

    // Link the posts as translations in WPML
    linkWpmlTranslations($originalPostId, $translationId, $originalLang, $language);

    return $translationId;
}

/**
 * Set post language in WPML
 * 
 * @param int $postId WordPress post ID
 * @param string $language Language code
 */
function setPostLanguage($postId, $language)
{
    global $wpdb, $config;

    // Check if language entry exists
    $exists = $wpdb->get_var("SELECT translation_id FROM {$config['wp_prefix']}icl_translations 
        WHERE element_id = $postId AND element_type = 'post_post'");

    if ($exists) {
        // Update existing entry
        $wpdb->query("UPDATE {$config['wp_prefix']}icl_translations 
            SET language_code = '$language' 
            WHERE element_id = $postId AND element_type = 'post_post'");
    } else {
        // Create new entry
        $wpdb->query("INSERT INTO {$config['wp_prefix']}icl_translations 
            (element_type, element_id, trid, language_code, source_language_code) 
            VALUES 
            ('post_post', $postId, $postId, '$language', NULL)");
    }
}

/**
 * Link posts as translations in WPML
 * 
 * @param int $postId1 First post ID
 * @param int $postId2 Second post ID
 * @param string $lang1 First post language
 * @param string $lang2 Second post language
 */
function linkWpmlTranslations($postId1, $postId2, $lang1, $lang2)
{
    global $wpdb, $config;

    // Generate a translation relationship ID
    $trid = $wpdb->get_var("SELECT MAX(trid) + 1 FROM {$config['wp_prefix']}icl_translations");

    if (!$trid) {
        $trid = 1;
    }

    // Update first post
    $wpdb->query("UPDATE {$config['wp_prefix']}icl_translations 
        SET trid = $trid 
        WHERE element_id = $postId1 AND element_type = 'post_post'");

    // Update second post
    $wpdb->query("UPDATE {$config['wp_prefix']}icl_translations 
        SET trid = $trid, source_language_code = '$lang1' 
        WHERE element_id = $postId2 AND element_type = 'post_post'");
}

/**
 * Process and attach image to post
 * 
 * @param string $imageUrl Original image URL
 * @param int $postId WordPress post ID
 * @param string $language Language code
 * @return int|false WordPress attachment ID or false on failure
 */
function processAndAttachImage($imageUrl, $postId, $language)
{
    global $wpdb, $config;

    try {
        // Transform URL
        $imageUrl = str_replace(
            $config['old_image_domain'],
            $config['new_image_domain'],
            $imageUrl
        );

        // Extract filename from URL
        $filename = basename(parse_url($imageUrl, PHP_URL_PATH));
        $localPath = $config['image_download_path'] . $filename;

        // Download image
        if (!downloadFile($imageUrl, $localPath)) {
            throw new Exception("Failed to download image: $imageUrl");
        }

        // Get file info
        $fileType = wp_check_filetype($filename);
        if (!$fileType['type']) {
            throw new Exception("Unknown file type for image: $filename");
        }

        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $fileType['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $imageUrl
        ];

        // Insert attachment
        $wpdb->query("INSERT INTO {$config['wp_prefix']}posts 
            (post_mime_type, post_title, post_content, post_status, guid, post_parent) 
            VALUES 
            ('{$attachment['post_mime_type']}', 
             '{$wpdb->escape($attachment['post_title'])}', 
             '{$attachment['post_content']}', 
             '{$attachment['post_status']}', 
             '{$wpdb->escape($attachment['guid'])}', 
             $postId)");

        $attachmentId = $wpdb->insert_id;

        if (!$attachmentId) {
            throw new Exception("Failed to insert attachment: " . $wpdb->last_error);
        }

        // Set attachment metadata
        $attachMeta = [
            '_wp_attached_file' => $filename,
            '_wp_attachment_metadata' => serialize([
                'width' => 800,  // Default values, would be better to get actual dimensions
                'height' => 600,
                'file' => $filename,
                'sizes' => [
                    'thumbnail' => [
                        'file' => $filename,
                        'width' => 150,
                        'height' => 150,
                    ],
                    'medium' => [
                        'file' => $filename,
                        'width' => 300,
                        'height' => 225,
                    ],
                ],
            ])
        ];

        foreach ($attachMeta as $key => $value) {
            $wpdb->query("INSERT INTO {$config['wp_prefix']}postmeta 
                (post_id, meta_key, meta_value) 
                VALUES 
                ($attachmentId, '$key', '{$wpdb->escape($value)}')");
        }

        // Set WPML language for attachment
        setPostLanguage($attachmentId, $language);

        return $attachmentId;
    } catch (Exception $e) {
        logError("Error processing image: " . $e->getMessage());
        return false;
    }
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
    global $config, $report;

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
}

/**
 * Log an error
 * 
 * @param string $message Error message to log
 */
function logError($message)
{
    global $log;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] ERROR: $message";

    $log[] = $logEntry;

    // Always output errors
    echo $logEntry . "\n";
}

// Run the script
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    // Only run main() if this script is being executed directly, not included
    main();
}
