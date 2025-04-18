<?php
/**
 * Test Runner for API Unit Tests
 * 
 * This file runs all the unit tests for our API services
 */

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load test classes
require_once 'TestVideoServices.php';
require_once 'TestSearchServices.php';
require_once 'TestSentenceServices.php';

// Determine if running from CLI or browser
$isCli = php_sapi_name() === 'cli';

/**
 * Simple test runner
 */
class TestRunner {
    private $testClasses = [];
    private $results = [
        'passed' => 0,
        'failed' => 0,
        'tests' => []
    ];
    private $isCli;
    
    /**
     * Constructor
     *
     * @param bool $isCli Whether running in CLI mode
     */
    public function __construct($isCli = false) {
        $this->isCli = $isCli;
    }
    
    /**
     * Add a test class
     *
     * @param object $testClass Instance of a test class
     */
    public function addTestClass($testClass) {
        $this->testClasses[] = $testClass;
    }
    
    /**
     * Run all tests
     */
    public function runTests() {
        $this->outputHeader();
        
        foreach ($this->testClasses as $testClass) {
            $className = get_class($testClass);
            $this->output("\n🧪 Running tests for: " . $className);
            
            $methods = get_class_methods($testClass);
            foreach ($methods as $method) {
                // Only run methods that start with "test"
                if (strpos($method, 'test') === 0) {
                    $this->output("\n  ▶️ $method");
                    
                    try {
                        $start = microtime(true);
                        $result = $testClass->$method();
                        $end = microtime(true);
                        $time = round(($end - $start) * 1000, 2); // ms
                        
                        if ($result === true) {
                            $this->results['passed']++;
                            $this->results['tests'][] = [
                                'name' => "$className::$method",
                                'result' => 'passed',
                                'time' => $time
                            ];
                            $this->output("    ✅ Passed ($time ms)");
                        } else {
                            $this->results['failed']++;
                            $this->results['tests'][] = [
                                'name' => "$className::$method",
                                'result' => 'failed',
                                'message' => is_string($result) ? $result : 'Test failed'
                            ];
                            $this->output("    ❌ Failed: " . (is_string($result) ? $result : 'Test failed'));
                        }
                    } catch (Exception $e) {
                        $this->results['failed']++;
                        $this->results['tests'][] = [
                            'name' => "$className::$method",
                            'result' => 'error',
                            'message' => $e->getMessage()
                        ];
                        $this->output("    ❌ Error: " . $e->getMessage());
                    }
                }
            }
        }
        
        $this->outputSummary();
    }
    
    /**
     * Output test header
     */
    private function outputHeader() {
        $this->output("\n===================================");
        $this->output("🧪 API UNIT TESTS 🧪");
        $this->output("===================================");
    }
    
    /**
     * Output test summary
     */
    private function outputSummary() {
        $total = $this->results['passed'] + $this->results['failed'];
        $this->output("\n===================================");
        $this->output("📊 TEST SUMMARY");
        $this->output("===================================");
        $this->output("Total tests: $total");
        $this->output("Passed: " . $this->results['passed'] . " (" . 
            ($total > 0 ? round(($this->results['passed'] / $total) * 100) : 0) . "%)");
        $this->output("Failed: " . $this->results['failed']);
        $this->output("===================================\n");
    }
    
    /**
     * Output text appropriately for CLI or browser
     *
     * @param string $text Text to output
     */
    private function output($text) {
        if ($this->isCli) {
            echo $text . "\n";
        } else {
            echo htmlspecialchars($text) . "<br>";
        }
    }
}

// Create test runner
$runner = new TestRunner($isCli);

// Initialize tests to run
if (!$isCli) {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>API Unit Tests</title>
        <style>
            body { font-family: monospace; margin: 20px; background-color: #f5f5f5; }
            pre { background: #fff; padding: 10px; border: 1px solid #ddd; }
        </style>
    </head>
    <body><pre>';
}

// Add test classes
$runner->addTestClass(new TestVideoServices());
$runner->addTestClass(new TestSearchServices());
$runner->addTestClass(new TestSentenceServices());

// Run tests
$runner->runTests();

if (!$isCli) {
    echo '</pre></body></html>';
}
