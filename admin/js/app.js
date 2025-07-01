class VideoReviewApp {
    constructor() {
        this.currentPage = 1;
        this.isLoading = false;
        this.hasMore = true;
        this.filters = {
            status: 'all',
            theme: '',
            search: ''
        };
        this.selectedVideos = new Set();
        this.isAuthenticated = false;
        
        this.init();
    }
    
    init() {
        this.bindAuthEvents();
        this.checkAuthentication();
    }
    
    initApp() {
        this.bindEvents();
        this.loadThemes();
        this.loadStats();
        this.loadVideos();
        this.setupInfiniteScroll();
        this.loadUserInfo();
    }
    
    bindAuthEvents() {
        // Login form
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.login();
        });
        
        // Logout button
        document.getElementById('logout-btn').addEventListener('click', async () => {
            await this.logout();
        });
    }
    
    async checkAuthentication() {
        try {
            const response = await fetch('api.php?action=user_info');
            const data = await response.json();
            
            if (data.success) {
                this.isAuthenticated = true;
                this.showMainContent();
                this.initApp();
            } else {
                this.showLoginModal();
            }
        } catch (error) {
            console.error('Auth check failed:', error);
            this.showLoginModal();
        }
    }
    
    async login() {
        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        
        try {
            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('username', username);
            formData.append('password', password);
            
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.isAuthenticated = true;
                this.hideLoginError();
                this.showMainContent();
                this.initApp();
            } else {
                this.showLoginError(data.error || 'Login failed');
            }
        } catch (error) {
            console.error('Login failed:', error);
            this.showLoginError('Network error occurred');
        }
    }
    
    async logout() {
        try {
            const formData = new FormData();
            formData.append('action', 'logout');
            
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            
            this.isAuthenticated = false;
            this.showLoginModal();
            document.getElementById('login-form').reset();
        } catch (error) {
            console.error('Logout failed:', error);
        }
    }
    
    async loadUserInfo() {
        try {
            const response = await fetch('api.php?action=user_info');
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('username-display').textContent = `Welcome, ${data.data.username}`;
            }
        } catch (error) {
            console.error('Failed to load user info:', error);
        }
    }
    
    showLoginModal() {
        document.getElementById('login-modal').classList.remove('hidden');
        document.getElementById('main-content').classList.add('hidden');
    }
    
    showMainContent() {
        document.getElementById('login-modal').classList.add('hidden');
        document.getElementById('main-content').classList.remove('hidden');
    }
    
    showLoginError(message) {
        const errorDiv = document.getElementById('login-error');
        errorDiv.textContent = message;
        errorDiv.classList.remove('hidden');
    }
    
    hideLoginError() {
        document.getElementById('login-error').classList.add('hidden');
    }
    
    bindEvents() {
        // Filter events
        document.querySelectorAll('input[name="status"]').forEach(radio => {
            radio.addEventListener('change', () => {
                this.filters.status = radio.value;
                this.resetAndLoad();
            });
        });
        
        document.getElementById('theme-filter').addEventListener('change', (e) => {
            this.filters.theme = e.target.value;
            this.resetAndLoad();
        });
        
        let searchTimeout;
        document.getElementById('search-input').addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.filters.search = e.target.value;
                this.resetAndLoad();
            }, 500);
        });
        
        // Bulk action events
        document.getElementById('select-all').addEventListener('click', () => {
            this.toggleSelectAll();
        });
        
        document.getElementById('bulk-ready').addEventListener('click', () => {
            this.bulkUpdateStatus(1);
        });
        
        document.getElementById('bulk-not-ready').addEventListener('click', () => {
            this.bulkUpdateStatus(0);
        });
        
        document.getElementById('clear-filters').addEventListener('click', () => {
            this.clearFilters();
        });
        
        // Modal events
        document.getElementById('close-modal').addEventListener('click', () => {
            this.closeModal();
        });
        
        document.getElementById('video-modal').addEventListener('click', (e) => {
            if (e.target.id === 'video-modal') {
                this.closeModal();
            }
        });
    }
    
    setupInfiniteScroll() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && this.hasMore && !this.isLoading) {
                    this.loadMoreVideos();
                }
            });
        }, {
            rootMargin: '100px'
        });
        
        // Create a sentinel element at the bottom
        const sentinel = document.createElement('div');
        sentinel.id = 'scroll-sentinel';
        sentinel.className = 'h-10';
        document.getElementById('video-grid').parentNode.appendChild(sentinel);
        observer.observe(sentinel);
    }
    
    async loadThemes() {
        try {
            const response = await fetch('api.php?action=themes');
            const data = await response.json();
            
            if (data.success) {
                const select = document.getElementById('theme-filter');
                data.data.forEach(theme => {
                    const option = document.createElement('option');
                    option.value = theme;
                    option.textContent = theme;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load themes:', error);
        }
    }
    
    async loadStats() {
        try {
            const response = await fetch('api.php?action=stats');
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('total-count').textContent = data.data.total;
                document.getElementById('ready-count').textContent = data.data.ready;
                document.getElementById('not-ready-count').textContent = data.data.not_ready;
            }
        } catch (error) {
            console.error('Failed to load stats:', error);
        }
    }
    
    async loadVideos() {
        if (this.isLoading) return;
        
        this.isLoading = true;
        this.showLoading();
        
        try {
            const params = new URLSearchParams({
                action: 'list',
                page: this.currentPage,
                limit: 50,
                ...this.filters
            });
            
            const response = await fetch(`api.php?${params}`);
            
            if (response.status === 401) {
                this.showLoginModal();
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.renderVideos(data.data.videos);
                this.hasMore = data.data.pagination.has_more;
                
                if (data.data.videos.length === 0 && this.currentPage === 1) {
                    this.showNoResults();
                }
            } else {
                this.showError(data.error || 'Failed to load videos');
            }
        } catch (error) {
            console.error('Failed to load videos:', error);
            if (error.status === 401) {
                this.showLoginModal();
                return;
            }
            this.showError('Network error occurred');
        } finally {
            this.isLoading = false;
            this.hideLoading();
        }
    }
    
    async loadMoreVideos() {
        if (!this.hasMore || this.isLoading) return;
        
        this.currentPage++;
        this.showLoadMore();
        await this.loadVideos();
        this.hideLoadMore();
    }
    
    renderVideos(videos) {
        const grid = document.getElementById('video-grid');
        const template = document.getElementById('video-card-template');
        
        if (!grid || !template) {
            console.error('Grid or template element not found');
            return;
        }
        
        videos.forEach(video => {
            try {
                const card = template.content.cloneNode(true);
                
                // Set data attributes
                const videoCard = card.querySelector('.video-card');
                const videoSelect = card.querySelector('.video-select');
                
                if (videoCard) videoCard.dataset.id = video.id;
                if (videoSelect) videoSelect.dataset.id = video.id;
            
                // Set content
                const glosName = card.querySelector('.glos-name');
                const themeElement = card.querySelector('.theme');
                
                if (glosName) glosName.textContent = video.glos || 'Unknown';
                if (themeElement) themeElement.textContent = video.theme || 'Unknown';
                
                // Set senses
                const sensesContainer = card.querySelector('.senses');
                if (sensesContainer && video.senses && video.senses.length > 0) {
                    video.senses.forEach(sense => {
                        const tag = document.createElement('span');
                        tag.className = 'inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-1 mb-1';
                        tag.textContent = sense;
                        sensesContainer.appendChild(tag);
                    });
                }
            
                // Set status
                const statusBadge = card.querySelector('.status-badge');
                const toggleBtn = card.querySelector('.toggle-ready');
                
                if (statusBadge && toggleBtn) {
                    if (video.tyd_app_ready === 1) {
                        statusBadge.textContent = 'Ready';
                        statusBadge.className = 'status-badge px-2 py-1 text-xs font-medium rounded-full status-ready';
                        toggleBtn.textContent = 'Mark Not Ready';
                        toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-warning hover:bg-yellow-600 text-white';
                    } else {
                        statusBadge.textContent = 'Not Ready';
                        statusBadge.className = 'status-badge px-2 py-1 text-xs font-medium rounded-full status-not-ready';
                        toggleBtn.textContent = 'Mark Ready';
                        toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-success hover:bg-green-600 text-white';
                    }
                }
            
                // Set video thumbnail
                const videoElement = card.querySelector('video');
                if (videoElement) {
                    let videoUrl = null;
                    if (video.videos.videoCenter) {
                        videoUrl = video.videos.videoCenter;
                    } else if (video.videos.videoLeft) {
                        videoUrl = video.videos.videoLeft;
                    } else if (video.videos.videoRight) {
                        videoUrl = video.videos.videoRight;
                    }
                    
                    if (videoUrl) {
                        videoElement.src = videoUrl;
                        const posterUrl = videoUrl.replace('.mp4', '.jpg');
                        videoElement.poster = posterUrl;
                        
                        // Add error handler for poster image
                        videoElement.addEventListener('error', () => {
                            // If poster fails to load, fall back to video
                            if (videoElement.poster === posterUrl) {
                                videoElement.poster = videoUrl;
                            }
                        });
                    }
                }
            
                // Bind events
                this.bindCardEvents(card, video);
                
                grid.appendChild(card);
            } catch (error) {
                console.error('Error rendering video card:', error, video);
            }
        });
    }
    
    bindCardEvents(card, video) {
        // Hover to play video
        const videoElement = card.querySelector('video');
        if (videoElement && videoElement.src) {
            videoElement.addEventListener('mouseenter', () => {
                try {
                    videoElement.play().catch(error => {
                        console.error('Video play failed:', error);
                    });
                } catch (error) {
                    console.error('Video control error:', error);
                }
            });
            
            videoElement.addEventListener('mouseleave', () => {
                try {
                    videoElement.pause();
                    videoElement.currentTime = 0; // Reset to beginning
                } catch (error) {
                    console.error('Video control error:', error);
                }
            });
        }
        
        // Toggle status
        const toggleBtn = card.querySelector('.toggle-ready');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', async () => {
                const newStatus = video.tyd_app_ready === 1 ? 0 : 1;
                console.log('Toggle clicked, current status:', video.tyd_app_ready, 'new status:', newStatus);
                
                // Disable button during API call
                toggleBtn.disabled = true;
                toggleBtn.textContent = 'Updating...';
                
                try {
                    console.log('Calling updateVideoStatus...');
                    await this.updateVideoStatus(video.id, newStatus);
                    console.log('API call successful, updating UI...');
                    
                    // Only update local state and UI if API call succeeds
                    video.tyd_app_ready = newStatus;
                    
                    // Update status badge
                    const statusBadge = card.querySelector('.status-badge');
                    if (statusBadge) {
                        if (newStatus === 1) {
                            statusBadge.textContent = 'Ready';
                            statusBadge.className = 'status-badge px-2 py-1 text-xs font-medium rounded-full status-ready';
                        } else {
                            statusBadge.textContent = 'Not Ready';
                            statusBadge.className = 'status-badge px-2 py-1 text-xs font-medium rounded-full status-not-ready';
                        }
                    }
                    
                    // Update toggle button
                    if (newStatus === 1) {
                        toggleBtn.textContent = 'Mark Not Ready';
                        toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-warning hover:bg-yellow-600 text-white';
                    } else {
                        toggleBtn.textContent = 'Mark Ready';
                        toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-success hover:bg-green-600 text-white';
                    }
                    
                    this.loadStats(); // Refresh stats
                    console.log('UI updated successfully');
                } catch (error) {
                    console.error('Toggle status error:', error);
                    // Re-enable button on error - revert to original state
                    if (video.tyd_app_ready === 1) {
                        toggleBtn.textContent = 'Mark Not Ready';
                        toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-warning hover:bg-yellow-600 text-white';
                    } else {
                        toggleBtn.textContent = 'Mark Ready';
                        toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-success hover:bg-green-600 text-white';
                    }
                }
                
                // Always re-enable button (moved outside try-catch)
                console.log('Re-enabling button...');
                console.log('Button disabled before:', toggleBtn.disabled);
                console.log('Button text before re-enable:', toggleBtn.textContent);
                toggleBtn.disabled = false;
                console.log('Button disabled after:', toggleBtn.disabled);
            });
        }
        
        // View all videos
        const viewBtn = card.querySelector('.view-videos');
        if (viewBtn) {
            viewBtn.addEventListener('click', () => {
                this.showVideoModal(video);
            });
        }
        
        // Selection checkbox
        const checkbox = card.querySelector('.video-select');
        if (checkbox) {
            checkbox.addEventListener('change', (e) => {
                if (e.target.checked) {
                    this.selectedVideos.add(video.id);
                } else {
                    this.selectedVideos.delete(video.id);
                }
            });
        }
    }
    
    updateCardStatus(card, status) {
        console.log('updateCardStatus called with status:', status);
        const statusBadge = card.querySelector('.status-badge');
        const toggleBtn = card.querySelector('.toggle-ready');
        
        console.log('statusBadge found:', !!statusBadge);
        console.log('toggleBtn found:', !!toggleBtn);
        
        if (statusBadge && toggleBtn) {
            console.log('Updating UI elements...');
            if (status === 1) {
                console.log('Setting to Ready state');
                statusBadge.textContent = 'Ready';
                statusBadge.className = 'status-badge px-2 py-1 text-xs font-medium rounded-full status-ready';
                toggleBtn.textContent = 'Mark Not Ready';
                toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-warning hover:bg-yellow-600 text-white';
                console.log('Button text set to:', toggleBtn.textContent);
            } else {
                console.log('Setting to Not Ready state');
                statusBadge.textContent = 'Not Ready';
                statusBadge.className = 'status-badge px-2 py-1 text-xs font-medium rounded-full status-not-ready';
                toggleBtn.textContent = 'Mark Ready';
                toggleBtn.className = 'toggle-ready flex-1 px-3 py-2 text-sm font-medium rounded-md transition-colors bg-success hover:bg-green-600 text-white';
                console.log('Button text set to:', toggleBtn.textContent);
            }
        } else {
            console.log('Elements not found - statusBadge:', !!statusBadge, 'toggleBtn:', !!toggleBtn);
        }
    }
    
    async updateVideoStatus(id, status) {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', status);
        
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to update status');
        }
        
        this.showMessage('Status updated successfully', 'success');
        return data;
    }
    
    async bulkUpdateStatus(status) {
        if (this.selectedVideos.size === 0) {
            this.showMessage('No videos selected', 'warning');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'bulk_update');
            formData.append('status', status);
            
            this.selectedVideos.forEach(id => {
                formData.append('ids[]', id);
            });
            
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showMessage(`Updated ${data.data.updated_count} videos`, 'success');
                this.selectedVideos.clear();
                this.resetAndLoad();
            } else {
                throw new Error(data.error || 'Failed to bulk update');
            }
        } catch (error) {
            console.error('Failed to bulk update:', error);
            this.showMessage('Failed to bulk update', 'error');
        }
    }
    
    toggleSelectAll() {
        const checkboxes = document.querySelectorAll('.video-select');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        
        checkboxes.forEach(cb => {
            cb.checked = !allChecked;
            const id = parseInt(cb.dataset.id);
            if (!allChecked) {
                this.selectedVideos.add(id);
            } else {
                this.selectedVideos.delete(id);
            }
        });
    }
    
    showVideoModal(video) {
        const modal = document.getElementById('video-modal');
        const title = document.getElementById('modal-title');
        const videosContainer = document.getElementById('modal-videos');
        
        if (!modal || !title || !videosContainer) {
            console.error('Modal elements not found');
            return;
        }
        
        title.textContent = `${video.glos || 'Unknown'} - All Camera Angles`;
        videosContainer.innerHTML = '';
        
        const angles = [
            { key: 'videoLeft', name: 'Left Camera' },
            { key: 'videoCenter', name: 'Center Camera' },
            { key: 'videoRight', name: 'Right Camera' }
        ];
        
        angles.forEach(angle => {
            if (video.videos[angle.key]) {
                const videoDiv = document.createElement('div');
                videoDiv.className = 'text-center';
                videoDiv.innerHTML = `
                    <h4 class="text-sm font-medium text-gray-700 mb-2">${angle.name}</h4>
                    <video class="w-full rounded-lg" controls>
                        <source src="${video.videos[angle.key]}" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                `;
                videosContainer.appendChild(videoDiv);
            }
        });
        
        modal.classList.remove('hidden');
    }
    
    closeModal() {
        const modal = document.getElementById('video-modal');
        const videos = modal.querySelectorAll('video');
        videos.forEach(video => video.pause());
        modal.classList.add('hidden');
    }
    
    clearFilters() {
        this.filters = { status: 'all', theme: '', search: '' };
        document.querySelector('input[name="status"][value="all"]').checked = true;
        document.getElementById('theme-filter').value = '';
        document.getElementById('search-input').value = '';
        this.resetAndLoad();
    }
    
    resetAndLoad() {
        this.currentPage = 1;
        this.hasMore = true;
        this.selectedVideos.clear();
        document.getElementById('video-grid').innerHTML = '';
        document.getElementById('no-results').classList.add('hidden');
        this.loadVideos();
    }
    
    showLoading() {
        document.getElementById('loading').classList.remove('hidden');
    }
    
    hideLoading() {
        document.getElementById('loading').classList.add('hidden');
    }
    
    showLoadMore() {
        document.getElementById('load-more').classList.remove('hidden');
    }
    
    hideLoadMore() {
        document.getElementById('load-more').classList.add('hidden');
    }
    
    showNoResults() {
        document.getElementById('no-results').classList.remove('hidden');
    }
    
    showMessage(message, type = 'info') {
        // Create a simple toast notification
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-4 py-2 rounded-lg text-white z-50 ${
            type === 'success' ? 'bg-green-500' :
            type === 'error' ? 'bg-red-500' :
            type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500'
        }`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
    
    showError(message) {
        this.showMessage(message, 'error');
    }
}

// Initialize the app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new VideoReviewApp();
});