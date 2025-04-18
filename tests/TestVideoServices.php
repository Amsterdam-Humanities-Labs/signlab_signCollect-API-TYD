<?php
/**
 * Unit Tests for Video Services
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/VideoService.php';

class TestVideoServices {
    private $conn;
    private $response;
    private $videoService;
    
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
        
        // Initialize service
        $this->videoService = new VideoService($this->conn, $this->response);
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
     * Test getVideosForEntity for zin type
     */
    public function testGetVideosForZin() {
        // Find a valid sentence ID for testing
        $stmt = $this->conn->prepare("SELECT ID FROM sentences LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No sentences found in database for testing";
        }
        
        $row = $result->fetch_assoc();
        $id = $row['ID'];
        
        // Test the method
        $videos = $this->videoService->getVideosForEntity($id, 'zin');
        
        // Verify structure
        $result = $this->assertTrue(is_array($videos), "Videos should be an array");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoLeft', $videos), "Videos should have videoLeft key");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoCenter', $videos), "Videos should have videoCenter key");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoRight', $videos), "Videos should have videoRight key");
        
        return $result;
    }
    
    /**
     * Test getVideosForEntity for glos type
     */
    public function testGetVideosForGlos() {
        // Find a valid form ID for testing
        $stmt = $this->conn->prepare("SELECT id FROM form_data LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No forms found in database for testing";
        }
        
        $row = $result->fetch_assoc();
        $id = $row['id'];
        
        // Test the method
        $videos = $this->videoService->getVideosForEntity($id, 'glos');
        
        // Verify structure
        $result = $this->assertTrue(is_array($videos), "Videos should be an array");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoLeft', $videos), "Videos should have videoLeft key");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoCenter', $videos), "Videos should have videoCenter key");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoRight', $videos), "Videos should have videoRight key");
        
        return $result;
    }
    
    /**
     * Test for non-existent entity
     */
    public function testNonExistentEntity() {
        // Use a likely non-existent ID
        $nonExistentId = 999999999;
        
        // Test the method
        $videos = $this->videoService->getVideosForEntity($nonExistentId, 'zin');
        
        // Verify structure still exists even if empty
        $result = $this->assertTrue(is_array($videos), "Videos should be an array even for non-existent entity");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoLeft', $videos), "Videos should have videoLeft key");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoCenter', $videos), "Videos should have videoCenter key");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue(array_key_exists('videoRight', $videos), "Videos should have videoRight key");
        if ($result !== true) return $result;
        
        // Verify all videos are null for non-existent entity
        $result = $this->assertTrue($videos['videoLeft'] === null, "videoLeft should be null for non-existent entity");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue($videos['videoCenter'] === null, "videoCenter should be null for non-existent entity");
        if ($result !== true) return $result;
        
        $result = $this->assertTrue($videos['videoRight'] === null, "videoRight should be null for non-existent entity");
        
        return $result;
    }

    /**
     * Test with malicious entity ID inputs
     */
    public function testMaliciousEntityIds() {
        // Array of malicious ID inputs to test
        $maliciousIds = [
            "1' OR '1'='1", // SQL injection attempt
            "1; DROP TABLE videos;", // SQL command injection
            "<script>alert('XSS')</script>", // XSS attempt
            "../../../etc/passwd", // Path traversal attempt
            "999999999999999999999999999999", // Extremely large number
            "-1", // Negative ID
            "null", // String 'null'
            "true", // String 'true'
            "123456' UNION SELECT username, password FROM users --", // Union injection
            "../../mysql_config_test.php" // Path traversal to config
        ];
        
        // This should always be a safe entity type
        $safeType = 'zin';
        
        foreach ($maliciousIds as $maliciousId) {
            try {
                // Attempt to use the malicious ID
                $videos = $this->videoService->getVideosForEntity($maliciousId, $safeType);
                
                // For any result, we should get a consistent structure, even if empty
                $result = $this->assertTrue(is_array($videos), "Result should be an array even for malicious input");
                if ($result !== true) return $result;
                
                // Check each key individually to make debugging easier
                $result = $this->assertTrue(array_key_exists('videoLeft', $videos), 
                    "Result should have videoLeft key for malicious input: " . json_encode($videos));
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(array_key_exists('videoCenter', $videos), 
                    "Result should have videoCenter key for malicious input: " . json_encode($videos));
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(array_key_exists('videoRight', $videos), 
                    "Result should have videoRight key for malicious input: " . json_encode($videos));
                if ($result !== true) return $result;
                
                // Check for any suspicious data in the result
                // ...existing code...
            } catch (Exception $e) {
                // ...existing code...
            }
        }
        
        // If we've made it through all tests without returning, that's a pass
        return true;
    }
    
    /**
     * Test with malicious entity type inputs
     */
    public function testMaliciousEntityTypes() {
        // Use a known good ID to isolate the type testing
        $safeId = 1;
        
        // Array of malicious type inputs
        $maliciousTypes = [
            "zin' OR '1'='1", // SQL injection
            "glos; DROP TABLE videos;", // SQL command injection
            "<script>alert('XSS')</script>", // XSS attempt
            "../../../etc/passwd", // Path traversal
            "' UNION SELECT username,password FROM users --", // Union injection
            "1=1", // SQL Boolean
            "' OR 1=1 --", // OR injection
            "glos' --", // Comment injection
            "sb%00", // Null byte injection
            "zin/**/UNION/**/SELECT/**/1,2,3" // Comment-separated SQL injection
        ];
        
        foreach ($maliciousTypes as $maliciousType) {
            try {
                $videos = $this->videoService->getVideosForEntity($safeId, $maliciousType);
                
                // For any result, we should get a consistent structure, even if empty
                $result = $this->assertTrue(is_array($videos), "Result should be an array even for malicious type");
                if ($result !== true) return $result;
                
                // Check each key individually to make debugging easier
                $result = $this->assertTrue(array_key_exists('videoLeft', $videos), 
                    "Result should have videoLeft key for malicious type: " . json_encode($videos));
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(array_key_exists('videoCenter', $videos), 
                    "Result should have videoCenter key for malicious type: " . json_encode($videos));
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(array_key_exists('videoRight', $videos), 
                    "Result should have videoRight key for malicious type: " . json_encode($videos));
                if ($result !== true) return $result;
                
                // Check for signs of successful SQL injection
                // ...existing code...
            } catch (Exception $e) {
                // ...existing code...
            }
        }
        
        return true;
    }
    
    /**
     * Test combination of malicious ID and type
     */
    public function testMaliciousCombination() {
        // Try a few combinations of malicious ID and type
        $maliciousCombos = [
            ["1' OR '1'='1", "zin' OR '1'='1"],
            ["1; --", "glos; --"],
            ["<script>alert(1)</script>", "<script>alert(2)</script>"],
            ["123 UNION SELECT 1,2,3", "zin UNION SELECT 4,5,6"]
        ];
        
        foreach ($maliciousCombos as $combo) {
            $maliciousId = $combo[0];
            $maliciousType = $combo[1];
            
            try {
                $videos = $this->videoService->getVideosForEntity($maliciousId, $maliciousType);
                
                // If no exception, check structure and content
                $result = $this->assertTrue(is_array($videos), 
                    "Result should be an array even for malicious combo");
                if ($result !== true) return $result;
                
            } catch (Exception $e) {
                // Exception is acceptable but should not leak info
                $message = $e->getMessage();
                $sensitiveTerms = ["SQL syntax", "mysql", "database", "SELECT", "query failed"];
                $hasLeakedInfo = false;
                
                foreach ($sensitiveTerms as $term) {
                    if (stripos($message, $term) !== false) {
                        $hasLeakedInfo = true;
                        break;
                    }
                }
                
                if ($hasLeakedInfo) {
                    return "Malicious combo test (ID: '$maliciousId', Type: '$maliciousType') revealed sensitive information: $message";
                }
            }
        }
        
        return true;
    }
}
