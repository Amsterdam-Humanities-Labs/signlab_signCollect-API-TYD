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
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Optional VideoService instance
     * @param MocapService $mocapService Optional MocapService instance
     */
    public function __construct($conn, &$response, $videoService = null, $mocapService = null)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
        $this->mocapService = $mocapService;
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
                'words' => [],
                'sentences' => [],
                'glosses' => [], // Changed from forms and sb_records to glosses
                'synonyms' => []
            ];
        }
        
        // Sanitize input to prevent SQL injection
        $searchQuery = $this->sanitizeInput($searchQuery);
        
        // Get raw search results
        $formResults = $this->searchForms($searchQuery, $offset, $limit);
        $sbResults = $this->searchSignbank($searchQuery, $offset, $limit);
        
        // Merge forms and sb_records into glosses with duplicate removal
        $glosses = $this->mergeGlosses($formResults, $sbResults);
        
        $results = [
            'words' => $this->searchWords($searchQuery),
            'sentences' => $this->searchSentences($searchQuery, $offset, $limit),
            'glosses' => $glosses, // New combined glosses field
            'synonyms' => $this->searchSynonyms($searchQuery, $offset, $limit)
        ];
        
        return $this->sanitizeResults($results);
    }
    
    /**
     * Merge forms and SignBank records into a single glosses array with duplicates removed
     * 
     * @param array $forms Form data results
     * @param array $sbRecords SignBank records results
     * @return array Merged glosses without duplicates
     */
    private function mergeGlosses($forms, $sbRecords)
    {
        $glosses = [];
        $processedGlosses = []; // Track processed glosses to avoid duplicates
        
        // Process form_data entries first
        foreach ($forms as $form) {
            $glos = '';
            
            // Extract first sense item from array for processing
            if (isset($form['senses']) && is_array($form['senses']) && !empty($form['senses'])) {
                $glos = $form['senses'][0] ?? '';
            } elseif (isset($form['senses']) && is_string($form['senses'])) {
                // Handle old format if still encountered
                $decoded = json_decode($form['senses'], true);
                $glos = is_array($decoded) && !empty($decoded) ? $decoded[0] : $form['senses'];
            }
            
            if (!empty($glos) && !in_array($glos, $processedGlosses)) {
                $processedGlosses[] = $glos;
                
                // Check for mocap data
                if ($this->mocapService !== null) {
                    $form['mocap'] = $this->mocapService->hasMocapData($glos);
                    $this->response['debug']['form_' . $form['id'] . '_mocap'] = $form['mocap'];
                } else {
                    $form['mocap'] = false;
                }
                
                $glosses[] = $form;
            }
        }
        
        // Process sb_records and add only non-duplicates
        foreach ($sbRecords as $record) {
            $annotationIdGloss = $record['annotation_id_gloss_dutch'] ?? '';
            
            // If annotation_id_gloss_dutch is empty, use the id as a fallback to ensure we include the record
            if (empty($annotationIdGloss) && isset($record['id'])) {
                $annotationIdGloss = 'sb_' . $record['id'];
            }
            
            // Skip if this gloss already exists
            if (!empty($annotationIdGloss) && !in_array($annotationIdGloss, $processedGlosses)) {
                $processedGlosses[] = $annotationIdGloss;
                
                // Check for mocap data
                if ($this->mocapService !== null) {
                    $record['mocap'] = $this->mocapService->hasMocapData($annotationIdGloss);
                    $this->response['debug']['sb_record_' . $record['id'] . '_mocap'] = $record['mocap'];
                } else {
                    $record['mocap'] = false;
                }
                
                $glosses[] = $record;
            }
        }
        
        return $glosses;
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
                // Updated query to include theme field
                $sql = "SELECT ID, zinString, theme FROM sentences WHERE JSON_CONTAINS(lemmaList, ?) OR JSON_CONTAINS(lemmaList, ?) LIMIT ?, ?";
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
                    $exists = in_array($sentence['ID'], array_column($sentenceMatches, 'ID'));
                    
                    if (!$exists) {
                        // Include theme in the basic sentence info
                        $sentenceMatches[] = [
                            "ID" => $sentence['ID'] ?? null,
                            "zinString" => $sentence['zinString'] ?? "",
                            "theme" => $sentence['theme'] ?? "Unknown",
                            "type" => "zin" // Add type for frontend to know which endpoint to call
                        ];
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
        $lemmas = $this->getLemmas($searchQuery);
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // Updated query to include theme field
                $sql = "SELECT id, senses, signbank, theme FROM form_data WHERE JSON_CONTAINS(CAST(IF(senses = '', '[]', senses) AS JSON), JSON_QUOTE(?)) AND extern = '1' AND glosZichtbaar = '0' LIMIT ?, ? ";
                $this->response['debug']['form_data_query'] = $sql;
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    $this->response['debug']['form_data_prepare_error'] = $this->conn->error;
                    continue;
                }
                
                // Bind one string and two integers (offset, limit)
                $stmt->bind_param("sii", $lemma, $offset, $limit);
                
                if (!$stmt->execute()) {
                    $this->response['debug']['form_data_execute_error'] = $stmt->error;
                    $stmt->close();
                    continue;
                }
                
                $formResult = $stmt->get_result();
                $stmt->close();
                
                $this->response['debug']['forms_found_for_lemma_' . $lemma] = $formResult->num_rows;
                
                while ($form = $formResult->fetch_assoc()) {
                    // Check if we already have this form using in_array
                    $exists = in_array($form['id'], array_column($formMatches, 'id'));
                    
                    if (!$exists) {
                        // Check if there are any videos associated with this form
                        $hasVideos = true;
                        
                        if ($this->videoService !== null) {
                            $videos = $this->videoService->getVideosForEntity($form['id'], 'glos');
                            $hasVideos = !empty($videos['videoLeft']) || !empty($videos['videoCenter']) || !empty($videos['videoRight']);
                            $this->response['debug']['form_' . $form['id'] . '_has_videos'] = $hasVideos;
                        }
                        
                        // Only add the form if it has associated videos
                        if ($hasVideos) {
                            // Convert senses from JSON string to array format
                            $sensesArray = [];
                            if (!empty($form['senses'])) {
                                $decodedSenses = json_decode($form['senses'], true);
                                if (is_array($decodedSenses)) {
                                    $sensesArray = $decodedSenses;
                                } elseif (is_string($decodedSenses)) {
                                    $sensesArray = [$decodedSenses];
                                }
                            }
                            
                            // Include theme in basic form info
                            $formMatches[] = [
                                "id" => $form['id'],
                                "senses" => $sensesArray, // Now using array format like sb_records
                                "signbank" => $form['signbank'] ?? "",
                                "theme" => $form['theme'] ?? "Unknown",
                                "type" => "glos", // Add type for frontend to know which endpoint to call
                                "source" => "form_data"
                            ];
                        }
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
                        $formattedSenses[$key] = [
                            'original' => $value,
                            'phrases' => $phrases
                        ];
                    } else {
                        $formattedSenses[$key] = [
                            'original' => $value,
                            'phrases' => [$value]
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
