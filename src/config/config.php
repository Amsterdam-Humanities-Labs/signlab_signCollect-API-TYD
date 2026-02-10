<?php
/**
 * Configuration constants for the application
 */

// Environment setting (DEV or PROD)
define('ENVIRONMENT', getenv('API_ENVIRONMENT') ?: 'DEV');
define('IS_DEV', ENVIRONMENT === 'DEV');
define('IS_PROD', ENVIRONMENT === 'PROD');

// Media URLs
define('MEDIA_BASE_URL', 'https://media.signcollect.nl/');
define('SUBTITLE_BASE_URL', 'https://media.signcollect.nl/zin/eaf/zin/');

// Sentence status filter: only return sentences where all 4 statuses are "Klaar"
define('SENTENCE_STATUS_FILTER', "status = 'Klaar' AND status_annotatie = 'Klaar' AND status_video = 'Klaar' AND status_glos = 'Klaar'");
