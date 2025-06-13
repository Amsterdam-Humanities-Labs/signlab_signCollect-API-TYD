<?php
/**
 * Service for handling NMM data
 */
class NmmService
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
     * Get NMM data by ID
     * 
     * @param int $id NMM ID
     * @return array NMM data
     * @throws Exception If NMM not found
     */
    public function getNmmById($id)
    {
        // Fetch NMM data directly - selecting only necessary columns
        $stmt = $this->conn->prepare("SELECT id, signbank_id, glos, zelfopname, type, thema FROM nmm_data WHERE id = ?");
        if (!$stmt) {
            $this->response['debug']['nmm_prepare_error'] = $this->conn->error;
            throw new Exception('Prepare statement failed: ' . $this->conn->error);
        }
        
        $stmt->bind_param("i", $id); // Assuming id is an integer
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_execute_error'] = $stmt->error;
            throw new Exception('Execute statement failed: ' . $stmt->error);
        }
        
        $nmmResult = $stmt->get_result();
        $stmt->close();
        
        if ($nmmResult->num_rows === 0) {
            throw new Exception('NMM data not found with ID: ' . $id);
        }
        
        $nmm = $nmmResult->fetch_assoc();
        
        // Fetch videos from 'nmm' source
        $videosNMM = $this->_fetchLastVideoSet((string)$id, "zOg LIKE '%nmm%'");
        $this->response['debug']['videos_nmm_source_id_' . $id] = $videosNMM;

        // Fetch videos from 'extern' source
        $videosExtern = $this->_fetchLastVideoSet((string)$id, "zOg = 'extern'");
        $this->response['debug']['videos_extern_source_id_' . $id] = $videosExtern;

        // Combine videos, prioritizing 'nmm' source, then 'extern' for each slot
        $finalVideos = [
            'videoLeft'   => $videosNMM['videoLeft']   ?? $videosExtern['videoLeft']   ?? null,
            'videoCenter' => $videosNMM['videoCenter'] ?? $videosExtern['videoCenter'] ?? null,
            'videoRight'  => $videosNMM['videoRight']  ?? $videosExtern['videoRight']  ?? null,
        ];

        // Debug which source was effectively used for each video
        $this->response['debug']['final_video_source_left_id_' . $id] = $videosNMM['videoLeft'] ? 'nmm' : ($videosExtern['videoLeft'] ? 'extern' : 'none');
        $this->response['debug']['final_video_source_center_id_' . $id] = $videosNMM['videoCenter'] ? 'nmm' : ($videosExtern['videoCenter'] ? 'extern' : 'none');
        $this->response['debug']['final_video_source_right_id_' . $id] = $videosNMM['videoRight'] ? 'nmm' : ($videosExtern['videoRight'] ? 'extern' : 'none');
        
        $nmm['videos'] = $finalVideos;
        return $nmm;
    }
    
    /**
     * Get NMM data by signbank ID
     * 
     * @param string $signbankId Signbank ID
     * @return array NMM data
     */
    public function getNmmDataForSignbankId($signbankId)
    {
        $nmmData = [];
        
        // Query nmm_data table for records with matching signbank_id
        $sql = "SELECT id, signbank_id, glos, zelfopname, type, thema FROM nmm_data WHERE signbank_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['nmm_prepare_error'] = $this->conn->error;
            return $nmmData;
        }
        
        $stmt->bind_param("s", $signbankId);
        
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_execute_error'] = $stmt->error;
            $stmt->close();
            return $nmmData;
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        // Process each NMM record
        if ($result->num_rows > 0) {
            while ($nmmRow = $result->fetch_assoc()) {
                $nmmId = $nmmRow['id']; // This is nmm_data.id
                $nmmRecord = $nmmRow;
                
                // Fetch videos from 'nmm' source
                $videosNMM = $this->_fetchLastVideoSet((string)$nmmId, "zOg LIKE '%nmm%'");
                $this->response['debug']['videos_nmm_source_nmmid_' . $nmmId] = $videosNMM;

                // Fetch videos from 'extern' source
                $videosExtern = $this->_fetchLastVideoSet((string)$nmmId, "zOg = 'extern'");
                $this->response['debug']['videos_extern_source_nmmid_' . $nmmId] = $videosExtern;

                // Combine videos, prioritizing 'nmm' source, then 'extern' for each slot
                $finalVideos = [
                    'videoLeft'   => $videosNMM['videoLeft']   ?? $videosExtern['videoLeft']   ?? null,
                    'videoCenter' => $videosNMM['videoCenter'] ?? $videosExtern['videoCenter'] ?? null,
                    'videoRight'  => $videosNMM['videoRight']  ?? $videosExtern['videoRight']  ?? null,
                ];

                // Debug which source was effectively used for each video
                $this->response['debug']['final_video_source_left_nmmid_' . $nmmId] = $videosNMM['videoLeft'] ? 'nmm' : ($videosExtern['videoLeft'] ? 'extern' : 'none');
                $this->response['debug']['final_video_source_center_nmmid_' . $nmmId] = $videosNMM['videoCenter'] ? 'nmm' : ($videosExtern['videoCenter'] ? 'extern' : 'none');
                $this->response['debug']['final_video_source_right_nmmid_' . $nmmId] = $videosNMM['videoRight'] ? 'nmm' : ($videosExtern['videoRight'] ? 'extern' : 'none');
                
                $nmmRecord['videos'] = $finalVideos;
                $nmmData[] = $nmmRecord;
            }
        }
        
        return $nmmData;
    }
    
    /**
     * Search NMM data by glos field (LIKE 'keyword%')
     * 
     * @param string $keyword The keyword to search for in the glos field
     * @return array Array of NMM data matching the keyword
     */
    public function searchNmmByGlos($keyword)
    {
        $nmmData = [];
        $searchPattern = $keyword . '%';
        
        // Fetch NMM data matching the glos pattern
        // Corrected SQL to select only existing columns based on provided schema
        $sql = "SELECT id, signbank_id, glos, zelfopname, type, thema FROM nmm_data WHERE glos LIKE ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->response['debug']['nmm_glos_prepare_error'] = $this->conn->error;
            // Optionally, throw an exception or return empty array based on desired error handling
            // For now, returning empty array if prepare fails, consistent with some parts of existing code
            return $nmmData;
        }
        
        $stmt->bind_param("s", $searchPattern);
        
        if (!$stmt->execute()) {
            $this->response['debug']['nmm_glos_execute_error'] = $stmt->error;
            $stmt->close();
            return $nmmData; // Return empty if execute fails
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        if ($result->num_rows > 0) {
            while ($nmmRow = $result->fetch_assoc()) {
                $nmmId = $nmmRow['id']; // This is the ID from nmm_data
                $nmmRecord = $nmmRow;
                
                // Fetch videos from 'nmm' source
                $videosNMM = $this->_fetchLastVideoSet((string)$nmmId, "zOg LIKE '%nmm%'");
                $this->response['debug']['videos_nmm_source_search_nmmid_' . $nmmId] = $videosNMM;

                // Fetch videos from 'extern' source
                $videosExtern = $this->_fetchLastVideoSet((string)$nmmId, "zOg = 'extern'");
                $this->response['debug']['videos_extern_source_search_nmmid_' . $nmmId] = $videosExtern;

                // Combine videos, prioritizing 'nmm' source, then 'extern' for each slot
                $finalVideos = [
                    'videoLeft'   => $videosNMM['videoLeft']   ?? $videosExtern['videoLeft']   ?? null,
                    'videoCenter' => $videosNMM['videoCenter'] ?? $videosExtern['videoCenter'] ?? null,
                    'videoRight'  => $videosNMM['videoRight']  ?? $videosExtern['videoRight']  ?? null,
                ];
                
                // Debug which source was effectively used for each video
                $this->response['debug']['final_video_source_left_search_nmmid_' . $nmmId] = $videosNMM['videoLeft'] ? 'nmm' : ($videosExtern['videoLeft'] ? 'extern' : 'none');
                $this->response['debug']['final_video_source_center_search_nmmid_' . $nmmId] = $videosNMM['videoCenter'] ? 'nmm' : ($videosExtern['videoCenter'] ? 'extern' : 'none');
                $this->response['debug']['final_video_source_right_search_nmmid_' . $nmmId] = $videosNMM['videoRight'] ? 'nmm' : ($videosExtern['videoRight'] ? 'extern' : 'none');
                                
                $nmmRecord['videos'] = $finalVideos;
                $nmmData[] = $nmmRecord;
            }
        }
        
        return $nmmData;
    }

    /**
     * Private helper function to fetch the last video set for a given transcription ID and zOg condition.
     *
     * @param string $transcriptionId The m_transcription ID (from nmm_data.id).
     * @param string $zOgCondition The SQL condition for zOg (e.g., "zOg LIKE '%nmm%'" or "zOg = 'extern'").
     * @return array Associative array with 'videoLeft', 'videoCenter', 'videoRight'.
     */
    private function _fetchLastVideoSet($transcriptionId, $zOgCondition) {
        $videoFiles = ['videoLeft' => null, 'videoCenter' => null, 'videoRight' => null];
        
        // Ensure zOgCondition is one of the allowed patterns to prevent SQL injection.
        // This is a basic check; more robust validation might be needed if conditions become complex.
        $allowedConditions = ["zOg LIKE '%nmm%'", "zOg = 'extern'"];
        if (!in_array($zOgCondition, $allowedConditions, true)) {
            $this->response['debug']['video_fetch_invalid_condition_' . $transcriptionId] = $zOgCondition;
            return $videoFiles; // Return empty if condition is not allowed
        }

        $sql = "SELECT l_file, m_file, r_file FROM matched_transcriptions 
                WHERE m_transcription = ? AND " . $zOgCondition;

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            // m_transcription in matched_transcriptions is compared against nmm_data.id.
            // nmm_data.id is an INT. However, previous code consistently used "s" for binding.
            // We cast $transcriptionId to string earlier, so "s" is appropriate here.
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
                $this->response['debug']['video_fetch_execute_error_tid_' . $transcriptionId . '_cond_' . preg_replace("/[^a-zA-Z0-9_]/", "_", $zOgCondition)] = $stmt->error;
            }
            $stmt->close();
        } else {
            $this->response['debug']['video_fetch_prepare_error_tid_' . $transcriptionId . '_cond_' . preg_replace("/[^a-zA-Z0-9_]/", "_", $zOgCondition)] = $this->conn->error;
        }
        return $videoFiles;
    }
}
