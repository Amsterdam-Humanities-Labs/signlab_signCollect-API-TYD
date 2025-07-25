# Video Coverage Analysis Script

## Overview
`test_gloss_video_coverage.php` analyzes what percentage of sentence glosses have available videos from `form_data` and `nmm_data` sources, outputting comprehensive results in JSON format.

## Usage

### Basic Usage (JSON output)
```bash
php test_gloss_video_coverage.php
```

### CLI Options
- `--verbose`: Show progress messages during execution
- `--validate-urls`: Actually test video URLs for HTTP 200 responses (slower but more accurate)

```bash
# With verbose output
php test_gloss_video_coverage.php --verbose

# With URL validation
php test_gloss_video_coverage.php --validate-urls

# Both options combined
php test_gloss_video_coverage.php --verbose --validate-urls
```

### Web Usage
Access via HTTP with query parameters:
```
http://your-domain/api/test_gloss_video_coverage.php?verbose=1&validate_urls=1
```

## Output Format

### JSON Response Structure
```json
{
  "success": true,
  "timestamp": "2025-01-25 12:00:00",
  "summary": {
    "total_sentences": 549,
    "total_unique_glosses": 647,
    "glosses_with_videos": 170,
    "coverage_percentage": 26.28,
    "form_data_coverage": 6.34,
    "nmm_fallback_coverage": 19.94
  },
  "detailed_stats": {
    "by_source": {
      "form_data_only": 41,
      "nmm_data_only": 129,
      "both_sources": 0,
      "no_videos": 477
    },
    "sentences_without_glosses": 2,
    "url_validation": {
      "tested": 285,
      "failed": 12,
      "success_rate": 95.79
    }
  },
  "missing_glosses": ["PT-1hand", "SCHOON", "ONDERBROEK", ...],
  "execution_time": 15.32
}
```

## Key Metrics Explained

### Coverage Statistics
- **total_sentences**: Number of sentences with glosses data
- **total_unique_glosses**: Unique glosses found across all sentences
- **glosses_with_videos**: Glosses that have at least one video source
- **coverage_percentage**: Overall coverage (glosses_with_videos / total_unique_glosses * 100)

### Source Breakdown
- **form_data_coverage**: Percentage covered by form_data table (primary source)
- **nmm_fallback_coverage**: Percentage covered by nmm_data table (fallback source)
- **form_data_only**: Glosses only available in form_data
- **nmm_data_only**: Glosses only available in nmm_data (fallback used)
- **both_sources**: Glosses available in both sources
- **no_videos**: Glosses with no video coverage

### URL Validation (optional)
When `--validate-urls` is enabled:
- **tested**: Total video URLs tested
- **failed**: URLs that returned non-200 HTTP responses
- **success_rate**: Percentage of URLs that are accessible

## Video Source Priority

The script follows the same logic as the existing system:

1. **Primary**: Check `form_data` table with exact gloss matching
   - Links to `matched_transcriptions` where `zOg IN ('glos', 'extern')` and `app_ready = 1`
   - Generates video URLs by converting `.wav` files to `.mp4`

2. **Fallback**: If no form_data videos found, check `nmm_data` table
   - Uses `NmmService` with exact gloss matching
   - Provides direct video URLs for left/center/right cameras

## Performance Notes

- **Basic run**: ~2-5 seconds for typical database size
- **With URL validation**: ~30-60 seconds (depends on network and server response times)
- **Memory usage**: Minimal, processes data in streaming fashion
- **Database impact**: Read-only operations, no modifications

## Use Cases

1. **Quality Assurance**: Identify glosses without video coverage
2. **Content Planning**: Prioritize video creation for missing glosses
3. **System Monitoring**: Track coverage improvements over time
4. **API Integration**: Use JSON output in dashboards or monitoring tools

## Integration with Existing System

The script reuses existing service classes and follows the same logic as:
- `SentenceService::getVideoDataForSentence()`
- `NmmService::searchNmmByGlos()`
- Video URL generation patterns from `test_video_urls_complete.php`

## Sample Output Interpretation

With 26.28% coverage (170/647 glosses):
- Primary source (form_data): 6.34% coverage
- Fallback source (nmm_data): 19.94% coverage
- 477 glosses (73.72%) have no video coverage

This indicates heavy reliance on the NMM fallback system and significant opportunity for content creation.