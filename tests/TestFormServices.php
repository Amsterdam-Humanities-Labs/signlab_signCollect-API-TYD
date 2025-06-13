<?php
/**
 * Unit Tests for Form Services
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/FormService.php';
require_once __DIR__ . '/../src/services/VideoService.php';
require_once __DIR__ . '/../src/services/NmmService.php';
require_once __DIR__ . '/../src/services/ApiLogger.php';

class TestFormServices {
    private $conn;
    private $response;
    private $formService;
    private $videoService;
    private $nmmService;
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
        $this->nmmService = new NmmService($this->conn, $this->response);
        $this->formService = new FormService($this->conn, $this->response, $this->videoService, $this->nmmService);
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
     * Test FormService constructor and basic setup
     */
    public function testFormServiceConstructor() {
        $result = $this->assertTrue(is_object($this->formService), "FormService should be instantiated");
        if ($result !== true) return $result;
        
        return "FormService constructor test passed";
    }
    
    /**
     * Test searchFormsByGlos method with a known term
     */
    public function testSearchFormsByGlos() {
        // Find a form_data record to search for
        $stmt = $this->conn->prepare("SELECT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos IS NOT NULL AND glos != '' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No form_data records found for testing";
        }
        
        $row = $result->fetch_assoc();
        $testGlos = $row['glos'];
        $stmt->close();
        
        // Test the search method
        $searchResults = $this->formService->searchFormsByGlos($testGlos);
        
        // Verify we got an array
        $result = $this->assertTrue(is_array($searchResults), "Search results should be an array");
        if ($result !== true) return $result;
        
        // Verify we got at least one result
        $result = $this->assertNotEmpty($searchResults, "Search for '$testGlos' should return results");
        if ($result !== true) return $result;
        
        // Check the structure of the first result
        $firstResult = $searchResults[0];
        
        $requiredKeys = ['id', 'senses', 'signbank', 'thema', 'glos', 'videos', 'nmm_data'];
        foreach ($requiredKeys as $key) {
            $result = $this->assertArrayHasKey($key, $firstResult, "Result should contain '$key' field");
            if ($result !== true) return $result;
        }
        
        return "FormService searchFormsByGlos test passed";
    }
    
    /**
     * Test searchFormsByGlos with partial matching
     */
    public function testSearchFormsByGlosPartialMatch() {
        // Find a form_data record and use part of it for search
        $stmt = $this->conn->prepare("SELECT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos IS NOT NULL AND glos != '' AND LENGTH(glos) > 3 LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No suitable form_data records found for partial matching test";
        }
        
        $row = $result->fetch_assoc();
        $fullGlos = $row['glos'];
        $partialGlos = substr($fullGlos, 0, 3); // Use first 3 characters
        $stmt->close();
        
        // Test the search method with partial term
        $searchResults = $this->formService->searchFormsByGlos($partialGlos);
        
        // Verify we got an array
        $result = $this->assertTrue(is_array($searchResults), "Partial search results should be an array");
        if ($result !== true) return $result;
        
        // Verify each result starts with our search term
        foreach ($searchResults as $searchResult) {
            $resultGlos = $searchResult['glos'] ?? '';
            if (!empty($resultGlos)) {
                $startsWithPartial = strpos($resultGlos, $partialGlos) === 0;
                $result = $this->assertTrue($startsWithPartial, "Result glos '$resultGlos' should start with '$partialGlos'");
                if ($result !== true) return $result;
            }
        }
        
        return "FormService partial matching test passed";
    }
    
    /**
     * Test searchFormsByGlos with non-existent term
     */
    public function testSearchFormsByGlosNonExistent() {
        $nonExistentTerm = "NONEXISTENTGLOSTERM12345";
        
        // Test the search method
        $searchResults = $this->formService->searchFormsByGlos($nonExistentTerm);
        
        // Verify we got an array
        $result = $this->assertTrue(is_array($searchResults), "Search results should be an array even for non-existent terms");
        if ($result !== true) return $result;
        
        // Verify the array is empty
        $result = $this->assertTrue(empty($searchResults), "Search for non-existent term should return empty array");
        if ($result !== true) return $result;
        
        return "FormService non-existent term test passed";
    }
    
    /**
     * Test searchFormsByGlos with empty input
     */
    public function testSearchFormsByGlosEmpty() {
        // Test with empty string
        $searchResults = $this->formService->searchFormsByGlos("");
        
        // Verify we got an array
        $result = $this->assertTrue(is_array($searchResults), "Search results should be an array for empty input");
        if ($result !== true) return $result;
        
        // With empty string and LIKE pattern, this could return many results or none depending on data
        // Just verify it doesn't crash
        return "FormService empty input test passed";
    }
    
    /**
     * Test searchFormsByGlos with special characters
     */
    public function testSearchFormsByGlosSpecialCharacters() {
        $specialChars = [
            "test'quote",
            'test"doublequote',
            'test;semicolon',
            'test%percent',
            'test_underscore'
        ];
        
        foreach ($specialChars as $testString) {
            try {
                $searchResults = $this->formService->searchFormsByGlos($testString);
                
                // Verify we got an array without exceptions
                $result = $this->assertTrue(is_array($searchResults), "Search with special characters should return an array");
                if ($result !== true) return $result;
                
            } catch (Exception $e) {
                return "Search with special characters failed: " . $e->getMessage();
            }
        }
        
        return "FormService special characters test passed";
    }
    
    /**
     * Test getFormById method
     */
    public function testGetFormById() {
        // Find a form_data record to test with
        $stmt = $this->conn->prepare("SELECT id FROM form_data WHERE extern = '1' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No form_data records found for getFormById test";
        }
        
        $row = $result->fetch_assoc();
        $testId = $row['id'];
        $stmt->close();
        
        try {
            // Test the getFormById method
            $formData = $this->formService->getFormById($testId);
            
            // Verify we got an array
            $result = $this->assertTrue(is_array($formData), "getFormById should return an array");
            if ($result !== true) return $result;
            
            // Check required fields
            $requiredKeys = ['id', 'senses', 'signbank', 'videos', 'nmm_data'];
            foreach ($requiredKeys as $key) {
                $result = $this->assertArrayHasKey($key, $formData, "Form data should contain '$key' field");
                if ($result !== true) return $result;
            }
            
            // Verify the ID matches
            $result = $this->assertEquals($testId, $formData['id'], "Returned form ID should match requested ID");
            if ($result !== true) return $result;
            
            return "FormService getFormById test passed";
            
        } catch (Exception $e) {
            return "getFormById test failed with exception: " . $e->getMessage();
        }
    }
    
    /**
     * Test getFormById with non-existent ID
     */
    public function testGetFormByIdNonExistent() {
        $nonExistentId = 999999999; // Very large ID that shouldn't exist
        
        try {
            $formData = $this->formService->getFormById($nonExistentId);
            return "getFormById should throw exception for non-existent ID";
        } catch (Exception $e) {
            // This is expected behavior
            return "FormService getFormById non-existent ID test passed";
        }
    }
    
    /**
     * Test video integration in searchFormsByGlos
     */
    public function testSearchFormsByGlosVideoIntegration() {
        // Find a form_data record
        $stmt = $this->conn->prepare("SELECT glos FROM form_data WHERE extern = '1' AND glosZichtbaar = '0' AND glos IS NOT NULL AND glos != '' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No form_data records found for video integration test";
        }
        
        $row = $result->fetch_assoc();
        $testGlos = $row['glos'];
        $stmt->close();
        
        // Test the search method
        $searchResults = $this->formService->searchFormsByGlos($testGlos);
        
        if (empty($searchResults)) {
            return "No search results found for video integration test";
        }
        
        $firstResult = $searchResults[0];
        
        // Check that videos field exists and has expected structure
        $result = $this->assertArrayHasKey('videos', $firstResult, "Result should contain videos field");
        if ($result !== true) return $result;
        
        $videos = $firstResult['videos'];
        $result = $this->assertTrue(is_array($videos), "Videos should be an array");
        if ($result !== true) return $result;
        
        // Check for expected video angle keys
        $expectedVideoKeys = ['videoLeft', 'videoCenter', 'videoRight'];
        foreach ($expectedVideoKeys as $key) {
            $result = $this->assertArrayHasKey($key, $videos, "Videos should contain '$key' field");
            if ($result !== true) return $result;
        }
        
        return "FormService video integration test passed";
    }
}