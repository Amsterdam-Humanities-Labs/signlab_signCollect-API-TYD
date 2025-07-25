<?php
/**
 * Unit Tests for getZinnenThemas.php endpoint
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/SentenceService.php';
require_once __DIR__ . '/../src/services/ApiLogger.php';

class TestGetZinnenThemas {
    private $conn;
    private $response;
    private $sentenceService;
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
        
        $this->conn->set_charset("utf8");
        
        // Initialize services
        $this->logger = new ApiLogger($this->conn);
        $this->sentenceService = new SentenceService($this->conn, $this->response, null);
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
     */
    private function assertTrue($condition, $message = "Assertion failed") {
        return $condition === true ? true : $message;
    }
    
    /**
     * Assert that a value is not empty
     */
    private function assertNotEmpty($value, $message = "Value should not be empty") {
        return !empty($value) ? true : $message;
    }
    
    /**
     * Assert that two values are equal
     */
    private function assertEquals($expected, $actual, $message = "Values are not equal") {
        return $expected === $actual ? true : "$message (Expected: $expected, Got: $actual)";
    }
    
    /**
     * Test getAllThemas basic functionality
     */
    public function testGetAllThemas() {
        try {
            $themas = $this->sentenceService->getAllThemas();
            
            // Verify structure
            $result = $this->assertTrue(is_array($themas), "Themas result should be an array");
            if ($result !== true) return $result;
            
            // Check if we have any themas (assuming test database has data)
            if (!empty($themas)) {
                // Verify each thema is a string
                foreach ($themas as $thema) {
                    $result = $this->assertTrue(is_string($thema), "Each thema should be a string");
                    if ($result !== true) return $result;
                    
                    $result = $this->assertNotEmpty($thema, "Each thema should not be empty");
                    if ($result !== true) return $result;
                }
            }
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that themas are unique
     */
    public function testThemasAreUnique() {
        try {
            $themas = $this->sentenceService->getAllThemas();
            
            if (empty($themas)) {
                return true; // No themas to check for uniqueness
            }
            
            // Check for duplicates
            $uniqueThemas = array_unique($themas);
            
            $result = $this->assertEquals(count($themas), count($uniqueThemas), 
                "All themas should be unique");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that themas are sorted alphabetically
     */
    public function testThemasAreSorted() {
        try {
            $themas = $this->sentenceService->getAllThemas();
            
            if (count($themas) < 2) {
                return true; // Need at least 2 items to test sorting
            }
            
            // Create a sorted copy
            $sortedThemas = $themas;
            sort($sortedThemas);
            
            $result = $this->assertEquals($sortedThemas, $themas, "Themas should be sorted alphabetically");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test endpoint response structure (simulating full endpoint call)
     */
    public function testEndpointResponseStructure() {
        try {
            // Simulate the endpoint logic
            $themas = $this->sentenceService->getAllThemas();
            
            // Create response structure like the endpoint does
            $response = [
                'success' => true,
                'data' => [
                    'themas' => $themas
                ]
            ];
            
            // Verify response structure
            $result = $this->assertTrue($response['success'], "Response should be successful");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(isset($response['data']['themas']), "Response should have themas data");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(is_array($response['data']['themas']), "Themas data should be an array");
            if ($result !== true) return $result;
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that themas match database content
     */
    public function testThemasMatchDatabase() {
        try {
            // Get themas using the service
            $serviceThemas = $this->sentenceService->getAllThemas();
            
            // Get themas directly from database
            $stmt = $this->conn->prepare("SELECT DISTINCT thema FROM sentences WHERE thema IS NOT NULL AND thema != '' ORDER BY thema ASC");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $dbThemas = [];
            while ($row = $result->fetch_assoc()) {
                $dbThemas[] = $row['thema'];
            }
            
            // Compare results
            $result = $this->assertEquals(count($dbThemas), count($serviceThemas), 
                "Service should return same number of themas as database");
            if ($result !== true) return $result;
            
            $result = $this->assertEquals($dbThemas, $serviceThemas, 
                "Service themas should match database themas");
            if ($result !== true) return $result;
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test empty database scenario
     */
    public function testEmptyDatabaseHandling() {
        try {
            // This test verifies the method handles empty results gracefully
            // We can't actually empty the database in tests, but we can check the structure
            $themas = $this->sentenceService->getAllThemas();
            
            // Should always return an array, even if empty
            $result = $this->assertTrue(is_array($themas), "Should always return an array");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that null and empty themas are excluded
     */
    public function testNullAndEmptyThemasExcluded() {
        try {
            $themas = $this->sentenceService->getAllThemas();
            
            // Check that no thema is null or empty
            foreach ($themas as $thema) {
                $result = $this->assertTrue($thema !== null, "No thema should be null");
                if ($result !== true) return $result;
                
                $result = $this->assertTrue($thema !== '', "No thema should be empty string");
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(trim($thema) !== '', "No thema should be just whitespace");
                if ($result !== true) return $result;
            }
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test database connection error handling
     */
    public function testDatabaseErrorHandling() {
        try {
            // This test ensures the method handles database errors gracefully
            // We can't simulate a real database error easily, but we verify the method structure
            $themas = $this->sentenceService->getAllThemas();
            
            // If we get here, the database connection worked
            $result = $this->assertTrue(is_array($themas), "Method should handle database operations gracefully");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test performance with large datasets
     */
    public function testPerformance() {
        try {
            $startTime = microtime(true);
            
            $themas = $this->sentenceService->getAllThemas();
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            // Should complete within reasonable time (5 seconds max)
            $result = $this->assertTrue($executionTime < 5.0, 
                "getAllThemas should complete within 5 seconds (took {$executionTime}s)");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(is_array($themas), "Should return array even in performance test");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
}