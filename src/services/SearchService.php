<?php
/**
 * Service for handling search operations
 */
class SearchService
{
    private $conn;
    private $response;
    private $videoService; // Property for VideoService
    private $mocapService; // New property for MocapService
    private $nmmService;   // New property for NmmService
    private $formService;  // New property for FormService
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Optional VideoService instance
     * @param MocapService $mocapService Optional MocapService instance
     * @param NmmService $nmmService Optional NmmService instance
     * @param FormService $formService Optional FormService instance
     */
    public function __construct($conn, &$response, $videoService = null, $mocapService = null, $nmmService = null, $formService = null)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
        $this->mocapService = $mocapService;
        $this->nmmService = $nmmService; // Assign NmmService
        $this->formService = $formService; // Assign FormService
    }
    
    /**
     * Search content based on a query
     * 
     * @param string $searchQuery Query string to search for
     * @param int $offset Pagination offset
     * @return array Search results
     */
    public function search($searchQuery, $offset = 0)
    {
        $limit = 10;
        $this->response['debug']['received_query'] = $this->sanitizeOutput($searchQuery);
        
        // Handle empty searches - return empty result structure
        if (empty($searchQuery)) {
            return [
                // Ensure all expected keys are present even if empty
                'words' => [],
                'sentences' => [],
                'glosses' => [],
                'synonyms' => []
            ];
        }
        
        // Sanitize input to prevent SQL injection
        $searchQuerySanitized = $this->sanitizeInput($searchQuery); // Use a different var for sanitized query if original is needed elsewhere
        
        // Get raw search results
        $formResults = $this->searchForms($searchQuerySanitized, $offset, $limit);
        // $sbResults = $this->searchSignbank($searchQuery, $offset, $limit); // Temporarily disabled Signbank search
        
        // Get FormService search results (priority over NMM)
        $formServiceResults = [];
        if ($this->formService) {
            $formServiceResults = $this->formService->searchFormsByGlos($searchQuery);
            $this->response['debug']['form_service_search_count'] = count($formServiceResults);
        }
        
        $nmmResults = [];
        if ($this->nmmService) {
            $nmmResults = $this->nmmService->searchNmmByGlos($searchQuery); // Use original $searchQuery for NMM service if it does its own sanitization or needs original form
            // Add the NMM query to debug, using a representation of the query
            $searchPatternNmm = $searchQuery . '%'; // Pattern used in NmmService
            $this->response['debug']['nmm_data_query'] = "SELECT id, name, description, type, signbank_id, glos FROM nmm_data WHERE glos LIKE '" . $this->conn->real_escape_string($searchPatternNmm) . "'";
        }
        
        // Merge forms, (sb_records), nmm_records, and form_service_results into glosses with duplicate removal and priority
        $glosses = $this->mergeGlosses($formResults, [], $nmmResults, $formServiceResults); // Pass empty array for sbRecords, and new formServiceResults
        
        $results = [
            'words' => $this->searchWords($searchQuerySanitized),
            'sentences' => $this->searchSentences($searchQuerySanitized, $offset, $limit),
            'glosses' => $glosses, // New combined glosses field
            'synonyms' => $this->searchSynonyms($searchQuerySanitized, $offset, $limit)
        ];
        
        return $this->sanitizeResults($results);
    }
    
    /**
     * Merge forms, SignBank records, NMM records, and FormService results into a single glosses array with duplicates removed
     * Priority: FormService > NMM when glos values overlap
     * 
     * @param array $forms Form data results (from searchForms)
     * @param array $sbRecords SignBank records results (will be empty if Signbank search is disabled)
     * @param array $nmmRecords NMM records results
     * @param array $formServiceResults FormService search results (highest priority)
     * @return array Merged glosses without duplicates
     */
    private function mergeGlosses($forms, $sbRecords, $nmmRecords = [], $formServiceResults = [])
    {
        $glosses = [];
        $processedGlosses = []; // Track processed glosses (primary display string) to avoid duplicates
        $formServiceGlosValues = []; // Track glos values from FormService for priority system
        $formServiceIds = []; // Track IDs from FormService to avoid duplicates

        // Helper function to check for forbidden pattern (e.g., "-B" through "-Z")
        $checkForbiddenPattern = function($text) {
            if (!is_string($text) || empty($text)) {
                return false;
            }
            // Matches a hyphen followed by an uppercase letter from B to Z
            return preg_match('/-[B-Z]/', $text) === 1;
        };

        // Process FormService results FIRST (highest priority)
        foreach ($formServiceResults as $formServiceItem) {
            $formServiceGlosValue = $formServiceItem['glos'] ?? null;

            if ($formServiceGlosValue && $checkForbiddenPattern($formServiceGlosValue)) {
                $this->response['debug']['filter_skipped_formservice_by_glos_pattern'][] = ['id' => $formServiceItem['id'] ?? 'unknown', 'glos_field' => $formServiceGlosValue];
                continue;
            }

            $glosDisplayFormService = $formServiceGlosValue ?? '';
            $processedFormServiceGlosValue = $this->processSenseValue($formServiceGlosValue);

            if (!empty($glosDisplayFormService) && !in_array($glosDisplayFormService, $processedGlosses)) {
                $processedGlosses[] = $glosDisplayFormService;
                $formServiceGlosValues[] = $glosDisplayFormService; // Track for priority
                $formServiceIds[] = $formServiceItem['id']; // Track ID for deduplication

                // Process senses array from FormService
                $sensesArray = [];
                if (!empty($formServiceItem['senses'])) {
                    $decodedSenses = is_string($formServiceItem['senses']) ? json_decode($formServiceItem['senses'], true) : $formServiceItem['senses'];
                    if (is_array($decodedSenses)) {
                        $sensesArray = $this->processSensesArray($decodedSenses);
                    } elseif (is_string($decodedSenses)) {
                        $sensesArray = [$this->processSenseValue($decodedSenses)];
                    }
                }

                $mappedFormServiceItem = [
                    "id" => $formServiceItem['id'],
                    "senses" => !empty($sensesArray) ? $sensesArray : [$processedFormServiceGlosValue],
                    "signbank" => $formServiceItem['signbank'] ?? null,
                    "thema" => $formServiceItem['thema'] ?? "Unknown",
                    "type" => "glos",
                    "source" => "form_service", // Mark as FormService source
                    "videos" => $formServiceItem['videos'] ?? ['videoLeft' => null, 'videoCenter' => null, 'videoRight' => null],
                    "mocap" => false,
                    "nmm_data" => $formServiceItem['nmm_data'] ?? []
                ];
                $glosses[] = $mappedFormServiceItem;
                $this->response['debug']['added_formservice_glos'][] = $glosDisplayFormService;
            }
        }

        // Process form_data entries
        foreach ($forms as $form) {
            $formShouldBeSkipped = false;
            
            // PRIORITY SYSTEM: Skip if FormService already has this ID
            if (isset($form['id']) && in_array($form['id'], $formServiceIds)) {
                $this->response['debug']['skipped_form_for_formservice_id_priority'][] = [
                    'form_id' => $form['id'],
                    'reason' => 'FormService has same ID with priority'
                ];
                continue; // Skip this form_data entry as FormService has priority
            }

            // Check 'glos' field from form_data (actual column value)
            $formGlosField = $form['glos'] ?? null; 
            if ($formGlosField && $checkForbiddenPattern($formGlosField)) {
                $formShouldBeSkipped = true;
                $this->response['debug']['filter_skipped_form_by_glos_pattern'][] = ['id' => $form['id'] ?? 'unknown', 'glos_field' => $formGlosField];
            }

            // Check 'senses' field (array of strings) if not already skipped
            if (!$formShouldBeSkipped && isset($form['senses']) && is_array($form['senses'])) {
                foreach ($form['senses'] as $senseItem) {
                    if ($checkForbiddenPattern($senseItem)) {
                        $formShouldBeSkipped = true;
                        $this->response['debug']['filter_skipped_form_by_senses_pattern'][] = ['id' => $form['id'] ?? 'unknown', 'sense_item' => $senseItem];
                        break; 
                    }
                }
            }
            
            if ($formShouldBeSkipped) {
                continue; // Skip this form
            }

            // Original logic for $glosDisplay for duplicate checking (based on first sense)
            $glosDisplay = '';
            if (isset($form['senses']) && is_array($form['senses']) && !empty($form['senses'])) {
                $glosDisplay = $form['senses'][0] ?? '';
            } elseif (isset($form['senses']) && is_string($form['senses'])) {
                // Handle old format if still encountered (less likely with CAST AS JSON)
                $decoded = json_decode($form['senses'], true);
                $glosDisplay = is_array($decoded) && !empty($decoded) ? $decoded[0] : $form['senses'];
            }

            if (!empty($glosDisplay) && !in_array($glosDisplay, $processedGlosses)) {
                $processedGlosses[] = $glosDisplay;
                
                // Process senses array to remove -A to -Z patterns and capitalize properly
                if (isset($form['senses']) && is_array($form['senses'])) {
                    $form['senses'] = $this->processSensesArray($form['senses']);
                }
                
                if ($this->mocapService !== null) {
                    $form['mocap'] = $this->mocapService->hasMocapData($glosDisplay); 
                } else {
                    $form['mocap'] = false;
                }
                $glosses[] = $form;
            }
            // Note: Original code did not have an else-if for empty glosDisplay to add by ID,
            // so items with empty glosDisplay (after filtering) won't be added.
        }
        
        // Process sb_records (this part is effectively disabled if $sbRecords is always an empty array from search method)
        foreach ($sbRecords as $record) {
            $glosDisplay = $record['annotation_id_gloss_dutch'] ?? '';
            
            if (empty($glosDisplay) && isset($record['id'])) {
                $glosDisplay = 'sb_' . $record['id']; // Fallback unique identifier
            }
            
            if (!empty($glosDisplay) && !in_array($glosDisplay, $processedGlosses)) {
                $processedGlosses[] = $glosDisplay;
                
                if ($this->mocapService !== null && !isset($record['mocap'])) {
                    // Example: $record['mocap'] = $this->mocapService->hasMocapData($glosDisplay, 'signbank');
                } else if (!isset($record['mocap'])) {
                    $record['mocap'] = false;
                }
                $glosses[] = $record;
            }
        }

        // Process NMM records (lower priority than FormService)
        foreach ($nmmRecords as $nmmItem) {
            $nmmGlosValue = $nmmItem['glos'] ?? null; // Actual 'glos' field from nmm_data

            if ($nmmGlosValue && $checkForbiddenPattern($nmmGlosValue)) {
                $this->response['debug']['filter_skipped_nmm_by_glos_pattern'][] = ['id' => $nmmItem['id'] ?? 'unknown', 'glos_field' => $nmmGlosValue];
                continue; // Skip this NMM item
            }

            $glosDisplayNMM = $nmmGlosValue ?? ''; // Use NMM 'glos' for duplicate check and as primary sense

            // PRIORITY SYSTEM: Skip if FormService already has this glos value
            if (!empty($glosDisplayNMM) && in_array($glosDisplayNMM, $formServiceGlosValues)) {
                $this->response['debug']['skipped_nmm_for_formservice_priority'][] = [
                    'nmm_id' => $nmmItem['id'] ?? 'unknown', 
                    'glos_value' => $glosDisplayNMM,
                    'reason' => 'FormService takes priority'
                ];
                continue; // Skip this NMM item as FormService has priority
            }

            // Process the NMM glos value using the helper function
            $processedNmmGlosValue = $this->processSenseValue($nmmGlosValue);

            if (!empty($glosDisplayNMM) && !in_array($glosDisplayNMM, $processedGlosses)) {
                $processedGlosses[] = $glosDisplayNMM;
                
                $mappedNmmItem = [
                    "id" => $nmmItem['id'],
                    "senses" => $processedNmmGlosValue ? [$processedNmmGlosValue] : [], // Use processed NMM 'glos' field as the primary sense in an array
                    "signbank" => $nmmItem['signbank_id'] ?? null,
                    "thema" => $nmmItem['thema'] ?? "Unknown", 
                    "type" => "nmm", // Use nmm type for nmm_data source
                    "source" => "nmm_data",
                    "videos" => $nmmItem['videos'] ?? ['videoLeft' => null, 'videoCenter' => null, 'videoRight' => null],
                    "mocap" => false, // NMM data does not have mocap information
                    "nmm_type" => $nmmItem['type'] ?? '', 
                    "zelfopname" => $nmmItem['zelfopname'] ?? null
                ];
                $glosses[] = $mappedNmmItem;
                $this->response['debug']['added_nmm_glos'][] = $glosDisplayNMM;
            }
            // Note: Original code did not have an else-if for empty glosDisplayNMM to add by ID.
        }
        
        return $glosses;
    }
    
    /**
     * Process senses values by removing -A to -Z patterns and capitalizing properly
     *
     * @param string $senseValue The sense value to process
     * @return string Processed sense value
     */
    private function processSenseValue($senseValue)
    {
        if (!is_string($senseValue) || empty($senseValue)) {
            return $senseValue;
        }
        
        // Check if senseValue ends with hyphen followed by a single uppercase letter (A-Z)
        // If that's the case then remove it
        // Examples: AAP-A -> AAP, PANNENKOEK-D -> PANNENKOEK
        // But keep: PANNENKOEK-BAKKEN (multiple letters after hyphen)
        if (preg_match('/-[A-Z]$/', $senseValue)) {
            $senseValue = preg_replace('/-[A-Z]$/', '', $senseValue);
        }
        
        // Lowercase the string except the first letter
        return ucfirst(strtolower($senseValue));
    }

    /**
     * Process an array of senses values
     *
     * @param array $sensesArray The array of sense values to process
     * @return array Processed senses array
     */
    private function processSensesArray($sensesArray)
    {
        if (!is_array($sensesArray)) {
            return $sensesArray;
        }
        
        return array_map([$this, 'processSenseValue'], $sensesArray);
    }

    /**
     * Sanitize user input for database queries
     *
     * @param string $input The input to sanitize
     * @return string Sanitized input
     */
    private function sanitizeInput($input) 
    {
        if (!is_string($input)) {
            return '';
        }
        
        // Remove common SQL injection patterns
        $patterns = [
            '/\bUNION\b/i',
            '/\bSELECT\b/i',
            '/\bINSERT\b/i',
            '/\bUPDATE\b/i',
            '/\bDELETE\b/i',
            '/\bDROP\b/i',
            '/\bTABLE\b/i',
            '/\bFROM\b/i',
            '/\bWHERE\b/i',
            '/--/',
            '/;/',
            '/\/\*|\*\//'  // SQL comments
        ];
        
        $input = preg_replace($patterns, '', $input);
        
        // Basic sanitization
        $input = trim($input);
        $input = stripslashes($input);
        
        return $input;
    }
    
    /**
     * Sanitize output to prevent XSS
     *
     * @param mixed $output The output to sanitize
     * @return mixed Sanitized output
     */
    private function sanitizeOutput($output) 
    {
        if (is_array($output)) {
            $sanitized = [];
            foreach ($output as $key => $value) {
                $sanitized[$key] = $this->sanitizeOutput($value);
            }
            return $sanitized;
        } elseif (is_string($output)) {
            // More aggressive encoding to ensure tags are properly escaped
            $output = htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Double-check that critical tags are definitely encoded
            $output = str_replace(
                ['<script>', '</script>', '<img', 'onerror=', 'javascript:', 'alert('],
                ['&lt;script&gt;', '&lt;/script&gt;', '&lt;img', 'onerror&#x3D;', 'javascript&#x3A;', 'alert&#x28;'],
                $output
            );
            
            return $output;
        } else {
            return $output;
        }
    }
    
    /**
     * Search for words matching the query
     * 
     * @param string $searchQuery Query string
     * @return array Matching words
     */
    public function searchWords($searchQuery)  // Changed to public for testing
    {
        $wordMatches = [];
        
        if (empty($searchQuery)) {
            return $wordMatches;
        }
        
        // Step 1: Find exact word match in hh_words
        $stmt = $this->conn->prepare("SELECT id, word, lemma FROM hh_words WHERE word = ? LIMIT 1");
        if (!$stmt) {
            $this->response['debug']['prepare_error'] = $this->conn->error;
            return $wordMatches;
        }
        
        $stmt->bind_param("s", $searchQuery);
        if (!$stmt->execute()) {
            $this->response['debug']['execute_error'] = $stmt->error;
            $stmt->close();
            return $wordMatches;
        }
        
        $wordResult = $stmt->get_result();
        $stmt->close();
        
        $this->response['debug']['word_result_count'] = $wordResult->num_rows;
        
        if ($wordResult->num_rows > 0) {
            while ($word = $wordResult->fetch_assoc()) {
                $wordMatches[] = $word;
                
                // Check for senses matches
                if (!empty($word['senses'])) {
                    $this->response['debug']['senses_data'] = $word['senses'];
                    $senses = json_decode($word['senses'], true);
                    if (is_array($senses)) {
                        foreach ($senses as $sense) {
                            if (isset($sense['lemma']) && $sense['lemma'] === $word['lemma']) {
                                $this->response['data']['senses'][] = $sense;
                            }
                        }
                    } else {
                        $this->response['debug']['senses_json_error'] = json_last_error_msg();
                    }
                }
                
                // Ensure senses is always an array and process it
                if (isset($word['senses']) && is_string($word['senses'])) {
                    $word['senses'] = json_decode($word['senses'], true) ?? [];
                }
                
                // Process the senses array to remove -A to -Z patterns and capitalize properly
                if (isset($word['senses']) && is_array($word['senses'])) {
                    $word['senses'] = $this->processSensesArray($word['senses']);
                }
            }
        } else {
            // If no word matches are found, use the search query itself as a lemma
            $this->response['debug']['using_query_as_lemma'] = true;
            
            // Add a synthetic word match using the search query
            $wordMatches[] = [
                'id' => null,
                'word' => $searchQuery,
                'lemma' => $searchQuery
            ];
        }
        
        return $wordMatches;
    }
    
    /**
     * Get lemmas for the search query
     * 
     * @param string $searchQuery Query string
     * @return array Lemmas
     */
    private function getLemmas($searchQuery)
    {
        $words = $this->searchWords($searchQuery);
        $lemmas = array_unique(array_column($words, 'lemma'));
        
        // If no lemmas found, use search query
        if (empty($lemmas)) {
            $lemmas[] = $searchQuery;
        }
        
        $this->response['debug']['lemmas_found'] = $lemmas;
        return $lemmas;
    }
    
    /**
     * Search sentences based on lemmas
     * 
     * @param string $searchQuery Query string
     * @param int $offset Pagination offset
     * @param int $limit Results limit
     * @return array Matching sentences
     */
    private function searchSentences($searchQuery, $offset, $limit)
    {
        $sentenceMatches = [];
        $lemmas = $this->getLemmas($searchQuery);
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // Updated query to include thema field from sentences table
                $sql = "SELECT ID, zinString, IFNULL(thema, 'Unknown') as thema FROM sentences WHERE JSON_CONTAINS(lemmaList, ?) OR JSON_CONTAINS(lemmaList, ?) LIMIT ?, ?";
                $this->response['debug']['sentences_query'] = $sql;
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    $this->response['debug']['sentences_prepare_error'] = $this->conn->error;
                    continue; // Continue with the next lemma instead of failing
                }
                
                // Prepare for both formats the lemma might be stored in JSON
                $lemmaJson = json_encode($lemma);
                $lemmaQuotedJson = json_encode("\"$lemma\"");
                $this->response['debug']['lemma_json'] = $lemmaJson;
                $this->response['debug']['lemma_quoted_json'] = $lemmaQuotedJson;
                
                // Bind two strings and two integers (offset, limit)
                $stmt->bind_param("ssii", $lemmaJson, $lemmaQuotedJson, $offset, $limit);
                
                if (!$stmt->execute()) {
                    $this->response['debug']['sentences_execute_error'] = $stmt->error;
                    $stmt->close();
                    continue;
                }
                
                $sentenceResult = $stmt->get_result();
                $stmt->close();
                
                $this->response['debug']['sentences_found_for_lemma_' . $lemma] = $sentenceResult->num_rows;
                
                while ($sentence = $sentenceResult->fetch_assoc()) {
                    // Check if we already have this sentence using in_array
                    $exists = in_array($sentence['ID'], array_column($sentenceMatches, 'id')); // Changed 'ID' to 'id' for the check key
                    
                    if (!$exists) {
                        // First check if there's at least one row in matched_transcriptions with matching criteria and app_ready = 1
                        $hasMatchedTranscription = false;
                        $checkStmt = $this->conn->prepare("SELECT 1 FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'zin' AND added = '1' AND app_ready = 1 LIMIT 1");
                        if ($checkStmt) {
                            $checkStmt->bind_param("i", $sentence['ID']);
                            if ($checkStmt->execute()) {
                                $checkResult = $checkStmt->get_result();
                                $hasMatchedTranscription = $checkResult->num_rows > 0;
                            }
                            $checkStmt->close();
                        }
                        
                        // Only add the sentence if it has a matching transcription
                        if ($hasMatchedTranscription) {
                            // Include thema in the basic sentence info
                            $thema = $sentence['thema'] ?? "Unknown"; // Changed from theme to thema
                            $thema = strtolower($thema);
                            $thema = ucfirst($thema);

                            $sentenceMatches[] = [
                                "id" => $sentence['ID'] ?? null, // Changed "ID" to "id"
                                "zinstring" => $sentence['zinString'] ?? "", // Changed "zinString" to "zinstring"
                                "thema" => $thema, // Changed from theme to thema
                                "type" => "zin" // Add type for frontend to know which endpoint to call
                            ];
                        }
                    }
                }
            }
        }
        
        $this->response['debug']['sentence_matches_found'] = count($sentenceMatches);
        return array_slice($sentenceMatches, 0, $limit);
    }
    
    /**
     * Search form data based on lemmas
     * 
     * @param string $searchQuery Query string
     * @param int $offset Pagination offset
     * @param int $limit Results limit
     * @return array Matching forms
     */
    private function searchForms($searchQuery, $offset, $limit)
    {
        $formMatches = [];
        // Remove lemma generation, use searchQuery directly for LIKE search
        // $lemmas = $this->getLemmas($searchQuery);

        if (!empty($searchQuery)) { // Proceed if searchQuery is not empty
            // Prepare the search pattern for LIKE query
            $searchPattern = $searchQuery . '%';

            // Query form_data using LIKE on the 'glos' field
            // Select 'glos' field as well, and 'senses' for consistent output structure.
            // Keep existing filters: extern = '1' AND glosZichtbaar = '0'
            // Note: Column name varies between databases - 'theme' in test, 'thema' in production
            $sql = "SELECT id, CAST(IF(senses = '', '[]', senses) AS JSON) AS senses, signbank, 
                           IFNULL(thema, 'Unknown') as thema, glos 
                    FROM form_data 
                    WHERE glos LIKE ? AND extern = '1' AND glosZichtbaar = '0' 
                    LIMIT ?, ?";
            
            $this->response['debug']['form_data_query'] = $sql;
            $this->response['debug']['form_data_search_pattern'] = $searchPattern;

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->response['debug']['form_data_prepare_error'] = $this->conn->error;
                // Return empty array or handle error as appropriate
                return $formMatches; 
            }

            // Bind searchPattern (string), offset (int), limit (int)
            $stmt->bind_param("sii", $searchPattern, $offset, $limit);

            if (!$stmt->execute()) {
                $this->response['debug']['form_data_execute_error'] = $stmt->error;
                $stmt->close();
                // Return empty array or handle error
                return $formMatches; 
            }

            $formResult = $stmt->get_result();
            $stmt->close();

            $this->response['debug']['forms_found_for_pattern_' . $searchPattern] = $formResult->num_rows;

            while ($form = $formResult->fetch_assoc()) {
                // Check if we already have this form using in_array
                $exists = in_array($form['id'], array_column($formMatches, 'id'));

                if (!$exists) {
                    // Check if there are any videos associated with this form and app_ready = 1
                    $hasAppReadyVideos = false;
                    
                    // Check matched_transcriptions for app_ready status
                    $checkStmt = $this->conn->prepare("SELECT 1 FROM matched_transcriptions WHERE m_transcription = ? AND zOg IN ('glos', 'extern', 'labels') AND app_ready = 1 AND added = '1' LIMIT 1");
                    if ($checkStmt) {
                        $checkStmt->bind_param("i", $form['id']);
                        if ($checkStmt->execute()) {
                            $checkResult = $checkStmt->get_result();
                            $hasAppReadyVideos = $checkResult->num_rows > 0;
                        }
                        $checkStmt->close();
                    }

                    if ($hasAppReadyVideos) {
                        // Senses processing (already handles JSON string to array)
                        $sensesArray = [];
                        if (!empty($form['senses'])) { // Senses might be JSON string or already array from CAST
                            $decodedSenses = is_string($form['senses']) ? json_decode($form['senses'], true) : $form['senses'];
                            if (is_array($decodedSenses)) {
                                $sensesArray = $this->processSensesArray($decodedSenses); // Process the senses array
                            } elseif (is_string($decodedSenses)) { // Should not happen with CAST AS JSON but good fallback
                                $sensesArray = [$this->processSenseValue($decodedSenses)]; // Process single sense value
                            }
                        }
                        
                        // Thema processing
                        $thema = $form['thema'] ?? "Unknown"; // Changed from theme to thema
                        $thema = strtolower($thema);
                        $thema = ucfirst($thema);

                        $formMatches[] = [
                            "id" => $form['id'],
                            // Use the 'glos' field for the primary display if 'senses' is not suitable or empty
                            // For now, keep 'senses' as it was, assuming it's still the desired field for display
                            "senses" => $sensesArray, 
                            "signbank" => $form['signbank'] ?? "",
                            "thema" => $thema,
                            "type" => "glos",
                            "source" => "form_data",
                            // Optionally include the 'glos' field if it's different from 'senses' and needed by frontend
                            // "glos_text" => $form['glos'] 
                        ];
                    }
                }
            }
        }

        $this->response['debug']['form_matches_found'] = count($formMatches);
        return array_slice($formMatches, 0, $limit);
    }
    
    /**
     * Search signbank records based on lemmas
     * 
     * @param string $searchQuery Query string
     * @param int $offset Pagination offset
     * @param int $limit Results limit
     * @return array Matching signbank records
     */
    private function searchSignbank($searchQuery, $offset, $limit)
    {
        // Temporarily disabled as per request
        $this->response['debug']['searchSignbank_disabled'] = true;
        return []; // Return empty array as Signbank search is disabled
        
        /* Original code below, commented out
        $sbRecordMatches = [];
        $lemmas = $this->getLemmas($searchQuery);
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // First query: get potential matches based on LIKE search
                $sql = "SELECT id, senses_dutch, annotation_id_gloss_dutch FROM sb_records WHERE ";
                $searchTerms = [];
                
                // Split lemma into words for more flexible matching
                $lemmaWords = explode(' ', $lemma);
                foreach ($lemmaWords as $word) {
                    if (strlen($word) >= 3) { // Only search for words with 3+ characters
                        $searchTerms[] = "senses_dutch LIKE ?";
                    }
                }
                
                if (empty($searchTerms)) {
                    $searchTerms[] = "senses_dutch LIKE ?";
                    $params = ["%$lemma%"];
                } else {
                    $params = [];
                    foreach ($lemmaWords as $word) {
                        if (strlen($word) >= 3) {
                            $params[] = "%$word%";
                        }
                    }
                }
                
                $sql .= implode(' OR ', $searchTerms);
                $sql .= " LIMIT ?, ?";
                
                $this->response['debug']['sb_records_query'] = $sql;
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    $this->response['debug']['sb_records_prepare_error'] = $this->conn->error;
                    continue;
                }
                
                // Create the right number of bind parameters
                $types = str_repeat("s", count($params)) . "ii";
                
                // Create references to parameters for binding
                $bindParams = [];
                $bindParams[] = $types; // First parameter is types string
                
                // Add search parameters (passed by reference)
                foreach ($params as &$param) {
                    $bindParams[] = &$param;
                }
                
                // Add pagination parameters (passed by reference)
                $offsetParam = $offset;
                $limitParam = $limit;
                $bindParams[] = &$offsetParam;
                $bindParams[] = &$limitParam;
                
                // Bind all parameters using call_user_func_array
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                
                if (!$stmt->execute()) {
                    $this->response['debug']['sb_records_execute_error'] = $stmt->error;
                    $stmt->close();
                    continue;
                }
                
                $sbResult = $stmt->get_result();
                $stmt->close();
                
                $this->response['debug']['sb_records_found_for_lemma_' . $this->sanitizeOutput($lemma)] = $sbResult->num_rows;
                
                // Process each potential match to verify exact word matches in comma-separated values
                while ($record = $sbResult->fetch_assoc()) {
                    // Check if we already have this record using in_array
                    $exists = in_array($record['id'], array_column($sbRecordMatches, 'id'));
                    
                    if (!$exists) {
                        // Decode senses_dutch field and check for exact matches
                        $exactMatch = false;
                        $wholePhraseMatch = false;
                        $matchedPhrases = [];
                        $exactMatchPhrases = [];
                        
                        if (!empty($record['senses_dutch'])) {
                            $decodedSenses = $this->decodeSensesDutch($record['senses_dutch']);
                            
                            // Check for exact word matches in phrases
                            if (isset($decodedSenses['formatted']) && is_array($decodedSenses['formatted'])) {
                                foreach ($decodedSenses['formatted'] as $key => $data) {
                                    if (isset($data['phrases']) && is_array($data['phrases'])) {
                                        foreach ($data['phrases'] as $phrase) {
                                            // Clean the phrase for comparison
                                            $cleanPhrase = trim(preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', '', $phrase));
                                            $cleanLemma = trim(preg_replace('/[^a-zA-ZÀ-ÿ0-9\s]/', '', $lemma));
                                            
                                            // First check: Is the entire phrase an exact match for the search term?
                                            if (strcasecmp($cleanPhrase, $cleanLemma) === 0) {
                                                $wholePhraseMatch = true;
                                                $exactMatch = true;
                                                $exactMatchPhrases[] = $phrase;
                                                break 2; // Break out of both loops - whole phrase match is the best match
                                            }
                                            
                                            // Second check: Does the phrase contain the exact word?
                                            $wordsInPhrase = preg_split('/\s+/', $cleanPhrase);
                                            foreach ($wordsInPhrase as $word) {
                                                if (strcasecmp($word, $cleanLemma) === 0) {
                                                    $exactMatch = true;
                                                    $matchedPhrases[] = $phrase;
                                                    break 2; // Break out of both loops once match is found
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Add the record if it's an exact match
                        if ($exactMatch) {
                            // Prioritize whole phrase matches
                            $displayPhrases = $wholePhraseMatch ? $exactMatchPhrases : $matchedPhrases;
                            
                            // Process the display phrases to remove -A to -Z patterns and capitalize properly
                            $displayPhrases = $this->processSensesArray($displayPhrases);
                            
                            // Add record with all necessary fields for proper merging
                            $sbRecordMatches[] = [
                                "id" => $record['id'],
                                "annotation_id_gloss_dutch" => $record['annotation_id_gloss_dutch'] ?? "",
                                "senses" => $displayPhrases,
                                "type" => "sb", // Add type for frontend to know which endpoint to call
                                "source" => "sb_records"
                            ];
                        }
                    }
                }
            }
        }
        
        // Sort results to prioritize whole phrase matches
        usort($sbRecordMatches, function($a, $b) {
            // First prioritize whole phrase matches
            $aWholeMatch = !empty($a['whole_phrase_match']);
            $bWholeMatch = !empty($b['whole_phrase_match']);
            
            if ($aWholeMatch && !$bWholeMatch) {
                return -1; // a comes first
            } elseif (!$aWholeMatch && $bWholeMatch) {
                return 1; // b comes first
            }
            
            // If equal in whole phrase status, maintain original order
            return 0;
        });
        
        $this->response['debug']['sb_record_matches_found'] = count($sbRecordMatches);
        return array_slice($sbRecordMatches, 0, $limit);
        */
    }
    
    /**
     * Decode HTML entities and extract clean phrases from senses_dutch field
     * 
     * @param string $sensesDutch The encoded senses_dutch JSON string
     * @return array Decoded senses with structured data
     */
    private function decodeSensesDutch($sensesDutch)
    {
        if (empty($sensesDutch)) {
            return [];
        }
        
        try {
            // First decode HTML entities
            $decodedSenses = html_entity_decode($sensesDutch, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Handle double-encoded quotes
            $decodedSenses = str_replace('&quot;', '"', $decodedSenses);
            
            // Parse JSON
            $sensesData = json_decode($decodedSenses, true);
            
            // Format for display
            $formattedSenses = [];
            
            if (is_array($sensesData)) {
                foreach ($sensesData as $key => $value) {
                    // Handle comma-separated phrases
                    if (is_string($value)) {
                        $phrases = array_map('trim', explode(',', $value));
                        // Process each phrase to remove -A to -Z patterns and capitalize properly
                        $phrases = array_map([$this, 'processSenseValue'], $phrases);
                        $formattedSenses[$key] = [
                            'original' => $value,
                            'phrases' => $phrases
                        ];
                    } else {
                        // Process single value
                        $processedValue = $this->processSenseValue($value);
                        $formattedSenses[$key] = [
                            'original' => $value,
                            'phrases' => [$processedValue]
                        ];
                    }
                }
            }
            
            return [
                'raw' => $decodedSenses,
                'formatted' => $formattedSenses
            ];
        } catch (Exception $e) {
            // In case of errors, return the original with error info
            $this->response['debug']['senses_decode_error'] = $e->getMessage();
            return [
                'raw' => $sensesDutch,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Search for synonyms
     * 
     * @param string $searchQuery Query string
     * @param int $offset Pagination offset
     * @param int $limit Results limit
     * @return array Matching synonyms
     */
    private function searchSynonyms($searchQuery, $offset, $limit)
    {
        $synonymMatches = [];
        
        // Search directly using the search query in synonym field
        $sql = "SELECT id, lemma, synonym FROM hh_synonyms WHERE synonym LIKE ? LIMIT ?, ?";
        $searchParam = "%$searchQuery%";
        
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sii", $searchParam, $offset, $limit);
            
            if ($stmt->execute()) {
                $synonymResult = $stmt->get_result();
                
                $this->response['debug']['synonyms_found'] = $synonymResult->num_rows;
                
                while ($synonym = $synonymResult->fetch_assoc()) {
                    // Just add basic synonym info
                    $synonymMatches[] = [
                        "id" => $synonym['id'],
                        "lemma" => $synonym['lemma'],
                        "synonym" => $synonym['synonym']
                    ];
                }
            } else {
                $this->response['debug']['synonyms_execute_error'] = $stmt->error;
            }
            $stmt->close();
        } else {
            $this->response['debug']['synonyms_prepare_error'] = $this->conn->error;
        }
        
        $this->response['debug']['synonym_matches_found'] = count($synonymMatches);
        return array_slice($synonymMatches, 0, $limit);
    }
    
    /**
     * Apply sanitization to all search results
     *
     * @param array $results The array of search results
     * @return array Sanitized results
     */
    private function sanitizeResults($results) 
    {
        if (!is_array($results)) {
            return [];
        }
        
        $sanitized = [];
        
        // Known JSON fields that should not be HTML-escaped
        $jsonFields = [
            'senses', 
            'senses_dutch', 
            'control_nodig',
            'wie',
            'wie_snel_opname',
            'studioOpnameWie',
            'zelfopname',
            'videoLeft',
            'videoCenter',
            'videoRight',
            'videoTop',
            'videoA',
            'videoB',
            'nme_videos'
        ];
        
        foreach ($results as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeResults($value);
            } elseif (is_string($value)) {
                // Special handling for JSON strings
                if (in_array($key, $jsonFields) && $this->isJson($value)) {
                    // Decode and re-encode to ensure clean JSON without HTML entities
                    $sanitized[$key] = json_encode(json_decode($value));
                } else {
                    // Use the improved sanitizeOutput method for regular string values
                    $sanitized[$key] = $this->sanitizeOutput($value);
                }
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Check if a string is valid JSON
     *
     * @param string $string The string to check
     * @return boolean True if valid JSON, false otherwise
     */
    private function isJson($string) {
        if (!is_string($string)) return false;
        
        // Skip empty strings
        if (trim($string) === '') return false;
        
        // Try to decode
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}
