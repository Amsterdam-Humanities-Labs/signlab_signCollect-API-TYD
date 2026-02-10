<?php
/**
 * Service for handling auto-suggestions
 */
class SuggestionService
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
     * Get suggestions based on query
     * 
     * @param string $searchQuery Query string for suggestions
     * @return array Suggestions for words, sentences, lemmas and synonyms (limited to 8 total)
     */
    public function getSuggestions($searchQuery)
    {
        // Minimum 3 characters for suggestion
        if (strlen($searchQuery) < 3) {
            return [
                'words' => [],
                'sentences' => [],
                'lemmas' => [],
                'synonyms' => []
            ];
        }
        
        $this->response['debug']['received_query'] = $searchQuery;
        
        // Get all suggestions with smaller individual limits
        $wordSuggestions = $this->getWordSuggestions($searchQuery, 3);
        $sentenceSuggestions = $this->getSentenceSuggestions($searchQuery, 3);
        $lemmaSuggestions = $this->getLemmaSuggestions($searchQuery, 1);
        $synonymSuggestions = $this->getSynonymSuggestions($searchQuery, 1);
        
        // Combine all suggestions and limit to 8 total
        $allSuggestions = array_merge($wordSuggestions, $sentenceSuggestions, $lemmaSuggestions, $synonymSuggestions);
        $limitedSuggestions = array_slice($allSuggestions, 0, 8);
        
        // Separate back into categories for response
        $result = [
            'words' => [],
            'sentences' => [],
            'lemmas' => [],
            'synonyms' => []
        ];
        
        foreach ($limitedSuggestions as $suggestion) {
            if (isset($suggestion['type'])) {
                switch ($suggestion['type']) {
                    case 'word':
                        $result['words'][] = $suggestion;
                        break;
                    case 'sentence':
                        $result['sentences'][] = $suggestion;
                        break;
                    case 'lemma':
                        $result['lemmas'][] = $suggestion;
                        break;
                    case 'synonym':
                        $result['synonyms'][] = $suggestion;
                        break;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get word suggestions
     * 
     * @param string $searchQuery Search query
     * @param int $limit Maximum number of suggestions to return
     * @return array Word suggestions
     */
    private function getWordSuggestions($searchQuery, $limit = 10)
    {
        $wordSuggestions = [];
        $searchParam = $searchQuery . '%';
        
        // Get word suggestions that start with the query
        $stmt = $this->conn->prepare("SELECT DISTINCT word, lemma FROM hh_words 
                                    WHERE word LIKE ? 
                                    ORDER BY word ASC LIMIT ?");
        
        if (!$stmt) {
            $this->response['debug']['suggest_prepare_error'] = $this->conn->error;
            return $wordSuggestions;
        }
        
        $stmt->bind_param("si", $searchParam, $limit);
        
        if (!$stmt->execute()) {
            $this->response['debug']['suggest_execute_error'] = $stmt->error;
            $stmt->close();
            return $wordSuggestions;
        }
        
        $wordResult = $stmt->get_result();
        $stmt->close();
        
        while ($word = $wordResult->fetch_assoc()) {
            $wordSuggestions[] = [
                'text' => $word['word'],
                'lemma' => $word['lemma'],
                'type' => 'word'
            ];
        }
        
        return $wordSuggestions;
    }
    
    /**
     * Get lemma suggestions
     * 
     * @param string $searchQuery Search query
     * @param int $limit Maximum number of suggestions to return
     * @return array Lemma suggestions
     */
    private function getLemmaSuggestions($searchQuery, $limit = 10)
    {
        $lemmaSuggestions = [];
        $searchParam = $searchQuery . '%';
        
        // Get lemma suggestions directly
        $stmt = $this->conn->prepare("SELECT DISTINCT lemma FROM hh_words 
                                    WHERE lemma LIKE ? AND lemma != ? 
                                    ORDER BY lemma ASC LIMIT ?");
        
        if ($stmt) {
            $stmt->bind_param("ssi", $searchParam, $searchQuery, $limit);
            
            if ($stmt->execute()) {
                $lemmaResult = $stmt->get_result();
                
                while ($lemma = $lemmaResult->fetch_assoc()) {
                    $lemmaSuggestions[] = [
                        'text' => $lemma['lemma'],
                        'lemma' => $lemma['lemma'],
                        'type' => 'lemma'
                    ];
                }
            }
            $stmt->close();
        }
        
        return $lemmaSuggestions;
    }
    
    /**
     * Get synonym suggestions
     * 
     * @param string $searchQuery Search query
     * @param int $limit Maximum number of suggestions to return
     * @return array Synonym suggestions
     */
    private function getSynonymSuggestions($searchQuery, $limit = 10)
    {
        $synonymSuggestions = [];
        $searchParam = $searchQuery . '%';
        
        // Get synonym suggestions
        $stmt = $this->conn->prepare("SELECT id, lemma, synonym FROM hh_synonyms 
                                     WHERE synonym LIKE ? 
                                     ORDER BY synonym ASC LIMIT ?");
        
        if ($stmt) {
            $stmt->bind_param("si", $searchParam, $limit);
            
            if ($stmt->execute()) {
                $synonymResult = $stmt->get_result();
                
                while ($synonym = $synonymResult->fetch_assoc()) {
                    $synonymSuggestions[] = [
                        'text' => $synonym['synonym'],
                        'lemma' => $synonym['lemma'],
                        'id' => $synonym['id'],
                        'type' => 'synonym'
                    ];
                }
            }
            $stmt->close();
        }
        
        return $synonymSuggestions;
    }
    
    /**
     * Get sentence suggestions
     * 
     * @param string $searchQuery Search query
     * @param int $limit Maximum number of suggestions to return
     * @return array Sentence suggestions
     */
    private function getSentenceSuggestions($searchQuery, $limit = 3)
    {
        $sentenceSuggestions = [];
        
        // Check if searchQuery contains multiple words (spaces)
        if (strpos($searchQuery, ' ') !== false) {
            // Multi-word query: split into individual words and search for sentences containing all words
            $words = array_filter(explode(' ', trim($searchQuery))); // Remove empty elements
            
            if (!empty($words)) {
                // Build query to find sentences containing all words
                $lemmaConditions = [];
                $params = [];
                
                foreach ($words as $word) {
                    $lemmaConditions[] = "(JSON_CONTAINS(lemmaList, ?) OR JSON_CONTAINS(lemmaList, ?))";
                    $params[] = json_encode($word);
                    $params[] = json_encode("\"$word\"");
                }
                
                $sql = "SELECT ID, zinStringEAF AS zinString, IFNULL(thema, 'Unknown') as thema FROM sentences WHERE " . SENTENCE_STATUS_FILTER . " AND " . implode(' AND ', $lemmaConditions) . " LIMIT ?";
                
                $stmt = $this->conn->prepare($sql);
                if ($stmt) {
                    // Build bind parameters: strings for each word (2 per word) + limit
                    $bindTypes = str_repeat('s', count($params)) . 'i';
                    $params[] = $limit;
                    
                    $stmt->bind_param($bindTypes, ...$params);
                    
                    if ($stmt->execute()) {
                        $sentenceResult = $stmt->get_result();
                        
                        while ($sentence = $sentenceResult->fetch_assoc()) {
                            // Check if sentence has videos
                            $hasMatchedTranscription = false;
                            $checkStmt = $this->conn->prepare("SELECT 1 FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'zin' AND added = '1' LIMIT 1");
                            if ($checkStmt) {
                                $checkStmt->bind_param("i", $sentence['ID']);
                                if ($checkStmt->execute()) {
                                    $checkResult = $checkStmt->get_result();
                                    $hasMatchedTranscription = $checkResult->num_rows > 0;
                                }
                                $checkStmt->close();
                            }

                            if ($hasMatchedTranscription) {
                                // Truncate long sentences for suggestions
                                $truncatedText = strlen($sentence['zinString']) > 60 ?
                                    substr($sentence['zinString'], 0, 57) . '...' :
                                    $sentence['zinString'];

                                $sentenceSuggestions[] = [
                                    'text' => $truncatedText,
                                    'full_text' => $sentence['zinString'],
                                    'id' => $sentence['ID'],
                                    'thema' => $sentence['thema'],
                                    'type' => 'sentence'
                                ];
                            }
                        }
                    }
                    $stmt->close();
                }
            }
        } else {
            // Single word query: search using lemma
            $lemmaJson = json_encode($searchQuery);
            $lemmaQuotedJson = json_encode("\"$searchQuery\"");

            $sql = "SELECT ID, zinStringEAF AS zinString, IFNULL(thema, 'Unknown') as thema FROM sentences WHERE " . SENTENCE_STATUS_FILTER . " AND (JSON_CONTAINS(lemmaList, ?) OR JSON_CONTAINS(lemmaList, ?)) LIMIT ?";

            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ssi", $lemmaJson, $lemmaQuotedJson, $limit);

                if ($stmt->execute()) {
                    $sentenceResult = $stmt->get_result();

                    while ($sentence = $sentenceResult->fetch_assoc()) {
                        // Check if sentence has videos
                        $hasMatchedTranscription = false;
                        $checkStmt = $this->conn->prepare("SELECT 1 FROM matched_transcriptions WHERE m_transcription = ? AND zOg = 'zin' AND added = '1' LIMIT 1");
                        if ($checkStmt) {
                            $checkStmt->bind_param("i", $sentence['ID']);
                            if ($checkStmt->execute()) {
                                $checkResult = $checkStmt->get_result();
                                $hasMatchedTranscription = $checkResult->num_rows > 0;
                            }
                            $checkStmt->close();
                        }
                        
                        if ($hasMatchedTranscription) {
                            // Truncate long sentences for suggestions
                            $truncatedText = strlen($sentence['zinString']) > 60 ? 
                                substr($sentence['zinString'], 0, 57) . '...' : 
                                $sentence['zinString'];
                            
                            $sentenceSuggestions[] = [
                                'text' => $truncatedText,
                                'full_text' => $sentence['zinString'],
                                'id' => $sentence['ID'],
                                'thema' => $sentence['thema'],
                                'type' => 'sentence'
                            ];
                        }
                    }
                }
                $stmt->close();
            }
        }
        
        return $sentenceSuggestions;
    }
}
