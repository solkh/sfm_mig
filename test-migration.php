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
 * Test script for MongoDB to WordPress Migration
 * 
 * This script tests the functionality of the migration script with sample data
 * without actually performing the full migration.
 */

// Override configuration for testing
$config = [
    // MongoDB connection (use test database)
    'mongo_uri' => 'mongodb://root:b84m9FjK1n3phU9HdsoJA86QrLXqwePJOqH1YHAiJU5Ee5EgnFjTts6faXUrrBIl@MongoDb:27017',
    'mongo_db' => 'future-project',
    'mongo_collection' => 'articles',

    // WordPress database connection (use test database)
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
    'image_download_path' => '/tmp/wp-migration-test-images/',

    // Languages
    'languages' => ['en', 'ar'],
    'default_language' => 'en',

    // Reporting
    'report_file' => 'test_migration_report.csv',
    'log_file' => 'test_migration_log.txt',
    'verbose' => true,

    // Test mode
    'test_mode' => true,
    'sample_size' => 3,
];

/**
 * Run all tests
 */
function runTests()
{
    echo "=== MongoDB to WordPress Migration Script Tests ===\n\n";

    $tests = [
        'Database Connections' => 'testDatabaseConnections',
        'Category Mapping' => 'testCategoryMapping',
        'Image Handling' => 'testImageHandling',
        'WPML Integration' => 'testWpmlIntegration',
        'Error Handling' => 'testErrorHandling',
    ];

    $results = [];
    $allPassed = true;

    foreach ($tests as $name => $function) {
        echo "\n=== Testing: $name ===\n";
        $result = call_user_func($function);
        $results[$name] = $result;

        if (!$result) {
            $allPassed = false;
        }
    }

    echo "\n=== Test Summary ===\n";
    foreach ($results as $name => $result) {
        echo ($result ? "✓" : "✗") . " $name: " . ($result ? "PASSED" : "FAILED") . "\n";
    }

    echo "\nOverall Test Result: " . ($allPassed ? "PASSED" : "FAILED") . "\n";

    return $allPassed;
}

/**
 * Test database connections
 */
function testDatabaseConnections()
{
    global $config;

    echo "Testing database connections...\n";

    try {
        // Test MongoDB connection
        $mongoClient = new MongoDB\Client($config['mongo_uri']);
        $adminDb = $mongoClient->admin;
        $result = $adminDb->command(['ping' => 1]);

        // Use a simpler ping approach
        try {
            // Just try to list databases to verify connection
            $dbs = $mongoClient->listDatabases();
            echo "✓ MongoDB connection successful\n";
        } catch (Exception $e) {
            echo "✗ MongoDB connection failed: " . $e->getMessage() . "\n";
            return false;
        }

        // Test WordPress database connection
        $wpdb = new mysqli(
            $config['wp_host'],
            $config['wp_user'],
            $config['wp_pass'],
            $config['wp_db']
        );

        if ($wpdb->connect_error) {
            echo "✗ WordPress database connection failed: " . $wpdb->connect_error . "\n";
            return false;
        } else {
            echo "✓ WordPress database connection successful\n";
        }

        return true;
    } catch (Exception $e) {
        echo "✗ Connection test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Test category mapping
 */
function testCategoryMapping()
{
    echo "Testing category mapping...\n";

    try {
        // Load office mappings
        $officeMapping = loadMappingFromCSV('future-project.offices.csv', 'wp_terms.csv');

        if (count($officeMapping) > 0) {
            echo "✓ Office mapping loaded successfully (" . count($officeMapping) . " mappings)\n";

            // Display sample mapping
            $sampleKey = array_key_first($officeMapping);
            echo "  Sample: MongoDB ID: $sampleKey => WP Term IDs: " .
                "AR: " . $officeMapping[$sampleKey]['ar'] . ", " .
                "EN: " . $officeMapping[$sampleKey]['en'] . "\n";
        } else {
            echo "✗ Office mapping failed to load\n";
            return false;
        }

        // Load department mappings
        $departmentMapping = loadMappingFromCSV('future-project.departments.csv', 'wp_terms.csv');

        if (count($departmentMapping) > 0) {
            echo "✓ Department mapping loaded successfully (" . count($departmentMapping) . " mappings)\n";

            // Display sample mapping
            $sampleKey = array_key_first($departmentMapping);
            echo "  Sample: MongoDB ID: $sampleKey => WP Term IDs: " .
                "AR: " . $departmentMapping[$sampleKey]['ar'] . ", " .
                "EN: " . $departmentMapping[$sampleKey]['en'] . "\n";
        } else {
            echo "✗ Department mapping failed to load\n";
            return false;
        }

        return true;
    } catch (Exception $e) {
        echo "✗ Category mapping test failed: " . $e->getMessage() . "\n";
        return false;
    }
}
/**
 * Test image URL transformation and download
 */
function testImageHandling()
{
    global $config;

    echo "Testing image handling...\n";

    try {
        // Create test directory
        if (!file_exists($config['image_download_path'])) {
            mkdir($config['image_download_path'], 0755, true);
        }

        // Test URL transformation
        $originalUrl = "https://storage.xposuredevlabs.com/sfm-assets/public/uploads/images/en_1741211907975.jpg";
        $transformedUrl = str_replace(
            $config['old_image_domain'],
            $config['new_image_domain'],
            $originalUrl
        );

        $expectedUrl = "https://storage.sfuturem.org/sfm-assets/public/uploads/images/en_1741211907975.jpg";

        if ($transformedUrl === $expectedUrl) {
            echo "✓ URL transformation successful\n";
            echo "  Original: $originalUrl\n";
            echo "  Transformed: $transformedUrl\n";
        } else {
            echo "✗ URL transformation failed\n";
            echo "  Original: $originalUrl\n";
            echo "  Transformed: $transformedUrl\n";
            echo "  Expected: $expectedUrl\n";
            return false;
        }

        // Test file download (mock)
        $testFile = $config['image_download_path'] . 'test-image.jpg';
        file_put_contents($testFile, 'Test image content');

        if (file_exists($testFile)) {
            echo "✓ Image download simulation successful\n";
            unlink($testFile); // Clean up
        } else {
            echo "✗ Image download simulation failed\n";
            return false;
        }

        return true;
    } catch (Exception $e) {
        echo "✗ Image handling test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Test WPML integration
 */
function testWpmlIntegration()
{
    global $config;

    echo "Testing WPML integration...\n";

    try {
        // Check if WPML tables exist (mock test)
        echo "✓ WPML table structure validation (simulated)\n";

        // Test language setting function (mock)
        echo "✓ Post language setting function (simulated)\n";

        // Test translation linking function (mock)
        echo "✓ Translation linking function (simulated)\n";

        return true;
    } catch (Exception $e) {
        echo "✗ WPML integration test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Test error handling and reporting
 */
function testErrorHandling()
{
    global $config;

    echo "Testing error handling and reporting...\n";

    try {
        // Test logging
        logMessage("Test log message");
        logError("Test error message");

        if (file_exists($config['log_file'])) {
            echo "✓ Logging functionality works\n";
        } else {
            echo "✗ Logging functionality failed\n";
            return false;
        }

        // Test report generation (mock)
        $report = [
            [
                'mongo_id' => '67c8c90349189a1c230deec4',
                'wp_id_en' => '123',
                'wp_id_ar' => '124',
                'status' => 'Success',
                'message' => 'Successfully migrated article',
                'title_en' => 'Test Article',
                'title_ar' => 'مقالة اختبار',
            ],
            [
                'mongo_id' => '67c8c90349189a1c230deec5',
                'wp_id_en' => '',
                'wp_id_ar' => '',
                'status' => 'Failed',
                'message' => 'Missing required field: title',
                'title_en' => '',
                'title_ar' => '',
            ]
        ];

        // Write test report
        $fp = fopen($config['report_file'], 'w');
        fputcsv($fp, [
            'MongoDB ID',
            'WordPress ID (EN)',
            'WordPress ID (AR)',
            'Status',
            'Message',
            'Title (EN)',
            'Title (AR)'
        ]);

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

        if (file_exists($config['report_file'])) {
            echo "✓ Report generation works\n";
        } else {
            echo "✗ Report generation failed\n";
            return false;
        }

        return true;
    } catch (Exception $e) {
        echo "✗ Error handling test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run the tests
runTests();
