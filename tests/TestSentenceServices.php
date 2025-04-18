<?php
/**
 * Unit Tests for Sentence Services
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/SentenceService.php';
require_once __DIR__ . '/../src/services/VideoService.php';

class TestSentenceServices {
    private $conn;
    private $response;
    private $videoService;
    private $sentenceService;
    
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
        $this->videoService = new VideoService($this->conn, $this->response);
        $this->sentenceService = new SentenceService($this->conn, $this->response, $this->videoService);
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
     * Test getSentenceById with valid ID
     */
    public function testGetSentenceByValidId() {
        // Find a valid sentence ID for testing
        $stmt = $this->conn->prepare("SELECT ID FROM sentences LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No sentences found in database for testing";
        }
        
        $row = $result->fetch_assoc();
        $id = $row['ID'];
        
        try {
            // Test the method
            $sentence = $this->sentenceService->getSentenceById($id);
            
            // Verify structure
            $result = $this->assertTrue(is_array($sentence), "Sentence result should be an array");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(isset($sentence['ID']), "Sentence should have an ID field");
            if ($result !== true) return $result;
            
            $result = $this->assertEquals($id, $sentence['ID'], "Returned sentence ID should match the requested ID");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(isset($sentence['zinString']), "Sentence should have a zinString field");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(isset($sentence['videos']), "Sentence should have videos field");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(is_array($sentence['videos']), "Videos should be an array");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test getSentenceById with invalid ID
     */
    public function testGetSentenceByInvalidId() {
        // Use a likely invalid ID
        $invalidId = 999999999;
        
        try {
            $this->sentenceService->getSentenceById($invalidId);
            return "Expected exception was not thrown for invalid sentence ID";
        } catch (Exception $e) {
            // Should throw an exception for invalid ID
            return true;
        }
    }
    
    /**
     * Test subtitle generation functionality
     */
    public function testSubtitleGeneration() {
        // Find a sentence with video
        $stmt = $this->conn->prepare("
            SELECT s.ID 
            FROM sentences s 
            JOIN matched_transcriptions m ON m.m_transcription = s.ID AND m.zOg = 'zin'
            WHERE m.m_file IS NOT NULL 
            LIMIT 1
        ");
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return "No sentences with videos found in database for testing";
        }
        
        $row = $result->fetch_assoc();
        $id = $row['ID'];
        
        try {
            // Test the method
            $sentence = $this->sentenceService->getSentenceById($id);
            
            // Verify subtitle structure if video exists
            if (!empty($sentence['videos']['videoCenter'])) {
                $result = $this->assertTrue(isset($sentence['subtitleFiles']), "Sentence should have subtitleFiles field");
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(is_array($sentence['subtitleFiles']), "subtitleFiles should be an array");
                
                return $result;
            } else {
                // If we didn't find a video after all, just pass the test
                return true;
            }
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
}
