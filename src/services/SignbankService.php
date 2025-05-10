<?php
/**
 * Service for handling Signbank records
 */
class SignbankService
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
     * Get Signbank record by ID
     * 
     * @param int $id Signbank ID
     * @return array Signbank data
     * @throws Exception If record not found
     */
    public function getSignbankById($id)
    {
        // Fetch sb_records data
        $stmt = $this->conn->prepare("SELECT id, senses_dutch, annotation_id_gloss_dutch, nme_videos, video FROM sb_records WHERE id = ?");
        if (!$stmt) {
            $this->response['debug']['sb_records_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['sb_records_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $sbResult = $stmt->get_result();
        $stmt->close();
        
        if ($sbResult->num_rows === 0) {
            throw new Exception('SignBank record not found with ID: ' . $id);
        }
        
        $sbRecord = $sbResult->fetch_assoc();
        
        // Parse senses_dutch from JSON and decode HTML entities
        $sensesDutch = $this->decodeSensesDutch($sbRecord['senses_dutch']);
        
        // Initialize videos structure
        $videos = [
            'videoLeft' => null,
            'videoCenter' => null,
            'videoRight' => null
        ];
        
        // Get video from nme_videos JSON field or fall back to video field
        if (!empty($sbRecord['nme_videos'])) {
            try {
                $nmeVideos = json_decode($sbRecord['nme_videos'], true);
                
                if (is_array($nmeVideos) && !empty($nmeVideos)) {
                    // Get the first video
                    $firstVideo = $nmeVideos[0];
                    
                    if (isset($firstVideo['Link'])) {
                        $videoLink = $firstVideo['Link'];
                        
                        // Transform the URL as required
                        $videoLink = str_replace(
                            'https://signbank.cls.ru.nl//dictionary/protected_media/glossvideo/NGT/UI/', 
                            'https://signcollect.nl/uploads/',
                            $videoLink
                        );
                        
                        // Assign to videoCenter
                        $videos['videoCenter'] = $videoLink;
                    }
                }
            } catch (Exception $e) {
                $this->response['debug']['nme_videos_parse_error'] = $e->getMessage();
            }
        } 
        
        // If no video was found in nme_videos, use the video field if available
        if (empty($videos['videoCenter']) && !empty($sbRecord['video'])) {
            $videos['videoCenter'] = $sbRecord['video'];
        }
        
        // Prepare the final response
        return [
            "id" => $sbRecord['id'] ?? null,
            "senses_dutch" => $sensesDutch,
            "annotation_id_gloss_dutch" => $sbRecord['annotation_id_gloss_dutch'] ?? "",
            "videos" => $videos
        ];
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
     * Search for exact matches in senses_dutch field
     * 
     * @param string $term Search term
     * @param array $sbRecords Array of SignBank records
     * @return array Records with matched terms highlighted
     */
    public function findExactMatchesInSenses($term, $sbRecords)
    {
        if (empty($term) || empty($sbRecords)) {
            return $sbRecords;
        }
        
        $matchedRecords = [];
        
        foreach ($sbRecords as $record) {
            $sensesDutch = $record['senses_dutch'] ?? '';
            if (!empty($sensesDutch)) {
                $decodedSenses = $this->decodeSensesDutch($sensesDutch);
                
                // Track if we found a match
                $matchFound = false;
                $matchedPhrases = [];
                
                // Check for exact matches in phrases
                if (isset($decodedSenses['formatted']) && is_array($decodedSenses['formatted'])) {
                    foreach ($decodedSenses['formatted'] as $key => $data) {
                        if (isset($data['phrases']) && is_array($data['phrases'])) {
                            foreach ($data['phrases'] as $phrase) {
                                // Check for exact word match (case insensitive)
                                $wordsInPhrase = preg_split('/\s+/', $phrase);
                                foreach ($wordsInPhrase as $word) {
                                    // Remove any non-alphanumeric characters for comparison
                                    $cleanWord = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $word);
                                    $cleanTerm = preg_replace('/[^a-zA-ZÀ-ÿ0-9]/', '', $term);
                                    
                                    if (strcasecmp($cleanWord, $cleanTerm) === 0) {
                                        $matchFound = true;
                                        $matchedPhrases[] = $phrase;
                                        break 2; // Break out of both loops once match is found
                                    }
                                }
                            }
                        }
                    }
                }
                
                if ($matchFound) {
                    // Add match details to the record
                    $record['matched_phrases'] = array_unique($matchedPhrases);
                    $matchedRecords[] = $record;
                }
            }
        }
        
        return $matchedRecords;
    }
}
