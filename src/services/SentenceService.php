<?php
/**
 * Service for handling sentence data
 */
class SentenceService
{
    private $conn;
    private $response;
    private $videoService;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Video service
     */
    public function __construct($conn, &$response, $videoService)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
    }
    
    /**
     * Get all unique themas from sentences table
     * 
     * @return array Array of unique themas
     */
    public function getAllThemas()
    {
        $themas = [];
        
        $sql = "SELECT DISTINCT thema FROM sentences WHERE thema IS NOT NULL AND thema != '' ORDER BY thema ASC";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['themas_prepare_error'] = $this->conn->error;
            return $themas;
        }
        
        if (!$stmt->execute()) {
            $this->response['debug']['themas_execute_error'] = $stmt->error;
            $stmt->close();
            return $themas;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        while ($row = $result->fetch_assoc()) {
            $themas[] = $row['thema'];
        }
        
        return $themas;
    }
    
    /**
     * Get sentences by thema
     * 
     * @param string $thema The thema to filter by
     * @param int $limit Maximum number of results (default: 100)
     * @param int $offset Pagination offset (default: 0)
     * @return array Array of sentences
     */
    public function getSentencesByThema($thema, $limit = 100, $offset = 0)
    {
        $sentences = [];
        
        $sql = "SELECT ID, zinString, thema FROM sentences WHERE thema = ? ORDER BY ID ASC LIMIT ? OFFSET ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['sentences_by_thema_prepare_error'] = $this->conn->error;
            return $sentences;
        }
        
        $stmt->bind_param("sii", $thema, $limit, $offset);
        
        if (!$stmt->execute()) {
            $this->response['debug']['sentences_by_thema_execute_error'] = $stmt->error;
            $stmt->close();
            return $sentences;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        while ($row = $result->fetch_assoc()) {
            $sentences[] = [
                'id' => $row['ID'],
                'zinString' => $row['zinString'],
                'thema' => $row['thema']
            ];
        }
        
        return $sentences;
    }
    
    /**
     * Get sentence data by ID
     * 
     * @param int $id Sentence ID
     * @return array Sentence data
     * @throws Exception If sentence not found
     */
    public function getSentenceById($id)
    {
        // Fetch sentence data with only existing columns (ID, zinString, thema)
        $stmt = $this->conn->prepare("SELECT ID, zinString, thema FROM sentences WHERE ID = ?");
        if (!$stmt) {
            $this->response['debug']['sentence_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['sentence_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $sentenceResult = $stmt->get_result();
        $stmt->close();
        
        if ($sentenceResult->num_rows === 0) {
            throw new Exception('Sentence not found with ID: ' . $id);
        }
        
        $sentence = $sentenceResult->fetch_assoc();
        
        // Get videos
        $sentence['videos'] = $this->videoService->getVideosForEntity($id, 'zin');
        
        // Generate subtitle files
        $subtitleFiles = [];
        if (!empty($sentence['videos']['videoCenter'])) {
            $videoUrl = $sentence['videos']['videoCenter'];
            // Extract filename from URL (e.g., M20241216_0838.mp4)
            if (preg_match('/\/([^\/]+\.mp4)$/', $videoUrl, $matches)) {
                $baseFilename = str_replace('.mp4', '', $matches[1]);
                
                // Create the SRT filenames
                $subtitleFiles = [
                    'Nederlands' => $baseFilename . '_Nederlands.srt',
                    'Gebaar_voor_Gebaar' => $baseFilename . '_Gebaar-voor-gebaar.srt',
                    'Signbank_ID_glossen' => $baseFilename . '_Signbank_ID_glossen.srt'
                ];
            }
        }
        
        // Add full URLs for subtitle files
        $subtitleFullUrls = [];
        foreach ($subtitleFiles as $type => $filename) {
            $subtitleFullUrls[$type] = $filename ? SUBTITLE_BASE_URL . $filename : null;
        }
        
        // Prepare the final response
        return [
            "id" => $sentence['ID'] ?? null,
            "zinstring" => $sentence['zinString'] ?? "",
            "Nederlands" => "", // Providing empty default for non-existent column
            "Gebaar_voor_Gebaar" => "", // Providing empty default for non-existent column
            "Signbank_ID_glossen" => "", // Providing empty default for non-existent column
            "videos" => $sentence['videos'],
            "subtitleFiles" => $subtitleFullUrls
        ];
    }
    
    /**
     * Get video data and glosses for a sentence
     * 
     * @param int $sentenceId Sentence ID
     * @return array Video data with glosses and form IDs
     */
    public function getVideoDataForSentence($sentenceId)
    {
        // First get the sentence data
        $stmt = $this->conn->prepare("SELECT ID, zinString, thema, glosses FROM sentences WHERE ID = ?");
        if (!$stmt) {
            $this->response['debug']['sentence_prepare_error'] = $this->conn->error;
            return null;
        }
        
        $stmt->bind_param("i", $sentenceId);
        if (!$stmt->execute()) {
            $this->response['debug']['sentence_execute_error'] = $stmt->error;
            $stmt->close();
            return null;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows === 0) {
            return null;
        }
        
        $sentence = $result->fetch_assoc();
        
        // Extract glosses from sentence data
        $glosses = [];
        if (!empty($sentence['glosses'])) {
            // Parse JSON glosses or split by spaces if it's a string
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
        
        $baseUrl = defined('MEDIA_BASE_URL') ? MEDIA_BASE_URL : 'https://media.signcollect.nl/';
        
        // 1. GET SENTENCE VIDEO DATA
        $sentenceVideoData = $this->getSentenceVideoData($sentenceId, $baseUrl);
        
        // 2. GET GLOSSES VIDEO DATA
        $glossVideoData = $this->getGlossesVideoData($glosses, $baseUrl);
        
        return [
            'sentenceId' => $sentenceId,
            'zinString' => $sentence['zinString'],
            'glosses' => $glosses,
            'sentenceVideos' => $sentenceVideoData['videos'],
            'sentenceThumbnails' => $sentenceVideoData['thumbnails'],
            'glossVideosData' => $glossVideoData['glossVideosData'],
            'formDataIds' => $glossVideoData['formDataIds'],
            'nmmIds' => $glossVideoData['nmmIds'] ?? [],
            'glossDataSource' => $glossVideoData['dataSource']
        ];
    }
    
    /**
     * Get form_data ID by glos value with configurable matching
     * 
     * @param string $glos The glos value to search for
     * @param bool $exactMatch If true, use exact match; if false, use wildcard match (default: false)
     * @return int|null Form data ID or null if not found
     */
    private function getFormDataIdByGlos($glos, $exactMatch = false)
    {
        if ($exactMatch) {
            // Use exact match for sentence gloss video lookup
            $sql = "SELECT id FROM form_data WHERE glos = ? AND extern = '1' AND glosZichtbaar = '0' LIMIT 1";
            $searchValue = $glos;
        } else {
            // Use wildcard match for general search (default behavior)
            $sql = "SELECT id FROM form_data WHERE glos LIKE ? AND extern = '1' AND glosZichtbaar = '0' LIMIT 1";
            $searchValue = $glos . '%';
        }
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['form_data_by_glos_prepare_error'] = $this->conn->error;
            return null;
        }
        
        $stmt->bind_param("s", $searchValue);
        
        if (!$stmt->execute()) {
            $this->response['debug']['form_data_by_glos_execute_error'] = $stmt->error;
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
     * 
     * @param int $formId The form_data ID to search for
     * @return array|null Matched transcription data or null if not found
     */
    private function getMatchedTranscriptionsByFormId($formId)
    {
        $sql = "SELECT id, l_file, m_file, r_file, l_transcription, m_transcription, r_transcription, 
                       added, app_ready, zOg
                FROM matched_transcriptions 
                WHERE (l_transcription = ? OR m_transcription = ? OR r_transcription = ?) 
                  AND zOg IN ('glos', 'extern')
                ORDER BY id DESC
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['matched_transcriptions_by_form_prepare_error'] = $this->conn->error;
            return null;
        }
        
        $stmt->bind_param("iii", $formId, $formId, $formId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['matched_transcriptions_by_form_execute_error'] = $stmt->error;
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
    
    /**
     * Get video data specifically from sentence matched_transcriptions (zOg = 'zin')
     * 
     * @param int $sentenceId The sentence ID
     * @param string $baseUrl Base URL for media files
     * @return array Array with videos and thumbnails
     */
    private function getSentenceVideoData($sentenceId, $baseUrl)
    {
        $sql = "SELECT id, l_file, m_file, r_file, l_transcription, m_transcription, r_transcription, 
                       added, app_ready, zOg
                FROM matched_transcriptions 
                WHERE m_transcription = ? AND zOg = 'zin'
                ORDER BY id DESC
                LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['sentence_video_data_prepare_error'] = $this->conn->error;
            return ['videos' => [], 'thumbnails' => []];
        }
        
        $stmt->bind_param("i", $sentenceId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['sentence_video_data_execute_error'] = $stmt->error;
            $stmt->close();
            return ['videos' => [], 'thumbnails' => []];
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows === 0) {
            return ['videos' => [], 'thumbnails' => []];
        }
        
        $matchedTranscription = $result->fetch_assoc();
        
        $videos = [];
        $thumbnails = [];
        
        // Process left video
        if (!empty($matchedTranscription['l_file'])) {
            $videoFile = preg_replace('/\.wav$/i', '.mp4', $matchedTranscription['l_file']);
            $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $matchedTranscription['l_file']);
            $videos['left'] = $baseUrl . $videoFile;
            $thumbnails['left'] = $baseUrl . $thumbnailFile;
        }
        
        // Process center video
        if (!empty($matchedTranscription['m_file'])) {
            $videoFile = preg_replace('/\.wav$/i', '.mp4', $matchedTranscription['m_file']);
            $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $matchedTranscription['m_file']);
            $videos['center'] = $baseUrl . $videoFile;
            $thumbnails['center'] = $baseUrl . $thumbnailFile;
        }
        
        // Process right video
        if (!empty($matchedTranscription['r_file'])) {
            $videoFile = preg_replace('/\.wav$/i', '.mp4', $matchedTranscription['r_file']);
            $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $matchedTranscription['r_file']);
            $videos['right'] = $baseUrl . $videoFile;
            $thumbnails['right'] = $baseUrl . $thumbnailFile;
        }
        
        return ['videos' => $videos, 'thumbnails' => $thumbnails];
    }
    
    /**
     * Get video data from glosses via form_data matched_transcriptions, with NMM fallback
     * 
     * @param array $glosses Array of gloss strings
     * @param string $baseUrl Base URL for media files
     * @return array Array with videos per gloss, thumbnails per gloss, formDataIds, and nmmIds
     */
    private function getGlossesVideoData($glosses, $baseUrl)
    {
        $glossVideosData = [];
        $allFormDataIds = [];
        $allNmmIds = [];
        $hasFormData = false;
        $hasNmmData = false;
        
        foreach ($glosses as $gloss) {
            $glossData = [
                'gloss' => $gloss,
                'videos' => [],
                'thumbnails' => [],
                'formDataId' => null,
                'nmmId' => null,
                'dataSource' => null
            ];
            
            // First try to find videos via form_data (use exact match for sentence gloss lookup)
            $formId = $this->getFormDataIdByGlos($gloss, true);
            if ($formId) {
                $allFormDataIds[] = $formId;
                $glossData['formDataId'] = $formId;
                
                // Get matched_transcriptions for this form_data ID
                $transcriptionData = $this->getMatchedTranscriptionsByFormId($formId);
                if ($transcriptionData) {
                    $glossData['dataSource'] = 'form_data';
                    $hasFormData = true;
                    
                    // Process left video
                    if (!empty($transcriptionData['l_file'])) {
                        $videoFile = preg_replace('/\.wav$/i', '.mp4', $transcriptionData['l_file']);
                        $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $transcriptionData['l_file']);
                        $glossData['videos']['left'] = $baseUrl . $videoFile;
                        $glossData['thumbnails']['left'] = $baseUrl . $thumbnailFile;
                    }
                    
                    // Process center video
                    if (!empty($transcriptionData['m_file'])) {
                        $videoFile = preg_replace('/\.wav$/i', '.mp4', $transcriptionData['m_file']);
                        $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $transcriptionData['m_file']);
                        $glossData['videos']['center'] = $baseUrl . $videoFile;
                        $glossData['thumbnails']['center'] = $baseUrl . $thumbnailFile;
                    }
                    
                    // Process right video
                    if (!empty($transcriptionData['r_file'])) {
                        $videoFile = preg_replace('/\.wav$/i', '.mp4', $transcriptionData['r_file']);
                        $thumbnailFile = preg_replace('/\.wav$/i', '.jpg', $transcriptionData['r_file']);
                        $glossData['videos']['right'] = $baseUrl . $videoFile;
                        $glossData['thumbnails']['right'] = $baseUrl . $thumbnailFile;
                    }
                }
            }
            
            // If no videos found via form_data for this gloss, try NMM data as fallback
            if (empty($glossData['videos'])) {
                $this->response['debug']['trying_nmm_fallback_for_gloss'] = $gloss;
                
                // Create NmmService instance
                $nmmService = new NmmService($this->conn, $this->response);
                $nmmResults = $nmmService->searchNmmByGlos($gloss, 1, true); // Limit to 1 result, use exact match
                
                if (!empty($nmmResults)) {
                    $nmmRecord = $nmmResults[0];
                    $allNmmIds[] = $nmmRecord['id'];
                    $glossData['nmmId'] = $nmmRecord['id'];
                    $glossData['dataSource'] = 'nmm_data';
                    $hasNmmData = true;
                    
                    // Extract videos from NMM record
                    if (isset($nmmRecord['videos'])) {
                        if (!empty($nmmRecord['videos']['videoLeft'])) {
                            $glossData['videos']['left'] = $nmmRecord['videos']['videoLeft'];
                            $glossData['thumbnails']['left'] = preg_replace('/\.mp4$/i', '.jpg', $nmmRecord['videos']['videoLeft']);
                        }
                        
                        if (!empty($nmmRecord['videos']['videoCenter'])) {
                            $glossData['videos']['center'] = $nmmRecord['videos']['videoCenter'];
                            $glossData['thumbnails']['center'] = preg_replace('/\.mp4$/i', '.jpg', $nmmRecord['videos']['videoCenter']);
                        }
                        
                        if (!empty($nmmRecord['videos']['videoRight'])) {
                            $glossData['videos']['right'] = $nmmRecord['videos']['videoRight'];
                            $glossData['thumbnails']['right'] = preg_replace('/\.mp4$/i', '.jpg', $nmmRecord['videos']['videoRight']);
                        }
                    }
                }
            }
            
            // Add this gloss data to the collection
            $glossVideosData[] = $glossData;
        }
        
        // Determine overall data source
        $overallDataSource = null;
        if ($hasFormData && $hasNmmData) {
            $overallDataSource = 'mixed';
        } elseif ($hasFormData) {
            $overallDataSource = 'form_data';
        } elseif ($hasNmmData) {
            $overallDataSource = 'nmm_data';
        }
        
        return [
            'glossVideosData' => $glossVideosData,
            'formDataIds' => $allFormDataIds, 
            'nmmIds' => $allNmmIds,
            'dataSource' => $overallDataSource
        ];
    }
}
