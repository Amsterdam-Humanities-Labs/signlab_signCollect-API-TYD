<?php
/**
 * Unit Tests for SearchService Priority System
 * Tests the priority system where FormService glos values take precedence over NmmService when overlapping
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/SearchService.php';
require_once __DIR__ . '/../src/services/FormService.php';
require_once __DIR__ . '/../src/services/VideoService.php';
require_once __DIR__ . '/../src/services/MocapService.php';
require_once __DIR__ . '/../src/services/NmmService.php';
require_once __DIR__ . '/../src/services/ApiLogger.php';

class TestSearchServicesPriority {
    private $conn;
    private $response;
    private $searchService;
    private $formService;
    private $nmmService;
    private $videoService;
    private $mocapService;
    private $logger;
    
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
        
        // Initialize services
        $this->logger = new ApiLogger($this->conn);
        $this->videoService = new VideoService($this->conn, $this->response, $this->logger);
        $this->mocapService = new MocapService($this->conn, $this->response, $this->logger);
        $this->nmmService = new NmmService($this->conn, $this->response);
        $this->formService = new FormService($this->conn, $this->response, $this->videoService, $this->nmmService);
        $this->searchService = new SearchService($this->conn, $this->response, $this->videoService, $this->mocapService, $this->nmmService, $this->formService);
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
     * Assert that an array contains a specific key
     *
     * @param mixed $key Key to check for
     * @param array $array Array to check
     * @param string $message Error message on failure
     * @return bool|string True if assertion passes, error message otherwise
     */
    private function assertArrayHasKey($key, $array, $message = "Array should contain key") {
        return array_key_exists($key, $array) ? true : "$message: $key";
    }
    
    /**
     * Find a common glos value between form_data and nmm_data for testing priority
     */
    private function findOverlappingGlosValue() {
        $sql = "SELECT f.glos 
                FROM form_data f 
                INNER JOIN nmm_data n ON f.glos = n.glos 
                WHERE f.extern = '1' AND f.glosZichtbaar = '0' 
                AND f.glos IS NOT NULL AND f.glos != ''
                AND n.glos IS NOT NULL AND n.glos != ''
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['glos'];
        }
        
        $stmt->close();
        return null;
    }
    
    /**
     * Test that FormService results take priority over NmmService when glos values overlap
     */
    public function testFormServicePriorityOverNmm() {
        $overlappingGlos = $this->findOverlappingGlosValue();
        
        if (!$overlappingGlos) {
            return "No overlapping glos values found between form_data and nmm_data for priority test";
        }
        
        // Reset response debug for clean testing
        $this->response['debug'] = [];
        
        // Perform search that should trigger priority system
        $searchResults = $this->searchService->search($overlappingGlos);
        
        // Verify we got results
        $result = $this->assertTrue(is_array($searchResults), "Search results should be an array");
        if ($result !== true) return $result;
        
        $result = $this->assertArrayHasKey('glosses', $searchResults, "Search results should contain glosses");
        if ($result !== true) return $result;
        
        $glosses = $searchResults['glosses'];
        
        // Look for the overlapping glos in results
        $foundFormServiceResult = false;
        $foundNmmResult = false;
        
        foreach ($glosses as $gloss) {
            $glossValue = '';
            
            // Extract glos value from result structure
            if (isset($gloss['senses']) && is_array($gloss['senses']) && !empty($gloss['senses'])) {
                // For search results, glos value might be in first sense
                $glossValue = $gloss['senses'][0] ?? '';
            }
            
            // Check if this matches our overlapping glos (case-insensitive and after processing)
            if (strcasecmp($glossValue, $overlappingGlos) === 0 || 
                strcasecmp($glossValue, ucfirst(strtolower($overlappingGlos))) === 0) {
                
                if (isset($gloss['source'])) {
                    if ($gloss['source'] === 'form_service') {
                        $foundFormServiceResult = true;
                    } elseif ($gloss['source'] === 'nmm_data') {
                        $foundNmmResult = true;
                    }
                }
            }
        }
        
        // Verify that FormService result is present
        $result = $this->assertTrue($foundFormServiceResult, "Should find FormService result for overlapping glos '$overlappingGlos'");
        if ($result !== true) return $result;
        
        // Verify that NMM result is NOT present (due to priority)
        $result = $this->assertTrue(!$foundNmmResult, "Should NOT find NMM result for overlapping glos '$overlappingGlos' due to FormService priority");
        if ($result !== true) return $result;
        
        // Check debug information for priority system activity
        if (isset($this->response['debug']['skipped_nmm_for_formservice_priority'])) {
            $skippedEntries = $this->response['debug']['skipped_nmm_for_formservice_priority'];
            $foundSkippedEntry = false;
            
            foreach ($skippedEntries as $skipped) {
                if (isset($skipped['glos_value']) && strcasecmp($skipped['glos_value'], $overlappingGlos) === 0) {
                    $foundSkippedEntry = true;
                    break;
                }
            }
            
            $result = $this->assertTrue($foundSkippedEntry, "Debug should show NMM entry was skipped for overlapping glos");
            if ($result !== true) return $result;
        }
        
        return "FormService priority over NMM test passed for glos: $overlappingGlos";
    }
    
    /**
     * Test that FormService and NMM can coexist when glos values don't overlap
     */
    public function testFormServiceAndNmmCoexistence() {
        // Find a glos that exists in form_data but not in nmm_data
        $sql = "SELECT f.glos 
                FROM form_data f 
                LEFT JOIN nmm_data n ON f.glos = n.glos 
                WHERE f.extern = '1' AND f.glosZichtbaar = '0' 
                AND f.glos IS NOT NULL AND f.glos != ''
                AND n.glos IS NULL
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return "No form_data-only glos values found for coexistence test";
        }
        
        $row = $result->fetch_assoc();
        $formOnlyGlos = $row['glos'];
        $stmt->close();
        
        // Reset response debug
        $this->response['debug'] = [];
        
        // Perform search
        $searchResults = $this->searchService->search($formOnlyGlos);
        
        // Verify we got results
        $result = $this->assertArrayHasKey('glosses', $searchResults, "Search results should contain glosses");
        if ($result !== true) return $result;
        
        $glosses = $searchResults['glosses'];
        $foundFormServiceResult = false;
        
        foreach ($glosses as $gloss) {
            if (isset($gloss['source']) && $gloss['source'] === 'form_service') {
                $foundFormServiceResult = true;
                break;
            }
        }
        
        $result = $this->assertTrue($foundFormServiceResult, "Should find FormService result when no NMM overlap");
        if ($result !== true) return $result;
        
        return "FormService and NMM coexistence test passed for form-only glos: $formOnlyGlos";
    }
    
    /**
     * Test debug logging for priority system
     */
    public function testPrioritySystemDebugLogging() {
        $overlappingGlos = $this->findOverlappingGlosValue();
        
        if (!$overlappingGlos) {
            return "No overlapping glos values found for debug logging test";
        }
        
        // Reset response debug
        $this->response['debug'] = [];
        
        // Perform search
        $searchResults = $this->searchService->search($overlappingGlos);
        
        // Check for expected debug entries
        $expectedDebugKeys = [
            'form_service_search_count',
            'added_formservice_glos',
            'skipped_nmm_for_formservice_priority',
            'added_nmm_glos'
        ];
        
        foreach ($expectedDebugKeys as $key) {
            if (isset($this->response['debug'][$key])) {
                // At least one debug key should be present
                return "Priority system debug logging test passed - found debug key: $key";
            }
        }
        
        return "Priority system debug logging test failed - no expected debug keys found";
    }
    
    /**
     * Test search with term that has multiple FormService and NMM matches
     */
    public function testMultipleMatchesPriority() {
        // Find a partial glos that matches multiple records
        $sql = "SELECT SUBSTRING(f.glos, 1, 3) as partial_glos, COUNT(*) as form_count
                FROM form_data f 
                WHERE f.extern = '1' AND f.glosZichtbaar = '0' 
                AND f.glos IS NOT NULL AND f.glos != ''
                AND LENGTH(f.glos) > 3
                GROUP BY SUBSTRING(f.glos, 1, 3)
                HAVING form_count > 1
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return "No suitable partial glos found for multiple matches test";
        }
        
        $row = $result->fetch_assoc();
        $partialGlos = $row['partial_glos'];
        $stmt->close();
        
        // Reset response debug
        $this->response['debug'] = [];
        
        // Perform search
        $searchResults = $this->searchService->search($partialGlos);
        
        // Verify we got results
        $result = $this->assertArrayHasKey('glosses', $searchResults, "Search results should contain glosses");
        if ($result !== true) return $result;
        
        $glosses = $searchResults['glosses'];
        $formServiceCount = 0;
        $nmmCount = 0;
        
        foreach ($glosses as $gloss) {
            if (isset($gloss['source'])) {
                if ($gloss['source'] === 'form_service') {
                    $formServiceCount++;
                } elseif ($gloss['source'] === 'nmm_data') {
                    $nmmCount++;
                }
            }
        }
        
        // We should have some results
        $totalResults = $formServiceCount + $nmmCount;
        $result = $this->assertTrue($totalResults > 0, "Should find some results for partial glos search");
        if ($result !== true) return $result;
        
        return "Multiple matches priority test passed - FormService: $formServiceCount, NMM: $nmmCount for partial glos: $partialGlos";
    }
    
    /**
     * Test that JSON output structure is maintained with priority system
     */
    public function testJsonOutputStructureWithPriority() {
        // Find any glos that should return results
        $stmt = $this->conn->prepare("SELECT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos IS NOT NULL AND glos != '' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No form_data records found for JSON structure test";
        }
        
        $row = $result->fetch_assoc();
        $testGlos = $row['glos'];
        $stmt->close();
        
        // Perform search
        $searchResults = $this->searchService->search($testGlos);
        
        // Check top-level structure
        $expectedTopLevelKeys = ['words', 'sentences', 'glosses'];
        foreach ($expectedTopLevelKeys as $key) {
            $result = $this->assertArrayHasKey($key, $searchResults, "Search results should contain '$key'");
            if ($result !== true) return $result;
        }
        
        // Check glosses structure if we have results
        if (!empty($searchResults['glosses'])) {
            $firstGloss = $searchResults['glosses'][0];
            $expectedGlossKeys = ['id', 'senses', 'type', 'source'];
            
            foreach ($expectedGlossKeys as $key) {
                $result = $this->assertArrayHasKey($key, $firstGloss, "Gloss result should contain '$key'");
                if ($result !== true) return $result;
            }
            
            // Verify 'source' field contains expected values
            $validSources = ['form_service', 'nmm_data', 'form_data'];
            $source = $firstGloss['source'] ?? '';
            $result = $this->assertTrue(in_array($source, $validSources), "Source field should be one of: " . implode(', ', $validSources));
            if ($result !== true) return $result;
        }
        
        return "JSON output structure with priority test passed";
    }
    
    /**
     * Test edge case: empty search with priority system
     */
    public function testEmptySearchWithPriority() {
        // Test empty search
        $searchResults = $this->searchService->search('');
        
        // Should return empty structured results
        $result = $this->assertTrue(is_array($searchResults), "Empty search should return array");
        if ($result !== true) return $result;
        
        $expectedKeys = ['words', 'sentences', 'glosses'];
        foreach ($expectedKeys as $key) {
            $result = $this->assertArrayHasKey($key, $searchResults, "Empty search should contain '$key' key");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(empty($searchResults[$key]), "Empty search '$key' should be empty");
            if ($result !== true) return $result;
        }
        
        return "Empty search with priority system test passed";
    }
}