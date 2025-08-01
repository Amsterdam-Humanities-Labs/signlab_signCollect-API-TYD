<?php
/**
 * Service for handling form data
 */
class FormService
{
    private $conn;
    private $response;
    private $videoService;
    private $nmmService;
    private $latestTranscriptionService;
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Video service
     * @param NmmService $nmmService NMM service
     * @param LatestTranscriptionService $latestTranscriptionService Latest transcription service
     */
    public function __construct($conn, &$response, $videoService, $nmmService, $latestTranscriptionService = null)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
        $this->nmmService = $nmmService;
        $this->latestTranscriptionService = $latestTranscriptionService;
    }
    
    /**
     * Get the LatestTranscriptionService instance
     * 
     * @return LatestTranscriptionService|null
     */
    public function getLatestTranscriptionService()
    {
        return $this->latestTranscriptionService;
    }
    
    /**
     * Search form data by glos field (LIKE 'keyword%')
     * 
     * @param string $keyword The keyword to search for in the glos field
     * @param int $limit Maximum number of results to return (default: 8)
     * @return array Array of form data matching the keyword
     */
    public function searchFormsByGlos($keyword, $limit = 8)
    {
        $formData = [];
        // Convert spaces to hyphens and uppercase for gloss search
        $glossKeyword = str_replace(' ', '-', $keyword);
        $glossKeyword = strtoupper($glossKeyword);
        $searchPattern = $glossKeyword . '%';
        
        // Fetch form data matching the glos pattern
        // Note: Column name varies between databases - 'theme' in test, 'thema' in production
        $sql = "SELECT id, CAST(IF(senses = '', '[]', senses) AS JSON) AS senses, signbank, 
                       IFNULL(thema, 'Unknown') as thema, glos 
                FROM form_data 
                WHERE glos LIKE ? AND extern = '1' AND glosZichtbaar = '0'
                LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['form_glos_prepare_error'] = $this->conn->error;
            return $formData;
        }
        
        $stmt->bind_param("si", $searchPattern, $limit);
        
        if (!$stmt->execute()) {
            $this->response['debug']['form_glos_execute_error'] = $stmt->error;
            $stmt->close();
            return $formData;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            while ($formRow = $result->fetch_assoc()) {
                $formId = $formRow['id'];
                $formRecord = $formRow;
                
                // Note: app_ready filtering has been disabled for ZIN Project endpoints
                // to support comprehensive annotation workflows
                
                // Use LatestTranscriptionService if available, otherwise fall back to old method
                if ($this->latestTranscriptionService && !empty($formRow['glos'])) {
                    $latestVideoData = $this->latestTranscriptionService->getLatestVideosForGloss($formRow['glos']);
                    $finalVideos = [
                        'videoLeft'   => $latestVideoData['videos']['videoLeft'],
                        'videoCenter' => $latestVideoData['videos']['videoCenter'],
                        'videoRight'  => $latestVideoData['videos']['videoRight'],
                    ];
                    $this->response['debug']['form_search_latest_service_used_for_id_' . $formId] = [
                        'gloss' => $formRow['glos'],
                        'source' => $latestVideoData['source'],
                        'matched_transcription_id' => $latestVideoData['matched_transcription_id']
                    ];
                } else {
                    // Fall back to old method
                    $videosExtern = $this->_fetchLastVideoSet((string)$formId, "zOg = 'extern'");
                    $this->response['debug']['videos_extern_source_formid_' . $formId] = $videosExtern;

                    $videosLabels = $this->_fetchLastVideoSet((string)$formId, "zOg = 'labels'");
                    $this->response['debug']['videos_labels_source_formid_' . $formId] = $videosLabels;

                    $finalVideos = [
                        'videoLeft'   => $videosExtern['videoLeft']   ?? $videosLabels['videoLeft']   ?? null,
                        'videoCenter' => $videosExtern['videoCenter'] ?? $videosLabels['videoCenter'] ?? null,
                        'videoRight'  => $videosExtern['videoRight']  ?? $videosLabels['videoRight']  ?? null,
                    ];
                    
                    $this->response['debug']['form_search_fallback_method_used_for_id_' . $formId] = true;
                }
                
                // Check if it has signbank_id, then fetch NMM data as well
                $nmm_data = [];
                if (!empty($formRecord['signbank'])) {
                    $nmm_data = $this->nmmService->getNmmDataForSignbankId($formRecord['signbank']);
                    
                    // If this form_data record has the latest matched_transcriptions for its gloss,
                    // replace NMM records with this form_data record in the nmm_data array
                    if ($this->latestTranscriptionService && !empty($formRow['glos'])) {
                        $latestMatch = $this->latestTranscriptionService->getLatestMatchedTranscriptionForGloss($formRow['glos']);
                        if ($latestMatch && $latestMatch['source']['type'] === 'form_data' && $latestMatch['source']['id'] == $formId) {
                            // This form_data record has the latest transcription, so include it in nmm_data
                            $formAsNmmData = [
                                'id' => $formId,
                                'signbank_id' => $formRecord['signbank'],
                                'glos' => $formRow['glos'],
                                'zelfopname' => '', // form_data doesn't have zelfopname
                                'type' => 'form_data', // Mark as form_data source
                                'thema' => $formRecord['thema'],
                                'videos' => $finalVideos
                            ];
                            
                            // Replace nmm_data array with this form_data record since it has the latest transcription
                            $nmm_data = [$formAsNmmData];
                            
                            $this->response['debug']['replaced_nmm_data_with_form_data_for_id_' . $formId] = [
                                'reason' => 'form_data has latest matched_transcription',
                                'matched_transcription_id' => $latestMatch['transcription']['id']
                            ];
                        }
                    }
                }
                
                $formRecord['videos'] = $finalVideos;
                $formRecord['nmm_data'] = $nmm_data;
                $formData[] = $formRecord;
            }
        }
        
        return $formData;
    }
    
    /**
     * Get form data by ID
     * 
     * @param int $id Form ID
     * @return array Form data
     * @throws Exception If form not found
     */
    public function getFormById($id)
    {
        // Fetch form data
        $stmt = $this->conn->prepare("SELECT id, senses, signbank, glos FROM form_data WHERE id = ? AND extern='1'");
        if (!$stmt) {
            $this->response['debug']['form_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            $this->response['debug']['form_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $formResult = $stmt->get_result();
        $stmt->close();
        
        if ($formResult->num_rows === 0) {
            throw new Exception('Form not found with ID: ' . $id);
        }
        
        $form = $formResult->fetch_assoc();
        
        // Use LatestTranscriptionService if available, otherwise fall back to VideoService
        if ($this->latestTranscriptionService && !empty($form['glos'])) {
            $latestVideoData = $this->latestTranscriptionService->getLatestVideosForGloss($form['glos']);
            $form['videos'] = [
                'videoLeft'   => $latestVideoData['videos']['videoLeft'],
                'videoCenter' => $latestVideoData['videos']['videoCenter'],
                'videoRight'  => $latestVideoData['videos']['videoRight'],
            ];
            $this->response['debug']['form_by_id_latest_service_used_for_id_' . $id] = [
                'gloss' => $form['glos'],
                'source' => $latestVideoData['source'],
                'matched_transcription_id' => $latestVideoData['matched_transcription_id']
            ];
        } else {
            // Fall back to VideoService
            $form['videos'] = $this->videoService->getVideosForEntity($id, 'glos');
            $this->response['debug']['form_by_id_fallback_method_used_for_id_' . $id] = true;
        }
        
        // Check if it has signbank_id, then fetch NMM data as well
        $nmm_data = [];
        if (!empty($form['signbank'])) {
            $nmm_data = $this->nmmService->getNmmDataForSignbankId($form['signbank']);
        }
        
        // If main videos are empty/null, try to use videos from nmm_data
        $finalVideos = $form['videos'];
        if (!empty($nmm_data) && is_array($nmm_data)) {
            foreach ($nmm_data as $nmmRecord) {
                if (!empty($nmmRecord['videos'])) {
                    // Replace null videos with nmm videos
                    if (empty($finalVideos['videoLeft']) && !empty($nmmRecord['videos']['videoLeft'])) {
                        $finalVideos['videoLeft'] = $nmmRecord['videos']['videoLeft'];
                    }
                    if (empty($finalVideos['videoCenter']) && !empty($nmmRecord['videos']['videoCenter'])) {
                        $finalVideos['videoCenter'] = $nmmRecord['videos']['videoCenter'];
                    }
                    if (empty($finalVideos['videoRight']) && !empty($nmmRecord['videos']['videoRight'])) {
                        $finalVideos['videoRight'] = $nmmRecord['videos']['videoRight'];
                    }
                }
            }
        }
        
        // Prepare the final response
        return [
            "id" => $form['id'] ?? null,
            "senses" => $form['senses'] ?? "",
            "signbank" => $form['signbank'] ?? "",
            "videos" => $finalVideos
        ];
    }

    /**
     * Private helper function to fetch the last video set for a given transcription ID and zOg condition.
     *
     * @param string $transcriptionId The m_transcription ID (from form_data.id).
     * @param string $zOgCondition The SQL condition for zOg (e.g., "zOg = 'extern'" or "zOg = 'labels'").
     * @return array Associative array with 'videoLeft', 'videoCenter', 'videoRight'.
     */
    private function _fetchLastVideoSet($transcriptionId, $zOgCondition) {
        $videoFiles = ['videoLeft' => null, 'videoCenter' => null, 'videoRight' => null];
        
        // Ensure zOgCondition is one of the allowed patterns to prevent SQL injection.
        $allowedConditions = ["zOg = 'extern'", "zOg = 'labels'"];
        if (!in_array($zOgCondition, $allowedConditions, true)) {
            $this->response['debug']['video_fetch_invalid_condition_formservice_' . $transcriptionId] = $zOgCondition;
            return $videoFiles; // Return empty if condition is not allowed
        }

        $sql = "SELECT l_file, m_file, r_file FROM matched_transcriptions 
                WHERE m_transcription = ? AND " . $zOgCondition . " AND added = '1'";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $transcriptionId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $lastVideoRow = null;
                    while ($row = $result->fetch_assoc()) {
                        $lastVideoRow = $row; // Get the last row
                    }
                    if ($lastVideoRow) {
                        $leftFile = !empty($lastVideoRow['l_file']) ? preg_replace('/\\.wav$/i', '.mp4', $lastVideoRow['l_file']) : null;
                        $centerFile = !empty($lastVideoRow['m_file']) ? preg_replace('/\\.wav$/i', '.mp4', $lastVideoRow['m_file']) : null;
                        $rightFile = !empty($lastVideoRow['r_file']) ? preg_replace('/\\.wav$/i', '.mp4', $lastVideoRow['r_file']) : null;
                        
                        // Assuming MEDIA_BASE_URL is a defined constant
                        $videoFiles['videoLeft'] = $leftFile ? MEDIA_BASE_URL . $leftFile : null;
                        $videoFiles['videoCenter'] = $centerFile ? MEDIA_BASE_URL . $centerFile : null;
                        $videoFiles['videoRight'] = $rightFile ? MEDIA_BASE_URL . $rightFile : null;
                    }
                }
            } else {
                $this->response['debug']['video_fetch_execute_error_formservice_tid_' . $transcriptionId . '_cond_' . preg_replace("/[^a-zA-Z0-9_]/", "_", $zOgCondition)] = $stmt->error;
            }
            $stmt->close();
        } else {
            $this->response['debug']['video_fetch_prepare_error_formservice_tid_' . $transcriptionId . '_cond_' . preg_replace("/[^a-zA-Z0-9_]/", "_", $zOgCondition)] = $this->conn->error;
        }
        return $videoFiles;
    }
}
