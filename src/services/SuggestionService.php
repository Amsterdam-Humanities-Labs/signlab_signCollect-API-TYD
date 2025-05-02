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
     * @return array Suggestions for words, lemmas and synonyms
     */
    public function getSuggestions($searchQuery)
    {
        // Minimum 3 characters for suggestion
        if (strlen($searchQuery) < 3) {
            return [
                'words' => [],
                'lemmas' => [],
                'synonyms' => []
            ];
        }
        
        $this->response['debug']['received_query'] = $searchQuery;
        
        return [
            'words' => $this->getWordSuggestions($searchQuery),
            'lemmas' => $this->getLemmaSuggestions($searchQuery),
            'synonyms' => $this->getSynonymSuggestions($searchQuery)
        ];
    }
    
    /**
     * Get word suggestions
     * 
     * @param string $searchQuery Search query
     * @return array Word suggestions
     */
    private function getWordSuggestions($searchQuery)
    {
        $wordSuggestions = [];
        $searchParam = $searchQuery . '%';
        
        // Get word suggestions that start with the query
        $stmt = $this->conn->prepare("SELECT DISTINCT word, lemma FROM hh_words 
                                    WHERE word LIKE ? 
                                    ORDER BY word ASC LIMIT 10");
        
        if (!$stmt) {
            $this->response['debug']['suggest_prepare_error'] = $this->conn->error;
            return $wordSuggestions;
        }
        
        $stmt->bind_param("s", $searchParam);
        
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
                'lemma' => $word['lemma']
            ];
        }
        
        return $wordSuggestions;
    }
    
    /**
     * Get lemma suggestions
     * 
     * @param string $searchQuery Search query
     * @return array Lemma suggestions
     */
    private function getLemmaSuggestions($searchQuery)
    {
        $lemmaSuggestions = [];
        $searchParam = $searchQuery . '%';
        
        // First, get lemmas from word suggestions
        $wordSuggestions = $this->getWordSuggestions($searchQuery);
        foreach ($wordSuggestions as $word) {
            // Add lemma to lemma suggestions if not already there using in_array
            $lemmaExists = in_array($word['lemma'], array_column($lemmaSuggestions, 'text'), true);
            
            if (!$lemmaExists && $word['lemma'] !== $word['text']) {
                $lemmaSuggestions[] = [
                    'text' => $word['lemma'],
                    'lemma' => $word['lemma']
                ];
            }
        }
        
        // Get additional lemma suggestions
        $stmt = $this->conn->prepare("SELECT DISTINCT lemma FROM hh_words 
                                    WHERE lemma LIKE ? AND lemma NOT IN (SELECT word FROM hh_words WHERE word = lemma)
                                    ORDER BY lemma ASC LIMIT 10");
        
        if ($stmt) {
            $stmt->bind_param("s", $searchParam);
            
            if ($stmt->execute()) {
                $lemmaResult = $stmt->get_result();
                
                while ($lemma = $lemmaResult->fetch_assoc()) {
                    // Check if lemma already exists in suggestions using in_array
                    $lemmaExists = in_array($lemma['lemma'], array_column($lemmaSuggestions, 'text'), true);
                    
                    if (!$lemmaExists) {
                        $lemmaSuggestions[] = [
                            'text' => $lemma['lemma'],
                            'lemma' => $lemma['lemma']
                        ];
                    }
                }
            }
            $stmt->close();
        }
        
        return array_slice($lemmaSuggestions, 0, 10);
    }
    
    /**
     * Get synonym suggestions
     * 
     * @param string $searchQuery Search query
     * @return array Synonym suggestions
     */
    private function getSynonymSuggestions($searchQuery)
    {
        $synonymSuggestions = [];
        $searchParam = $searchQuery . '%';
        
        // Get synonym suggestions
        $stmt = $this->conn->prepare("SELECT id, lemma, synonym FROM hh_synonyms 
                                     WHERE synonym LIKE ? 
                                     ORDER BY synonym ASC LIMIT 10");
        
        if ($stmt) {
            $stmt->bind_param("s", $searchParam);
            
            if ($stmt->execute()) {
                $synonymResult = $stmt->get_result();
                
                while ($synonym = $synonymResult->fetch_assoc()) {
                    $synonymSuggestions[] = [
                        'text' => $synonym['synonym'],
                        'lemma' => $synonym['lemma'],
                        'id' => $synonym['id']
                    ];
                }
            }
            $stmt->close();
        }
        
        return array_slice($synonymSuggestions, 0, 10);
    }
}
