<?php
/**
 * Quick // Test cases
$testCases = [
    'AAP-A' => 'Aap',              // Should remove -A and capitalize
    'PANNENKOEK-D' => 'Pannenkoek', // Should remove -D and capitalize  
    'ZEBRA-Z' => 'Zebra',          // Should remove -Z and capitalize
    'PANNENKOEK-BAKKEN' => 'Pannenkoek-bakken', // Should keep multiple letters after hyphen
    'NORMAL' => 'Normal',          // Should just capitalize
    'lowercase' => 'Lowercase',    // Should just capitalize
    'MiXeD' => 'Mixed',           // Should normalize to proper case
    '' => '',                      // Should handle empty string
    'test-word' => 'Test-word',    // Should only affect first letter, keep hyphen with word
    'WORD-B' => 'Word',           // Should remove single letter at end
    'WORD-BB' => 'Word-bb'        // Should keep multiple letters at end
];fy sense processing functionality
 */

require_once 'src/config/config.php';
require_once 'src/services/SearchService.php';

// Create a mock response array
$response = [];

// Create SearchService instance
$searchService = new SearchService($conn, $response);

// Use reflection to test the private processSenseValue method
$reflection = new ReflectionClass($searchService);
$method = $reflection->getMethod('processSenseValue');
$method->setAccessible(true);

// Test cases
$testCases = [
    'AAAP-A' => 'Aaap',         // Should remove -A and capitalize
    '-BEER' => 'Beer',         // Should remove -B and capitalize  
    '-ZEBRA' => 'Zebra',       // Should remove -Z and capitalize
    'NORMAL' => 'Normal',      // Should just capitalize
    'lowercase' => 'Lowercase', // Should just capitalize
    'MiXeD' => 'Mixed',        // Should normalize to proper case
    '' => '',                  // Should handle empty string
    '-a' => '-a',              // Should not affect lowercase after hyphen
    'test-word' => 'Test-word' // Should only affect first letter
];

echo "Testing sense processing:\n";
echo "========================\n\n";

foreach ($testCases as $input => $expected) {
    $result = $method->invoke($searchService, $input);
    $status = ($result === $expected) ? "✓ PASS" : "✗ FAIL";
    echo sprintf("Input: %-15s Expected: %-15s Got: %-15s %s\n", 
                 "'{$input}'", "'{$expected}'", "'{$result}'", $status);
}

echo "\nTesting processSensesArray method:\n";
echo "==================================\n\n";

$arrayMethod = $reflection->getMethod('processSensesArray');
$arrayMethod->setAccessible(true);

$testArray = ['AAP-A', 'PANNENKOEK-BAKKEN', 'CHERRY-C', 'date'];
$expectedArray = ['Aap', 'Pannenkoek-bakken', 'Cherry', 'Date'];
$resultArray = $arrayMethod->invoke($searchService, $testArray);

echo "Input array: " . json_encode($testArray) . "\n";
echo "Expected:    " . json_encode($expectedArray) . "\n";
echo "Result:      " . json_encode($resultArray) . "\n";
echo "Status:      " . (json_encode($resultArray) === json_encode($expectedArray) ? "✓ PASS" : "✗ FAIL") . "\n";

echo "\nAll tests completed!\n";
?>
