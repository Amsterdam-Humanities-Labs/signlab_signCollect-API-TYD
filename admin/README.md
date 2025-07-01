# Video Review Admin Interface

A fast, responsive admin interface for reviewing sign language videos and marking them ready for publication.

## Features

### Video Management
- **Grid View**: Display 50 videos per page with thumbnails
- **Infinite Scroll**: Automatically loads next page when scrolling down
- **Status Toggle**: Quick buttons to mark videos as ready/not ready for publication
- **Bulk Actions**: Select multiple videos and update their status at once

### Filtering & Search
- **Publication Status**: Filter by ready/not ready for app
- **Theme Filter**: Filter videos by theme/category
- **Search**: Quick search by glos name
- **Clear Filters**: Reset all filters with one click

### Video Preview
- **Thumbnail Display**: Shows video thumbnails for quick identification
- **Play Controls**: Click to preview videos inline
- **Multi-Angle View**: Modal popup to view all camera angles (left/center/right)

### Statistics Dashboard
- Real-time counts of total, ready, and not ready videos
- Updates automatically when status changes

## Technical Details

### Backend (api.php)
- **Authentication**: Database-based authentication using users table
- **Password Security**: Supports both legacy plain text and modern bcrypt hashing
- **Session Management**: PHP sessions for maintaining login state
- **Database**: Uses form_data table with `tyd_app_ready` column
- **Filtering**: Supports status, theme, and search filters
- **Pagination**: Efficient LIMIT/OFFSET queries for performance
- **Bulk Operations**: Safe bulk status updates with prepared statements

### Frontend
- **Framework**: Vanilla JavaScript with Tailwind CSS
- **Performance**: Lazy loading and infinite scroll for fast loading
- **Responsive**: Works on desktop, tablet, and mobile devices
- **Accessibility**: Keyboard navigation and screen reader support

### Data Source
- **Table**: form_data with `extern = '1'` condition
- **Video URLs**: Generated from matched_transcriptions table
- **Status Field**: `tyd_app_ready` (INT: 0 = not ready, 1 = ready)
- **Video Types**: Supports left, center, and right camera angles

## Setup Instructions

1. **Database Setup**: Run the provided `migrate.sql` script to add `tyd_app_ready` column
2. **User Access**: Use any existing user from the `users` table for login
3. **Password Migration**: Plain text passwords are automatically hashed on first login
4. **Permissions**: Web server needs read/write access to admin directory and PHP session storage

## Usage

### Login
1. **Access Interface**: Navigate to `https://api.signcollect.nl/admin/`
2. **Login**: Use any username from the users table (e.g., gomer, casper, ulrika, ellen, dalene)
3. **Password**: Use the corresponding password from the database
4. **Session**: Login persists until browser is closed

### Basic Operations
1. **View Videos**: Grid automatically loads first 50 videos after login
2. **Filter**: Use sidebar filters to narrow down results
3. **Toggle Status**: Click "Mark Ready" or "Mark Not Ready" buttons
4. **Search**: Type in search box to find specific glosses

### Bulk Operations
1. **Select Videos**: Check boxes on videos you want to update
2. **Select All**: Click "Select All Visible" to select current page
3. **Bulk Update**: Use "Mark Selected Ready/Not Ready" buttons
4. **Clear Selection**: Selections clear when changing filters

### Video Preview
1. **Thumbnail**: Click play button on thumbnail to preview
2. **Full View**: Click "View All" to see all camera angles
3. **Modal Controls**: Each video has its own controls in modal

## API Endpoints

- `GET api.php?action=list` - Get paginated video list with filters
- `POST api.php action=update_status` - Update single video status
- `POST api.php action=bulk_update` - Bulk update multiple videos
- `GET api.php?action=themes` - Get available themes for filtering
- `GET api.php?action=stats` - Get statistics (total, ready, not ready)

## Performance Optimizations

- **Infinite Scroll**: Only loads 50 videos at a time
- **Lazy Loading**: Video thumbnails load as needed
- **Debounced Search**: Search waits 500ms after typing stops
- **Efficient Queries**: Uses DISTINCT and proper JOINs
- **Client Caching**: Themes and stats cached in browser

## Security Features

- **Database Authentication**: User credentials stored in users table
- **Password Hashing**: Automatic migration from plain text to bcrypt
- **SQL Injection Protection**: All queries use prepared statements
- **Input Validation**: Server-side validation of all parameters
- **Session Management**: PHP sessions track authentication state
- **Auto-logout**: Sessions expire when browser is closed

## Browser Support

- **Modern Browsers**: Chrome, Firefox, Safari, Edge (latest versions)
- **Video Support**: HTML5 video required for playback
- **JavaScript**: ES6+ features used (classes, async/await)
- **CSS**: Tailwind CSS via CDN for styling