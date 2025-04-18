<?php
/**
 * Service for handling search operations
 */
class SearchService
{
    private $conn;
    private $response;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     */
    public function __construct($conn, &$response)
    {
        $this->conn = $conn;
        $this->response = &$response;
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
                'forms' => [],
                'sb_records' => [],
                'synonyms' => []
            ];
        }
        
        // Sanitize input to prevent SQL injection
        $searchQuery = $this->sanitizeInput($searchQuery);
        
        // Get search results
        $results = [
            'words' => $this->searchWords($searchQuery),
            'sentences' => $this->searchSentences($searchQuery, $offset, $limit),
            'forms' => $this->searchForms($searchQuery, $offset, $limit),
            'sb_records' => $this->searchSignbank($searchQuery, $offset, $limit),
            'synonyms' => $this->searchSynonyms($searchQuery, $offset, $limit)
        ];
        
        // Sanitize ALL output to prevent XSS
        return $this->sanitizeResults($results);
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
        $lemmas = [];
        
        foreach ($words as $word) {
            if (!in_array($word['lemma'], $lemmas)) {
                $lemmas[] = $word['lemma'];
            }
        }
        
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
                // Updated query with LIMIT for sentences pagination
                $sql = "SELECT ID, zinString FROM sentences WHERE JSON_CONTAINS(lemmaList, ?) OR JSON_CONTAINS(lemmaList, ?) LIMIT ?, ?";
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
                    // Check if we already have this sentence
                    $exists = false;
                    foreach ($sentenceMatches as $existingSentence) {
                        if ($existingSentence['ID'] == $sentence['ID']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // Just add the basic sentence info - no videos
                        $sentenceMatches[] = [
                            "ID" => $sentence['ID'] ?? null,
                            "zinString" => $sentence['zinString'] ?? "",
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
                // Updated query with LIMIT for form_data pagination
                $sql = "SELECT id, senses, signbank FROM form_data WHERE JSON_CONTAINS(CAST(IF(senses = '', '[]', senses) AS JSON), JSON_QUOTE(?)) LIMIT ?, ?";
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
                    // Check if we already have this form
                    $exists = false;
                    foreach ($formMatches as $existingForm) {
                        if ($existingForm['id'] == $form['id']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // Just add basic form info without videos
                        $formMatches[] = [
                            "id" => $form['id'],
                            "senses" => $form['senses'] ?? "",
                            "signbank" => $form['signbank'] ?? "",
                            "type" => "glos" // Add type for frontend to know which endpoint to call
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
        $sbRecordMatches = [];
        $lemmas = $this->getLemmas($searchQuery);
        
        if (!empty($lemmas)) {
            foreach ($lemmas as $lemma) {
                // Search for lemma in senses_dutch field which is JSON formatted
                $sql = "SELECT id, senses_dutch FROM sb_records WHERE ";
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
                
                while ($record = $sbResult->fetch_assoc()) {
                    // Check if we already have this record
                    $exists = false;
                    foreach ($sbRecordMatches as $existingRecord) {
                        if ($existingRecord['id'] == $record['id']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        // Just add basic record info without videos
                        $sbRecordMatches[] = [
                            "id" => $record['id'],
                            "senses_dutch" => $this->sanitizeOutput($record['senses_dutch'] ?? ""),
                            "type" => "sb" // Add type for frontend to know which endpoint to call
                        ];
                    }
                }
            }
        }
        
        $this->response['debug']['sb_record_matches_found'] = count($sbRecordMatches);
        return array_slice($sbRecordMatches, 0, $limit);
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
        
        foreach ($results as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeResults($value);
            } elseif (is_string($value)) {
                // Use the improved sanitizeOutput method for string values
                $sanitized[$key] = $this->sanitizeOutput($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}
