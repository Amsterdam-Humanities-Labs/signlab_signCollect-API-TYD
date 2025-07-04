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
    
    /**
     * Constructor
     * 
     * @param mysqli $conn Database connection
     * @param array $response Reference to response array
     * @param VideoService $videoService Video service
     * @param NmmService $nmmService NMM service
     */
    public function __construct($conn, &$response, $videoService, $nmmService)
    {
        $this->conn = $conn;
        $this->response = &$response;
        $this->videoService = $videoService;
        $this->nmmService = $nmmService;
    }
    
    /**
     * Search form data by glos field (LIKE 'keyword%')
     * 
     * @param string $keyword The keyword to search for in the glos field
     * @return array Array of form data matching the keyword
     */
    public function searchFormsByGlos($keyword)
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
                WHERE glos LIKE ? AND extern = '1' AND glosZichtbaar = '0'";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['form_glos_prepare_error'] = $this->conn->error;
            return $formData;
        }
        
        $stmt->bind_param("s", $searchPattern);
        
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
                
                // Check if this form has app_ready = 1 in matched_transcriptions
                $hasAppReady = false;
                $checkStmt = $this->conn->prepare("SELECT 1 FROM matched_transcriptions WHERE m_transcription = ? AND zOg IN ('glos', 'extern', 'labels') AND app_ready = 1 AND added = '1' LIMIT 1");
                if ($checkStmt) {
                    $checkStmt->bind_param("i", $formId);
                    if ($checkStmt->execute()) {
                        $checkResult = $checkStmt->get_result();
                        $hasAppReady = $checkResult->num_rows > 0;
                    }
                    $checkStmt->close();
                }
                
                // Skip this form if no app_ready videos
                if (!$hasAppReady) {
                    continue;
                }
                
                // Fetch videos from 'extern' source (for FormService)
                $videosExtern = $this->_fetchLastVideoSet((string)$formId, "zOg = 'extern'");
                $this->response['debug']['videos_extern_source_formid_' . $formId] = $videosExtern;

                // Fetch videos from 'labels' source (for FormService) 
                $videosLabels = $this->_fetchLastVideoSet((string)$formId, "zOg = 'labels'");
                $this->response['debug']['videos_labels_source_formid_' . $formId] = $videosLabels;

                // Combine videos, prioritizing 'extern' source, then 'labels' for each slot
                $finalVideos = [
                    'videoLeft'   => $videosExtern['videoLeft']   ?? $videosLabels['videoLeft']   ?? null,
                    'videoCenter' => $videosExtern['videoCenter'] ?? $videosLabels['videoCenter'] ?? null,
                    'videoRight'  => $videosExtern['videoRight']  ?? $videosLabels['videoRight']  ?? null,
                ];

                // Debug which source was effectively used for each video
                $this->response['debug']['final_video_source_left_formid_' . $formId] = $videosExtern['videoLeft'] ? 'extern' : ($videosLabels['videoLeft'] ? 'labels' : 'none');
                $this->response['debug']['final_video_source_center_formid_' . $formId] = $videosExtern['videoCenter'] ? 'extern' : ($videosLabels['videoCenter'] ? 'labels' : 'none');
                $this->response['debug']['final_video_source_right_formid_' . $formId] = $videosExtern['videoRight'] ? 'extern' : ($videosLabels['videoRight'] ? 'labels' : 'none');
                
                // Check if it has signbank_id, then fetch NMM data as well
                $nmm_data = [];
                if (!empty($formRecord['signbank'])) {
                    $nmm_data = $this->nmmService->getNmmDataForSignbankId($formRecord['signbank']);
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
        
        // Fetch videos
        $form['videos'] = $this->videoService->getVideosForEntity($id, 'glos');
        
        // Check if it has signbank_id, then fetch NMM data as well
        $nmm_data = [];
        if (!empty($form['signbank'])) {
            $nmm_data = $this->nmmService->getNmmDataForSignbankId($form['signbank']);
        }
        
        // Prepare the final response
        return [
            "id" => $form['id'] ?? null,
            "senses" => $form['senses'] ?? "",
            "signbank" => $form['signbank'] ?? "",
            "videos" => $form['videos'],
            "nmm_data" => $nmm_data
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
                WHERE m_transcription = ? AND " . $zOgCondition . " AND app_ready = 1 AND added = '1'";

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
