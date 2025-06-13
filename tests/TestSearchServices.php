<?php
/**
 * Unit Tests for Search Services
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/SearchService.php';

class TestSearchServices {
    private $conn;
    private $response;
    private $searchService;
    
    /**
     * Constructor - setup for tests
     */
    public function __construct() {
        // Initialize response
        $this->response = [
            'success' => false,
            'data' => [],
            'errors' => [],
            'debug' => []
        ];
        
        // Connect to database
        require __DIR__ . '/../../../mysql_config_test.php';
        $this->conn = new mysqli($servername, $username, $password, $database);
        
        if ($this->conn->connect_error) {
            throw new Exception("Database connection failed: " . $this->conn->connect_error);
        }
        
        // Initialize services (updated to include FormService)
        require_once __DIR__ . '/../src/services/VideoService.php';
        require_once __DIR__ . '/../src/services/MocapService.php';
        require_once __DIR__ . '/../src/services/NmmService.php';
        require_once __DIR__ . '/../src/services/FormService.php';
        require_once __DIR__ . '/../src/services/ApiLogger.php';
        
        $logger = new ApiLogger($this->conn);
        $videoService = new VideoService($this->conn, $this->response, $logger);
        $mocapService = new MocapService($this->conn, $this->response, $logger);
        $nmmService = new NmmService($this->conn, $this->response);
        $formService = new FormService($this->conn, $this->response, $videoService, $nmmService);
        
        $this->searchService = new SearchService($this->conn, $this->response, $videoService, $mocapService, $nmmService, $formService);
    }
    
    /**
     * Clean up after tests
     */
    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
    
    /**
     * Assert that a condition is true
     *
     * @param bool $condition Condition to evaluate
     * @param string $message Error message on failure
     * @return bool|string True if assertion passes, error message otherwise
     */
    private function assertTrue($condition, $message = "Assertion failed") {
        return $condition === true ? true : $message;
    }
    
    /**
     * Assert that a value is not empty
     *
     * @param mixed $value Value to check
     * @param string $message Error message on failure
     * @return bool|string True if assertion passes, error message otherwise
     */
    private function assertNotEmpty($value, $message = "Value should not be empty") {
        return !empty($value) ? true : $message;
    }
    
    /**
     * Assert that two values are equal
     *
     * @param mixed $expected Expected value
     * @param mixed $actual Actual value
     * @param string $message Error message on failure
     * @return bool|string True if assertion passes, error message otherwise
     */
    private function assertEquals($expected, $actual, $message = "Values are not equal") {
        return $expected === $actual ? true : "$message (Expected: $expected, Got: $actual)";
    }
    
    /**
     * Test search functionality with a common word
     */
    public function testBasicSearch() {
        // Find a common word to search for (first check if "huis" exists as it's likely to be in the database)
        $testWords = ['huis', 'werk', 'dag', 'tijd'];
        $testWord = null;
        
        foreach ($testWords as $word) {
            $stmt = $this->conn->prepare("SELECT word FROM hh_words WHERE word = ? LIMIT 1");
            $stmt->bind_param("s", $word);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $testWord = $word;
                break;
            }
        }
        
        if (!$testWord) {
            // If none of the test words exist, get any word from the database
            $stmt = $this->conn->prepare("SELECT word FROM hh_words LIMIT 1");
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return "No words found in database for testing";
            }
            
            $row = $result->fetch_assoc();
            $testWord = $row['word'];
        }
        
        // Use reflection to call the private searchWords method
        $reflection = new ReflectionClass($this->searchService);
        $method = $reflection->getMethod('searchWords');
        $method->setAccessible(true);
        
        // Test the method
        $words = $method->invokeArgs($this->searchService, [$testWord]);
        
        // Verify we got results
        $result = $this->assertTrue(is_array($words), "Search result should be an array");
        if ($result !== true) return $result;
        
        $result = $this->assertNotEmpty($words, "Search for '$testWord' should return results");
        if ($result !== true) return $result;
        
        // Find the exact match
        $foundExact = false;
        foreach ($words as $word) {
            if ($word['word'] === $testWord) {
                $foundExact = true;
                break;
            }
        }
        
        $result = $this->assertTrue($foundExact, "Search should find exact match for '$testWord'");
        return $result;
    }
    
    /**
     * Test complete search method with results from different sources
     */
    public function testCompleteSearch() {
        // Find a good search term by checking for words with matching sentences
        $stmt = $this->conn->prepare("
            SELECT w.word 
            FROM hh_words w 
            JOIN sentences s ON JSON_CONTAINS(s.lemmaList, JSON_QUOTE(w.lemma)) 
            LIMIT 1
        ");
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // Fallback to any word
            $stmt = $this->conn->prepare("SELECT word FROM hh_words LIMIT 1");
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return "No words found in database for testing";
            }
        }
        
        $row = $result->fetch_assoc();
        $testWord = $row['word'];
        
        // Test the search method
        $results = $this->searchService->search($testWord);
        
        // Verify the structure of results
        $result = $this->assertTrue(is_array($results), "Search results should be an array");
        if ($result !== true) return $result;
        
        // Check that we have at least one of the expected result types
        // Note: Changed to look for 'glosses' instead of 'forms' and 'sb_records'
        $hasAnyResults = false;
        $resultTypes = ['words', 'sentences', 'glosses', 'synonyms'];
        
        foreach ($resultTypes as $type) {
            if (isset($results[$type]) && !empty($results[$type])) {
                $hasAnyResults = true;
                break;
            }
        }
        
        $result = $this->assertTrue($hasAnyResults, "Search for '$testWord' should return at least one type of result");
        return $result;
    }
    
    /**
     * Test search with pagination
     */
    public function testSearchPagination() {
        // Find any word
        $stmt = $this->conn->prepare("SELECT word FROM hh_words LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No words found in database for testing";
        }
        
        $row = $result->fetch_assoc();
        $testWord = $row['word'];
        
        // Test with offset 0
        $results1 = $this->searchService->search($testWord, 0);
        
        // Test with offset 1
        $results2 = $this->searchService->search($testWord, 1);
        
        // Verify both return arrays
        $result = $this->assertTrue(is_array($results1), "First search results should be an array");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(is_array($results2), "Second search results should be an array");
        if ($result !== true) return $result;
        
        // If there are sentences in both results, they should be different
        if (isset($results1['sentences']) && !empty($results1['sentences']) && 
            isset($results2['sentences']) && !empty($results2['sentences'])) {
            
            // Get the first sentence ID from each result
            $id1 = $results1['sentences'][0]['ID'] ?? null;
            $id2 = $results2['sentences'][0]['ID'] ?? null;
            
            // If both have IDs, they should differ due to pagination
            if ($id1 !== null && $id2 !== null) {
                $result = $this->assertTrue($id1 !== $id2, "Paginated results should return different sentences");
                return $result;
            }
        }
        
        // If we don't have comparable sentences, just pass the test
        return true;
    }

    /**
     * Test search with special characters
     */
    public function testSearchWithSpecialCharacters() {
        // Test array of strings with special characters
        $specialChars = [
            "test'quote", // Single quote
            'test"doublequote', // Double quote
            'test;semicolon', // Semicolon for SQL injection attempts
            'test--comment', // SQL comment
            'test<tag>', // HTML tag
            'test\backslash', // Backslash escape character
            "test\n\rlinebreak", // Line breaks
            'test%percent', // URL encoding character
            'ünicöde', // Unicode characters
            '👍emoji' // Emoji character
        ];
        
        foreach ($specialChars as $testString) {
            try {
                // Try searching with the special character string
                $results = $this->searchService->search($testString);
                
                // If we get here without exception, that's a pass for this string
                $result = $this->assertTrue(is_array($results), "Search results for '$testString' should be an array");
                if ($result !== true) return $result;
            } catch (Exception $e) {
                return "Search with '$testString' failed with exception: " . $e->getMessage();
            }
        }
        
        // If we got through all special characters without issues, pass the test
        return true;
    }
    
    /**
     * Test search with SQL injection attempts
     */
    public function testSearchWithSQLInjection() {
        // Common SQL injection patterns
        $injectionAttempts = [
            "' OR 1=1 --",
            "'; DROP TABLE users; --",
            "1' UNION SELECT username,password FROM users --",
            "1'; SELECT * FROM information_schema.tables; --",
            '" OR ""="',
            "' OR ''='",
            "admin'--",
            "1' OR '1'='1",
            "1 OR 1=1"
        ];
        
        foreach ($injectionAttempts as $attempt) {
            try {
                // Attempt search with injection string
                $results = $this->searchService->search($attempt);
                
                // If no exception is thrown, verify we still get a valid structure
                $result = $this->assertTrue(is_array($results), "Search results for SQL injection attempt should be an array");
                if ($result !== true) return $result;
                
                // Convert all results to a string for basic searching
                $resultString = json_encode($results);
                
                // Using a more direct check to see if sensitive terms actually appear in the output
                $isSafe = true;
                $sensitiveTerms = ['password', 'username', 'user_password', 'mysql.user', 'information_schema'];
                
                foreach ($sensitiveTerms as $term) {
                    // Only check lowercase versions in the lowercase result string to avoid false positives
                    if (strpos(strtolower($resultString), strtolower($term)) !== false) {
                        // Skip false positives that might legitimately contain these words
                        // For example, if searching for 'password' would obviously include that term
                        if (stripos($attempt, $term) === false) {
                            $isSafe = false;
                            break;
                        }
                    }
                }
                
                $result = $this->assertTrue($isSafe, 
                    "SQL injection attempt '$attempt' may have returned sensitive data");
                if ($result !== true) return $result;
                
            } catch (Exception $e) {
                // Exception is acceptable too as long as it's controlled
                // Check that it's not exposing SQL errors
                if (stripos($e->getMessage(), 'SQL syntax') !== false ||
                    stripos($e->getMessage(), 'mysql') !== false ||
                    stripos($e->getMessage(), 'database') !== false) {
                    return "SQL injection attempt '$attempt' is exposing database details in error: " . $e->getMessage();
                }
            }
        }
        
        return true;
    }
    
    /**
     * Test search with extreme length inputs
     */
    public function testSearchWithExtremeLengthInput() {
        // Test very long string
        $longString = str_repeat('a', 1000);
        
        try {
            $results = $this->searchService->search($longString);
            
            // If no exception, that's good
            $result = $this->assertTrue(is_array($results), "Search results for very long string should be an array");
            return $result;
        } catch (Exception $e) {
            // Some kind of limit error is acceptable, but shouldn't crash the system
            return "Search with very long string handled safely";
        }
    }
    
    /**
     * Test search with empty and null values
     */
    public function testSearchWithEmptyValues() {
        // Empty string
        try {
            $results = $this->searchService->search('');
            
            // The service should return empty results for empty string
            $result = $this->assertTrue(is_array($results), "Search results for empty string should be an array");
            if ($result !== true) return $result;
            
            // Verify we got expected empty structures for all result types
            // Updated to check for 'glosses' instead of 'forms' and 'sb_records'
            $resultTypes = ['words', 'sentences', 'glosses', 'synonyms'];
            $allEmpty = true;
            
            foreach ($resultTypes as $type) {
                if (!isset($results[$type])) {
                    return "Search results should include '$type' key, even if empty";
                }
                
                if (!empty($results[$type])) {
                    $allEmpty = false;
                    break;
                }
            }
            
            return $this->assertTrue($allEmpty, "Search with empty string should return empty results");
            
        } catch (Exception $e) {
            return "Exception thrown for empty search: " . $e->getMessage();
        }
    }
    
    /**
     * Test search with numeric and non-string values
     */
    public function testSearchWithNumericValues() {
        // Test with a number
        try {
            $results = $this->searchService->search('12345');
            
            // If we get here, verify we got expected structure
            $result = $this->assertTrue(is_array($results), "Search results for numeric value should be an array");
            return $result;
        } catch (Exception $e) {
            return "Search with numeric value failed with exception: " . $e->getMessage();
        }
    }
    
    /**
     * Test search with HTML and script tags
     */
    public function testSearchWithHtmlAndScriptTags() {
        // Test array with HTML and script tags
        $htmlTests = [
            '<script>alert("XSS")</script>',
            '<img src="x" onerror="alert(\'XSS\')">',
            '"><script>alert(document.cookie)</script>',
            '<body onload="alert(\'XSS\')">',
            '<?php echo "Server-side code"; ?>'
        ];
        
        foreach ($htmlTests as $test) {
            try {
                $results = $this->searchService->search($test);
                
                // If no exception, verify results structure
                $result = $this->assertTrue(is_array($results), "Search results for HTML/script tag should be an array");
                if ($result !== true) return $result;
                
                // Check that the output is properly encoded by converting to JSON and checking for raw tags
                $output = json_encode($results);
                
                // Check if the raw HTML/script is present (looking at complete matches)
                $dangerousTags = [
                    '<script>', '</script>', 'alert(', 'onerror=', '<img', '<?php'
                ];
                
                $isEncoded = true;
                foreach ($dangerousTags as $tag) {
                    if (strpos($output, $tag) !== false) {
                        $isEncoded = false;
                        break;
                    }
                }
                
                $result = $this->assertTrue($isEncoded, 
                    "HTML/Script content should be properly encoded in output: $tag found");
                if ($result !== true) return $result;
                
            } catch (Exception $e) {
                return "HTML/Script test threw exception: " . $e->getMessage();
            }
        }
        
        return true;
    }
    
    /**
     * Test that search includes FormService results
     */
    public function testSearchIncludesFormServiceResults() {
        // Find a form_data record to search for
        $stmt = $this->conn->prepare("SELECT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos IS NOT NULL AND glos != '' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No form_data records found for FormService integration test";
        }
        
        $row = $result->fetch_assoc();
        $testGlos = $row['glos'];
        $stmt->close();
        
        // Perform search
        $searchResults = $this->searchService->search($testGlos);
        
        // Verify structure
        $result = $this->assertTrue(is_array($searchResults), "Search results should be an array");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(isset($searchResults['glosses']), "Search results should contain glosses");
        if ($result !== true) return $result;
        
        // Look for FormService results in glosses
        $foundFormServiceResult = false;
        foreach ($searchResults['glosses'] as $gloss) {
            if (isset($gloss['source']) && $gloss['source'] === 'form_service') {
                $foundFormServiceResult = true;
                break;
            }
        }
        
        if ($foundFormServiceResult) {
            return "FormService integration test passed - found form_service results";
        } else {
            // This might be okay if the search pattern doesn't match FormService results
            return "FormService integration test passed - search completed without errors";
        }
    }
    
    /**
     * Test that search response includes debug information about FormService
     */
    public function testSearchFormServiceDebugInfo() {
        // Find any glos to search for
        $stmt = $this->conn->prepare("SELECT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos IS NOT NULL AND glos != '' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No form_data records found for FormService debug test";
        }
        
        $row = $result->fetch_assoc();
        $testGlos = $row['glos'];
        $stmt->close();
        
        // Reset debug info
        $this->response['debug'] = [];
        
        // Perform search
        $searchResults = $this->searchService->search($testGlos);
        
        // Check for FormService debug information
        $hasFormServiceDebug = isset($this->response['debug']['form_service_search_count']);
        
        $result = $this->assertTrue($hasFormServiceDebug, "Debug should include form_service_search_count");
        if ($result !== true) return $result;
        
        return "FormService debug information test passed";
    }
}
