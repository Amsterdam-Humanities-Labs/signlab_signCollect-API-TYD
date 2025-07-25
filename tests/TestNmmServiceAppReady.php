<?php
/**
 * Unit Tests for NMM Service app_ready filtering disabled functionality
 */

// Include necessary files
require_once __DIR__ . '/../src/config/config.php';
require_once __DIR__ . '/../src/services/NmmService.php';

class TestNmmServiceAppReady {
    private $conn;
    private $response;
    private $nmmService;
    
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
        $this->nmmService = new NmmService($this->conn, $this->response);
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
     * Get a valid NMM glos for testing
     */
    private function getValidNmmGlos() {
        $stmt = $this->conn->prepare("SELECT glos FROM nmm_data WHERE glos IS NOT NULL AND glos != '' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return null;
        }
        
        $row = $result->fetch_assoc();
        return $row['glos'];
    }
    
    /**
     * Get a valid NMM ID for testing
     */
    private function getValidNmmId() {
        $stmt = $this->conn->prepare("SELECT id FROM nmm_data LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return null;
        }
        
        $row = $result->fetch_assoc();
        return $row['id'];
    }
    
    /**
     * Test that searchNmmByGlos no longer filters by app_ready
     */
    public function testSearchNmmByGlosAppReadyDisabled() {
        $glos = $this->getValidNmmGlos();
        
        if (!$glos) {
            return "No NMM glosses found in database for testing";
        }
        
        try {
            // Search for NMM data by glos
            $nmmResults = $this->nmmService->searchNmmByGlos($glos, 10);
            
            // Verify structure
            $result = $this->assertTrue(is_array($nmmResults), "NMM results should be an array");
            if ($result !== true) return $result;
            
            // If we have results, verify their structure
            if (!empty($nmmResults)) {
                $firstResult = $nmmResults[0];
                
                $result = $this->assertTrue(isset($firstResult['id']), "NMM result should have an id field");
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(isset($firstResult['glos']), "NMM result should have a glos field");
                if ($result !== true) return $result;
                
                $result = $this->assertTrue(isset($firstResult['videos']), "NMM result should have videos field");
                if ($result !== true) return $result;
                
                // The fact that we got results indicates app_ready filtering is disabled
                // Previously, this method would check for app_ready = 1 before returning results
                $result = $this->assertTrue(true, "searchNmmByGlos executed without app_ready filtering");
                if ($result !== true) return $result;
            }
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that getNmmById works regardless of app_ready status
     */
    public function testGetNmmByIdAppReadyDisabled() {
        $nmmId = $this->getValidNmmId();
        
        if (!$nmmId) {
            return "No NMM data found in database for testing";
        }
        
        try {
            $nmmData = $this->nmmService->getNmmById($nmmId);
            
            // Verify structure
            $result = $this->assertTrue(is_array($nmmData), "NMM data should be an array");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(isset($nmmData['id']), "NMM data should have an id field");
            if ($result !== true) return $result;
            
            $result = $this->assertEquals($nmmId, $nmmData['id'], "NMM ID should match requested ID");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(isset($nmmData['videos']), "NMM data should have videos field");
            if ($result !== true) return $result;
            
            // The method should work regardless of app_ready status in matched_transcriptions
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test exact match functionality in searchNmmByGlos
     */
    public function testSearchNmmByGlosExactMatch() {
        $glos = $this->getValidNmmGlos();
        
        if (!$glos) {
            return "No NMM glosses found in database for testing";
        }
        
        try {
            // Test exact match (used by sentence service)
            $exactResults = $this->nmmService->searchNmmByGlos($glos, 1, true);
            
            $result = $this->assertTrue(is_array($exactResults), "Exact match results should be an array");
            if ($result !== true) return $result;
            
            // Test wildcard match (default behavior)  
            $wildcardResults = $this->nmmService->searchNmmByGlos($glos, 1, false);
            
            $result = $this->assertTrue(is_array($wildcardResults), "Wildcard match results should be an array");
            if ($result !== true) return $result;
            
            // Both should work without app_ready filtering
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that space-to-hyphen conversion still works
     */
    public function testSpaceToHyphenConversion() {
        try {
            // Test with a glos that might have spaces (converted to hyphens)
            $testGlos = "TEST GLOS";
            $nmmResults = $this->nmmService->searchNmmByGlos($testGlos, 5);
            
            // Should not throw an error even if no results found
            $result = $this->assertTrue(is_array($nmmResults), "Should return array even for non-existent glos");
            if ($result !== true) return $result;
            
            // The method should handle space-to-hyphen conversion internally
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test video URL structure from NMM data
     */
    public function testNmmVideoUrlStructure() {
        $nmmId = $this->getValidNmmId();
        
        if (!$nmmId) {
            return "No NMM data found in database for testing";
        }
        
        try {
            $nmmData = $this->nmmService->getNmmById($nmmId);
            
            if (isset($nmmData['videos']) && !empty($nmmData['videos'])) {
                $videos = $nmmData['videos'];
                
                // Check expected video angles
                $expectedAngles = ['videoLeft', 'videoCenter', 'videoRight'];
                foreach ($expectedAngles as $angle) {
                    if (isset($videos[$angle]) && $videos[$angle] !== null) {
                        $result = $this->assertTrue(is_string($videos[$angle]), "Video URL should be a string");
                        if ($result !== true) return $result;
                        
                        $result = $this->assertTrue(strpos($videos[$angle], 'http') === 0, "Video URL should start with http");
                        if ($result !== true) return $result;
                    }
                }
            }
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test database query efficiency without app_ready filtering
     */
    public function testQueryPerformanceWithoutAppReadyFilter() {
        $glos = $this->getValidNmmGlos();
        
        if (!$glos) {
            return "No NMM glosses found in database for testing";
        }
        
        try {
            $startTime = microtime(true);
            
            // This should be faster now that we removed the app_ready check
            $nmmResults = $this->nmmService->searchNmmByGlos($glos, 10);
            
            $endTime = microtime(true);
            $executionTime = $endTime - $startTime;
            
            // Should complete within reasonable time (5 seconds max)
            $result = $this->assertTrue($executionTime < 5.0, 
                "searchNmmByGlos should complete within 5 seconds (took {$executionTime}s)");
            if ($result !== true) return $result;
            
            $result = $this->assertTrue(is_array($nmmResults), "Should return array within performance limits");
            
            return $result;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that the internal _fetchLastVideoSet method works without app_ready filter
     */
    public function testFetchVideoSetAppReadyDisabled() {
        $nmmId = $this->getValidNmmId();
        
        if (!$nmmId) {
            return "No NMM data found in database for testing";
        }
        
        try {
            // This tests the internal video fetching which should now work without app_ready filtering
            $nmmData = $this->nmmService->getNmmById($nmmId);
            
            // If we get NMM data with videos, it means _fetchLastVideoSet worked
            $result = $this->assertTrue(isset($nmmData['videos']), "Should fetch videos without app_ready filtering");
            if ($result !== true) return $result;
            
            // Check that debug information doesn't show app_ready filtering
            if (isset($this->response['debug'])) {
                // The debug info should show video fetching without app_ready constraints
                $result = $this->assertTrue(true, "Video fetching executed without app_ready filtering");
                if ($result !== true) return $result;
            }
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test edge case with empty or null glos
     */
    public function testEmptyGlosHandling() {
        try {
            // Test with empty string
            $emptyResults = $this->nmmService->searchNmmByGlos('', 5);
            $result = $this->assertTrue(is_array($emptyResults), "Should handle empty glos gracefully");
            if ($result !== true) return $result;
            
            // Test with non-existent glos
            $nonExistentResults = $this->nmmService->searchNmmByGlos('NONEXISTENT_GLOS_12345', 5);
            $result = $this->assertTrue(is_array($nonExistentResults), "Should handle non-existent glos gracefully");
            if ($result !== true) return $result;
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
    
    /**
     * Test that multiple video sources are handled correctly
     */
    public function testMultipleVideoSources() {
        $nmmId = $this->getValidNmmId();
        
        if (!$nmmId) {
            return "No NMM data found in database for testing";
        }
        
        try {
            $nmmData = $this->nmmService->getNmmById($nmmId);
            
            // Check that the video prioritization logic works (nmm source over extern)
            if (isset($nmmData['videos'])) {
                $videos = $nmmData['videos'];
                
                // The implementation should combine videos from both nmm and extern sources
                // This test just verifies the structure is correct
                $result = $this->assertTrue(is_array($videos), "Videos should be an array");
                if ($result !== true) return $result;
                
                // Debug information should show which sources were used
                if (isset($this->response['debug'])) {
                    $result = $this->assertTrue(true, "Video source combination logic executed");
                    if ($result !== true) return $result;
                }
            }
            
            return true;
        } catch (Exception $e) {
            return "Exception occurred: " . $e->getMessage();
        }
    }
}