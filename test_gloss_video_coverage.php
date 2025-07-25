<?php
/**
 * Video Coverage Analysis Script
 * 
 * Analyzes what percentage of sentence glosses have available videos
 * from form_data and nmm_data sources, outputting results in JSON format.
 */

// Load configuration and service files
require_once __DIR__ . '/src/config/config.php';
require_once __DIR__ . '/src/services/ApiLogger.php';
require_once __DIR__ . '/src/services/SentenceService.php';
require_once __DIR__ . '/src/services/NmmService.php';

// Include the MySQL configuration file
require_once __DIR__ . '/mysql_config.php';

// Start execution timer
$startTime = microtime(true);

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'error' => 'Connection failed: ' . $conn->connect_error,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT));
}
$conn->set_charset("utf8mb4");

// Initialize services
$response = [];
$logger = new ApiLogger($conn);
$sentenceService = new SentenceService($conn, $response, null);
$nmmService = new NmmService($conn, $response);

// Configuration
$validateUrls = isset($_GET['validate_urls']) || in_array('--validate-urls', $argv ?? []) ? true : false;
$verbose = isset($_GET['verbose']) || in_array('--verbose', $argv ?? []) ? true : false;

// Results structure
$results = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'summary' => [
        'total_sentences' => 0,
        'total_unique_glosses' => 0,
        'glosses_with_videos' => 0,
        'coverage_percentage' => 0.0,
        'form_data_coverage' => 0.0,
        'nmm_fallback_coverage' => 0.0,
        'sentences_with_full_coverage' => 0,
        'sentence_coverage_percentage' => 0.0
    ],
    'detailed_stats' => [
        'by_source' => [
            'form_data_only' => 0,
            'nmm_data_only' => 0,
            'both_sources' => 0,
            'no_videos' => 0
        ],
        'sentences_without_glosses' => 0,
        'by_sentence_coverage' => [
            '0_percent' => 0,
            '1_to_24_percent' => 0,
            '25_to_49_percent' => 0,
            '50_to_74_percent' => 0,
            '75_to_99_percent' => 0,
            '100_percent' => 0
        ],
        'sentences_with_partial_coverage' => 0,
        'url_validation' => $validateUrls ? ['tested' => 0, 'failed' => 0, 'success_rate' => 0.0] : null
    ],
    'missing_glosses' => [],
    'sentences_with_full_coverage_details' => [],
    'execution_time' => 0.0,
    'debug' => $verbose ? [] : null
];

try {
    if ($verbose) echo "Starting video coverage analysis...\n";
    
    // Phase 1: Fetch all sentences and extract glosses
    if ($verbose) echo "Phase 1: Fetching all sentences...\n";
    
    $sql = "SELECT ID, zinString, thema, glosses FROM sentences WHERE glosses IS NOT NULL AND glosses != ''";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Failed to prepare sentence query: ' . $conn->error);
    }
    
    $stmt->execute();
    $sentenceResult = $stmt->get_result();
    $stmt->close();
    
    $allGlosses = [];
    $sentenceCount = 0;
    $sentencesWithoutGlosses = 0;
    $sentenceGlossMap = []; // NEW: Track sentence-to-glosses mapping
    
    while ($sentence = $sentenceResult->fetch_assoc()) {
        $sentenceCount++;
        
        // Extract glosses from sentence data (same logic as SentenceService)
        $glosses = [];
        if (!empty($sentence['glosses'])) {
            if (is_string($sentence['glosses'])) {
                // Try to decode as JSON first
                $decodedGlosses = json_decode($sentence['glosses'], true);
                if (is_array($decodedGlosses)) {
                    $glosses = $decodedGlosses;
                } else {
                    // If not JSON, split by spaces
                    $glosses = array_filter(explode(' ', trim($sentence['glosses'])));
                }
            } else {
                $glosses = $sentence['glosses'];
            }
        }
        
        if (empty($glosses)) {
            $sentencesWithoutGlosses++;
            continue;
        }
        
        // Clean and store glosses for this sentence
        $cleanGlosses = [];
        foreach ($glosses as $gloss) {
            if (!empty(trim($gloss))) {
                $cleanGloss = trim($gloss);
                $allGlosses[$cleanGloss] = true;
                $cleanGlosses[] = $cleanGloss;
            }
        }
        
        // NEW: Store sentence-to-glosses mapping
        $sentenceGlossMap[$sentence['ID']] = [
            'zinString' => $sentence['zinString'],
            'thema' => $sentence['thema'],
            'glosses' => $cleanGlosses
        ];
        
        if ($verbose && $sentenceCount % 100 == 0) {
            echo "Processed $sentenceCount sentences...\n";
        }
    }
    
    $uniqueGlosses = array_keys($allGlosses);
    $totalUniqueGlosses = count($uniqueGlosses);
    
    $results['summary']['total_sentences'] = $sentenceCount;
    $results['summary']['total_unique_glosses'] = $totalUniqueGlosses;
    $results['detailed_stats']['sentences_without_glosses'] = $sentencesWithoutGlosses;
    
    if ($verbose) {
        echo "Found $sentenceCount sentences with $totalUniqueGlosses unique glosses\n";
        echo "Phase 2: Checking video availability for each gloss...\n";
    }
    
    // Phase 2: Check video availability for each unique gloss
    $glossesWithVideos = 0;
    $formDataCount = 0;
    $nmmDataCount = 0;
    $bothSourcesCount = 0;
    $noVideosCount = 0;
    $missingGlosses = [];
    $glossVideoAvailability = []; // NEW: Track which glosses have videos
    
    $urlValidationStats = ['tested' => 0, 'failed' => 0];
    
    foreach ($uniqueGlosses as $index => $gloss) {
        $hasFormData = false;
        $hasNmmData = false;
        $hasWorkingUrls = false;
        
        // Check form_data first (exact match)
        $formId = getFormDataIdByGlos($conn, $gloss, true);
        if ($formId) {
            $transcriptionData = getMatchedTranscriptionsByFormId($conn, $formId);
            if ($transcriptionData) {
                $hasFormData = true;
                
                // Optionally validate URLs
                if ($validateUrls) {
                    $baseUrl = defined('MEDIA_BASE_URL') ? MEDIA_BASE_URL : 'https://media.signcollect.nl/';
                    $urls = [];
                    
                    if (!empty($transcriptionData['l_file'])) {
                        $urls[] = $baseUrl . preg_replace('/\.wav$/i', '.mp4', $transcriptionData['l_file']);
                    }
                    if (!empty($transcriptionData['m_file'])) {
                        $urls[] = $baseUrl . preg_replace('/\.wav$/i', '.mp4', $transcriptionData['m_file']);
                    }
                    if (!empty($transcriptionData['r_file'])) {
                        $urls[] = $baseUrl . preg_replace('/\.wav$/i', '.mp4', $transcriptionData['r_file']);
                    }
                    
                    foreach ($urls as $url) {
                        $urlValidationStats['tested']++;
                        if (!testUrl($url)) {
                            $urlValidationStats['failed']++;
                        } else {
                            $hasWorkingUrls = true;
                        }
                    }
                } else {
                    $hasWorkingUrls = true; // Assume working if not validating
                }
            }
        }
        
        // If no form_data videos, check NMM data as fallback
        if (!$hasFormData) {
            $nmmResults = $nmmService->searchNmmByGlos($gloss, 1, true);
            if (!empty($nmmResults)) {
                $nmmRecord = $nmmResults[0];
                if (isset($nmmRecord['videos'])) {
                    $hasNmmData = true;
                    
                    // Optionally validate NMM URLs
                    if ($validateUrls) {
                        $nmmUrls = array_filter([
                            $nmmRecord['videos']['videoLeft'] ?? null,
                            $nmmRecord['videos']['videoCenter'] ?? null,
                            $nmmRecord['videos']['videoRight'] ?? null
                        ]);
                        
                        foreach ($nmmUrls as $url) {
                            $urlValidationStats['tested']++;
                            if (!testUrl($url)) {
                                $urlValidationStats['failed']++;
                            } else {
                                $hasWorkingUrls = true;
                            }
                        }
                    } else {
                        $hasWorkingUrls = true; // Assume working if not validating
                    }
                }
            }
        }
        
        // Update statistics
        if ($hasFormData && $hasNmmData) {
            $bothSourcesCount++;
        } elseif ($hasFormData) {
            $formDataCount++;
        } elseif ($hasNmmData) {
            $nmmDataCount++;
        } else {
            $noVideosCount++;
            $missingGlosses[] = $gloss;
        }
        
        if (($hasFormData || $hasNmmData) && (!$validateUrls || $hasWorkingUrls)) {
            $glossesWithVideos++;
            $glossVideoAvailability[$gloss] = true; // NEW: Mark this gloss as having videos
        } else {
            $glossVideoAvailability[$gloss] = false; // NEW: Mark this gloss as not having videos
        }
        
        if ($verbose && ($index + 1) % 50 == 0) {
            echo "Checked " . ($index + 1) . "/$totalUniqueGlosses glosses...\n";
        }
    }
    
    // Phase 2.5: Calculate sentence-level coverage statistics
    if ($verbose) {
        echo "Phase 2.5: Calculating sentence-level coverage...\n";
    }
    
    $sentenceCoverageStats = [
        '0_percent' => 0,
        '1_to_24_percent' => 0,
        '25_to_49_percent' => 0,
        '50_to_74_percent' => 0,
        '75_to_99_percent' => 0,
        '100_percent' => 0
    ];
    
    $sentencesWithFullCoverage = 0;
    $sentencesWithPartialCoverage = 0;
    $processedSentences = 0;
    $sentencesWithFullCoverageDetails = [];
    
    foreach ($sentenceGlossMap as $sentenceId => $sentenceData) {
        $processedSentences++;
        $totalGlosses = count($sentenceData['glosses']);
        $coveredGlosses = 0;
        
        // Count how many glosses in this sentence have videos
        foreach ($sentenceData['glosses'] as $gloss) {
            if (isset($glossVideoAvailability[$gloss]) && $glossVideoAvailability[$gloss]) {
                $coveredGlosses++;
            }
        }
        
        // Calculate coverage percentage for this sentence
        $sentenceCoveragePercentage = $totalGlosses > 0 ? ($coveredGlosses / $totalGlosses) * 100 : 0;
        
        // Categorize sentence by coverage level
        if ($sentenceCoveragePercentage == 0) {
            $sentenceCoverageStats['0_percent']++;
        } elseif ($sentenceCoveragePercentage < 25) {
            $sentenceCoverageStats['1_to_24_percent']++;
            $sentencesWithPartialCoverage++;
        } elseif ($sentenceCoveragePercentage < 50) {
            $sentenceCoverageStats['25_to_49_percent']++;
            $sentencesWithPartialCoverage++;
        } elseif ($sentenceCoveragePercentage < 75) {
            $sentenceCoverageStats['50_to_74_percent']++;
            $sentencesWithPartialCoverage++;
        } elseif ($sentenceCoveragePercentage < 100) {
            $sentenceCoverageStats['75_to_99_percent']++;
            $sentencesWithPartialCoverage++;
        } else {
            $sentenceCoverageStats['100_percent']++;
            $sentencesWithFullCoverage++;
            
            // Collect details about sentences with full coverage
            $sentencesWithFullCoverageDetails[] = [
                'id' => $sentenceId,
                'zinString' => $sentenceData['zinString'],
                'thema' => $sentenceData['thema'],
                'glosses' => $sentenceData['glosses'],
                'total_glosses' => $totalGlosses,
                'covered_glosses' => $coveredGlosses
            ];
        }
        
        if ($verbose && $processedSentences % 50 == 0) {
            echo "Analyzed coverage for $processedSentences sentences...\n";
        }
    }
    
    if ($verbose) {
        echo "Sentence coverage analysis complete!\n";
        echo "Sentences with 100% coverage: $sentencesWithFullCoverage/" . count($sentenceGlossMap) . "\n";
    }
    
    // Phase 3: Calculate final statistics
    $coveragePercentage = $totalUniqueGlosses > 0 ? ($glossesWithVideos / $totalUniqueGlosses) * 100 : 0;
    $formDataCoverage = $totalUniqueGlosses > 0 ? (($formDataCount + $bothSourcesCount) / $totalUniqueGlosses) * 100 : 0;
    $nmmFallbackCoverage = $totalUniqueGlosses > 0 ? (($nmmDataCount + $bothSourcesCount) / $totalUniqueGlosses) * 100 : 0;
    $sentenceCoveragePercentage = count($sentenceGlossMap) > 0 ? ($sentencesWithFullCoverage / count($sentenceGlossMap)) * 100 : 0;
    
    // Update results
    $results['summary']['glosses_with_videos'] = $glossesWithVideos;
    $results['summary']['coverage_percentage'] = round($coveragePercentage, 2);
    $results['summary']['form_data_coverage'] = round($formDataCoverage, 2);
    $results['summary']['nmm_fallback_coverage'] = round($nmmFallbackCoverage, 2);
    $results['summary']['sentences_with_full_coverage'] = $sentencesWithFullCoverage;
    $results['summary']['sentence_coverage_percentage'] = round($sentenceCoveragePercentage, 2);
    
    $results['detailed_stats']['by_source']['form_data_only'] = $formDataCount;
    $results['detailed_stats']['by_source']['nmm_data_only'] = $nmmDataCount;
    $results['detailed_stats']['by_source']['both_sources'] = $bothSourcesCount;
    $results['detailed_stats']['by_source']['no_videos'] = $noVideosCount;
    
    $results['detailed_stats']['by_sentence_coverage'] = $sentenceCoverageStats;
    $results['detailed_stats']['sentences_with_partial_coverage'] = $sentencesWithPartialCoverage;
    
    $results['missing_glosses'] = $missingGlosses;
    $results['sentences_with_full_coverage_details'] = $sentencesWithFullCoverageDetails;
    
    if ($validateUrls) {
        $results['detailed_stats']['url_validation']['tested'] = $urlValidationStats['tested'];
        $results['detailed_stats']['url_validation']['failed'] = $urlValidationStats['failed'];
        $results['detailed_stats']['url_validation']['success_rate'] = 
            $urlValidationStats['tested'] > 0 ? 
            round((($urlValidationStats['tested'] - $urlValidationStats['failed']) / $urlValidationStats['tested']) * 100, 2) : 0;
    }
    
    if ($verbose) {
        echo "Analysis complete!\n";
        echo "Gloss Coverage: {$results['summary']['coverage_percentage']}% ({$glossesWithVideos}/{$totalUniqueGlosses})\n";
        echo "Sentence Coverage: {$results['summary']['sentence_coverage_percentage']}% ({$sentencesWithFullCoverage}/" . count($sentenceGlossMap) . ")\n";
    }
    
} catch (Exception $e) {
    $results['success'] = false;
    $results['error'] = $e->getMessage();
    if ($verbose) {
        $results['debug']['exception_trace'] = $e->getTraceAsString();
    }
}

// Calculate execution time
$executionTime = microtime(true) - $startTime;
$results['execution_time'] = round($executionTime, 2);

// Close database connection
$conn->close();

// Output results as JSON
if (!$verbose) {
    header('Content-Type: application/json');
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

/**
 * Test if a URL returns HTTP 200
 */
function testUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode == 200;
}

/**
 * Get form_data ID by glos value with exact matching
 */
function getFormDataIdByGlos($conn, $glos, $exactMatch = true) {
    if ($exactMatch) {
        $sql = "SELECT id FROM form_data WHERE glos = ? AND extern = '1' AND glosZichtbaar = '0' LIMIT 1";
        $searchValue = $glos;
    } else {
        $sql = "SELECT id FROM form_data WHERE glos LIKE ? AND extern = '1' AND glosZichtbaar = '0' LIMIT 1";
        $searchValue = $glos . '%';
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("s", $searchValue);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $stmt->close();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    $row = $result->fetch_assoc();
    return $row['id'];
}

/**
 * Get matched_transcriptions data by form_data ID
 */
function getMatchedTranscriptionsByFormId($conn, $formId) {
    $sql = "SELECT id, l_file, m_file, r_file, l_transcription, m_transcription, r_transcription, 
                   added, app_ready, zOg
            FROM matched_transcriptions 
            WHERE (l_transcription = ? OR m_transcription = ? OR r_transcription = ?) 
              AND zOg IN ('glos', 'extern') 
              AND app_ready = 1
            ORDER BY id DESC
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("iii", $formId, $formId, $formId);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $stmt->close();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}