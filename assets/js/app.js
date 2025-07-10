/**
 * Logistic CRM - Main Application JavaScript
 * 
 * Main application logic and initialization
 */

class LogisticCRM {
    constructor() {
        this.config = {
            apiBase: 'api',
            version: '1.0.0',
            sessionTimeout: 24 * 60 * 60 * 1000, // 24 hours
            refreshInterval: 30 * 1000, // 30 seconds
            maxRetries: 3,
            retryDelay: 1000
        };
        
        this.state = {
            user: null,
            currentPage: 'dashboard',
            isAuthenticated: false,
            license: null,
            notifications: [],
            sidebarCollapsed: false,
            filters: {},
            cache: new Map(),
            requestQueue: []
        };
        
        this.init();
    }
    
    /**
     * Initialize the application
     */
    async init() {
        try {
            // Show loading screen
            this.showLoadingScreen();
            
            // Check if user is already authenticated
            const isAuthenticated = await this.checkAuthentication();
            
            if (isAuthenticated) {
                await this.initializeApp();
            } else {
                this.showLoginScreen();
            }
            
        } catch (error) {
            console.error('Application initialization error:', error);
            this.showError('Chyba při inicializaci aplikace');
        } finally {
            this.hideLoadingScreen();
        }
    }
    
    /**
     * Show loading screen
     */
    showLoadingScreen() {
        const loadingScreen = document.getElementById('loading-screen');
        if (loadingScreen) {
            loadingScreen.classList.remove('hidden');
        }
    }
    
    /**
     * Hide loading screen
     */
    hideLoadingScreen() {
        const loadingScreen = document.getElementById('loading-screen');
        if (loadingScreen) {
            setTimeout(() => {
                loadingScreen.classList.add('hidden');
            }, 500);
        }
    }
    
    /**
     * Show login screen
     */
    showLoginScreen() {
        const loginScreen = document.getElementById('login-screen');
        const mainApp = document.getElementById('main-app');
        
        if (loginScreen) loginScreen.style.display = 'flex';
        if (mainApp) mainApp.style.display = 'none';
        
        this.initializeLoginForm();
    }
    
    /**
     * Show main application
     */
    showMainApp() {
        const loginScreen = document.getElementById('login-screen');
        const mainApp = document.getElementById('main-app');
        
        if (loginScreen) loginScreen.style.display = 'none';
        if (mainApp) mainApp.style.display = 'block';
        
        this.initializeMainApp();
    }
    
    /**
     * Initialize login form
     */
    initializeLoginForm() {
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.handleLogin(e);
            });
        }
    }
    
    /**
     * Handle login form submission
     */
    async handleLogin(event) {
        const form = event.target;
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        
        try {
            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Přihlašování...';
            
            const response = await this.apiCall('POST', 'login', {
                email: formData.get('email'),
                password: formData.get('password')
            });
            
            if (response.success) {
                this.state.user = response.user;
                this.state.isAuthenticated = true;
                this.state.license = response.license;
                
                // Store session info
                localStorage.setItem('user', JSON.stringify(response.user));
                localStorage.setItem('csrf_token', response.session.csrf_token);
                
                this.showToast('Přihlášení úspěšné', 'success');
                await this.initializeApp();
                
            } else {
                throw new Error(response.message || 'Přihlášení se nezdařilo');
            }
            
        } catch (error) {
            console.error('Login error:', error);
            this.showError(error.message || 'Chyba při přihlašování');
            
        } finally {
            // Re-enable submit button
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Přihlásit se';
        }
    }
    
    /**
     * Check if user is authenticated
     */
    async checkAuthentication() {
        try {
            const user = localStorage.getItem('user');
            if (!user) return false;
            
            this.state.user = JSON.parse(user);
            
            // Validate session with server
            const response = await this.apiCall('GET', 'auth/validate');
            
            if (response.success) {
                this.state.isAuthenticated = true;
                this.state.license = response.license;
                return true;
            }
            
            return false;
            
        } catch (error) {
            console.error('Authentication check error:', error);
            this.clearSession();
            return false;
        }
    }
    
    /**
     * Initialize main application
     */
    async initializeApp() {
        try {
            this.showMainApp();
            
            // Initialize UI components
            this.initializeNavigation();
            this.initializeSidebar();
            this.initializeUserMenu();
            this.initializeNotifications();
            this.initializeSearch();
            
            // Load initial data
            await this.loadDashboardData();
            
            // Start periodic updates
            this.startPeriodicUpdates();
            
            // Update license status
            this.updateLicenseStatus();
            
        } catch (error) {
            console.error('App initialization error:', error);
            this.showError('Chyba při načítání aplikace');
        }
    }
    
    /**
     * Initialize navigation
     */
    initializeNavigation() {
        const menuItems = document.querySelectorAll('.menu-item');
        
        menuItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const page = item.dataset.page;
                if (page) {
                    this.showPage(page);
                }
            });
        });
    }
    
    /**
     * Initialize sidebar
     */
    initializeSidebar() {
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                this.toggleSidebar();
            });
        }
        
        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });
    }
    
    /**
     * Toggle sidebar
     */
    toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('main-content');
        
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('show');
        } else {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            this.state.sidebarCollapsed = !this.state.sidebarCollapsed;
        }
    }
    
    /**
     * Initialize user menu
     */
    initializeUserMenu() {
        const userBtn = document.querySelector('.user-btn');
        const userDropdown = document.querySelector('.user-dropdown');
        const userName = document.querySelector('.user-name');
        const userAvatar = document.querySelector('.user-avatar');
        
        if (this.state.user) {
            if (userName) userName.textContent = this.state.user.full_name;
            if (userAvatar && this.state.user.avatar_url) {
                userAvatar.src = this.state.user.avatar_url;
            }
        }
        
        if (userBtn) {
            userBtn.addEventListener('click', () => {
                userDropdown.classList.toggle('show');
            });
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!userBtn.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });
    }
    
    /**
     * Initialize notifications
     */
    initializeNotifications() {
        this.loadNotifications();
        
        const notificationBtn = document.querySelector('.notification-btn');
        if (notificationBtn) {
            notificationBtn.addEventListener('click', () => {
                this.showNotificationDropdown();
            });
        }
    }
    
    /**
     * Initialize search
     */
    initializeSearch() {
        const searchInput = document.getElementById('global-search');
        if (searchInput) {
            let searchTimeout;
            
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.performSearch(e.target.value);
                }, 300);
            });
        }
    }
    
    /**
     * Show page
     */
    showPage(pageName) {
        // Hide all pages
        const pages = document.querySelectorAll('.page');
        pages.forEach(page => page.classList.remove('active'));
        
        // Show requested page
        const targetPage = document.getElementById(pageName + '-page');
        if (targetPage) {
            targetPage.classList.add('active');
            this.state.currentPage = pageName;
            
            // Update active menu item
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => {
                item.classList.remove('active');
                if (item.dataset.page === pageName) {
                    item.classList.add('active');
                }
            });
            
            // Load page data
            this.loadPageData(pageName);
        }
    }
    
    /**
     * Load page data
     */
    async loadPageData(pageName) {
        try {
            switch (pageName) {
                case 'dashboard':
                    await this.loadDashboardData();
                    break;
                case 'calendar':
                    await this.loadCalendarData();
                    break;
                case 'bookings':
                    await this.loadBookingsData();
                    break;
                case 'warehouses':
                    await this.loadWarehousesData();
                    break;
                case 'vehicles':
                    await this.loadVehiclesData();
                    break;
                case 'drivers':
                    await this.loadUsersData('driver');
                    break;
                case 'users':
                    await this.loadUsersData();
                    break;
                case 'reports':
                    await this.loadReportsData();
                    break;
                default:
                    console.warn('Unknown page:', pageName);
            }
        } catch (error) {
            console.error('Error loading page data:', error);
            this.showError('Chyba při načítání dat');
        }
    }
    
    /**
     * Load dashboard data
     */
    async loadDashboardData() {
        try {
            const [stats, todaySlots, upcomingBookings, warehouseUtilization] = await Promise.all([
                this.apiCall('GET', 'dashboard/stats'),
                this.apiCall('GET', 'slots/today'),
                this.apiCall('GET', 'bookings/upcoming'),
                this.apiCall('GET', 'warehouses/utilization')
            ]);
            
            this.updateDashboardStats(stats);
            this.updateTodaySlots(todaySlots);
            this.updateUpcomingBookings(upcomingBookings);
            this.updateWarehouseUtilization(warehouseUtilization);
            
        } catch (error) {
            console.error('Dashboard data loading error:', error);
            this.showError('Chyba při načítání dashboard dat');
        }
    }
    
    /**
     * Update dashboard statistics
     */
    updateDashboardStats(stats) {
        if (stats && stats.success) {
            const data = stats.data;
            
            const elements = {
                'pending-slots': data.pending_slots || 0,
                'completed-today': data.completed_today || 0,
                'active-drivers': data.active_drivers || 0,
                'warehouse-count': data.warehouse_count || 0
            };
            
            Object.entries(elements).forEach(([id, value]) => {
                const element = document.getElementById(id);
                if (element) {
                    this.animateNumber(element, value);
                }
            });
        }
    }
    
    /**
     * Animate number counter
     */
    animateNumber(element, targetValue) {
        const startValue = parseInt(element.textContent) || 0;
        const duration = 1000;
        const startTime = performance.now();
        
        const animate = (currentTime) => {
            const elapsedTime = currentTime - startTime;
            const progress = Math.min(elapsedTime / duration, 1);
            
            const currentValue = Math.floor(startValue + (targetValue - startValue) * progress);
            element.textContent = currentValue;
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };
        
        requestAnimationFrame(animate);
    }
    
    /**
     * Update license status
     */
    updateLicenseStatus() {
        const licenseStatus = document.getElementById('license-status');
        const licenseText = document.getElementById('license-text');
        
        if (this.state.license && licenseStatus && licenseText) {
            const expiresInDays = this.state.license.expires_in_days;
            
            licenseStatus.className = 'license-status';
            
            if (expiresInDays <= 3) {
                licenseStatus.classList.add('error');
                licenseText.textContent = `Licence vyprší za ${expiresInDays} dní!`;
            } else if (expiresInDays <= 7) {
                licenseStatus.classList.add('warning');
                licenseText.textContent = `Licence vyprší za ${expiresInDays} dní`;
            } else {
                licenseStatus.classList.add('success');
                licenseText.textContent = `Licence platná (${this.state.license.license_type})`;
            }
        }
    }
    
    /**
     * API call helper
     */
    async apiCall(method, endpoint, data = null, retries = 0) {
        try {
            const url = `${this.config.apiBase}/${endpoint}`;
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'include'
            };
            
            // Add CSRF token for non-GET requests
            const csrfToken = localStorage.getItem('csrf_token');
            if (csrfToken && method !== 'GET') {
                options.headers['X-CSRF-Token'] = csrfToken;
            }
            
            // Add request body for POST/PUT requests
            if (data && (method === 'POST' || method === 'PUT')) {
                options.body = JSON.stringify(data);
            }
            
            const response = await fetch(url, options);
            
            // Handle authentication errors
            if (response.status === 401) {
                this.handleAuthenticationError();
                return;
            }
            
            // Handle rate limiting
            if (response.status === 429) {
                const retryAfter = response.headers.get('Retry-After') || 60;
                throw new Error(`Rate limit exceeded. Try again in ${retryAfter} seconds.`);
            }
            
            const result = await response.json();
            
            // Handle API errors
            if (!response.ok) {
                throw new Error(result.message || `API Error: ${response.status}`);
            }
            
            return result;
            
        } catch (error) {
            // Retry logic for network errors
            if (retries < this.config.maxRetries && this.isNetworkError(error)) {
                await this.delay(this.config.retryDelay * Math.pow(2, retries));
                return this.apiCall(method, endpoint, data, retries + 1);
            }
            
            throw error;
        }
    }
    
    /**
     * Check if error is network-related
     */
    isNetworkError(error) {
        return error.name === 'TypeError' || error.message.includes('fetch');
    }
    
    /**
     * Delay helper
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    /**
     * Handle authentication error
     */
    handleAuthenticationError() {
        this.showError('Relace vypršela. Budete přesměrováni na přihlášení.');
        this.logout();
    }
    
    /**
     * Logout user
     */
    async logout() {
        try {
            await this.apiCall('POST', 'logout');
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            this.clearSession();
            this.showLoginScreen();
        }
    }
    
    /**
     * Clear session data
     */
    clearSession() {
        localStorage.removeItem('user');
        localStorage.removeItem('csrf_token');
        this.state.user = null;
        this.state.isAuthenticated = false;
        this.state.license = null;
    }
    
    /**
     * Show toast notification
     */
    showToast(message, type = 'info', duration = 5000) {
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const toastId = 'toast-' + Date.now();
        toast.id = toastId;
        
        toast.innerHTML = `
            <div class="toast-header">
                <span class="toast-title">${this.getToastTitle(type)}</span>
                <button class="toast-close" onclick="app.closeToast('${toastId}')">&times;</button>
            </div>
            <div class="toast-message">${message}</div>
        `;
        
        toastContainer.appendChild(toast);
        
        // Show toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);
        
        // Auto-hide toast
        setTimeout(() => {
            this.closeToast(toastId);
        }, duration);
    }
    
    /**
     * Get toast title based on type
     */
    getToastTitle(type) {
        const titles = {
            success: 'Úspěch',
            error: 'Chyba',
            warning: 'Varování',
            info: 'Informace'
        };
        return titles[type] || 'Oznámení';
    }
    
    /**
     * Close toast notification
     */
    closeToast(toastId) {
        const toast = document.getElementById(toastId);
        if (toast) {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }
    }
    
    /**
     * Show error message
     */
    showError(message) {
        this.showToast(message, 'error');
    }
    
    /**
     * Show success message
     */
    showSuccess(message) {
        this.showToast(message, 'success');
    }
    
    /**
     * Show warning message
     */
    showWarning(message) {
        this.showToast(message, 'warning');
    }
    
    /**
     * Start periodic updates
     */
    startPeriodicUpdates() {
        setInterval(() => {
            if (this.state.isAuthenticated) {
                this.refreshCurrentPageData();
                this.loadNotifications();
            }
        }, this.config.refreshInterval);
    }
    
    /**
     * Refresh current page data
     */
    refreshCurrentPageData() {
        if (this.state.currentPage) {
            this.loadPageData(this.state.currentPage);
        }
    }
    
    /**
     * Load notifications
     */
    async loadNotifications() {
        try {
            const response = await this.apiCall('GET', 'notifications');
            if (response.success) {
                this.state.notifications = response.notifications;
                this.updateNotificationCount();
            }
        } catch (error) {
            console.error('Notifications loading error:', error);
        }
    }
    
    /**
     * Update notification count
     */
    updateNotificationCount() {
        const notificationCount = document.querySelector('.notification-count');
        if (notificationCount) {
            const unreadCount = this.state.notifications.filter(n => !n.is_read).length;
            notificationCount.textContent = unreadCount;
            notificationCount.style.display = unreadCount > 0 ? 'flex' : 'none';
        }
    }
    
    /**
     * Perform search
     */
    async performSearch(query) {
        if (!query.trim()) return;
        
        try {
            const response = await this.apiCall('GET', `search?q=${encodeURIComponent(query)}`);
            if (response.success) {
                this.displaySearchResults(response.results);
            }
        } catch (error) {
            console.error('Search error:', error);
        }
    }
    
    /**
     * Display search results
     */
    displaySearchResults(results) {
        // TODO: Implement search results display
        console.log('Search results:', results);
    }
    
    /**
     * Format date for display
     */
    formatDate(dateString, format = 'datetime') {
        if (!dateString) return '';
        
        const date = new Date(dateString);
        const now = new Date();
        
        const options = {
            date: { year: 'numeric', month: 'numeric', day: 'numeric' },
            time: { hour: '2-digit', minute: '2-digit' },
            datetime: { 
                year: 'numeric', month: 'numeric', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
            }
        };
        
        return date.toLocaleString('cs-CZ', options[format] || options.datetime);
    }
    
    /**
     * Format currency
     */
    formatCurrency(amount, currency = 'CZK') {
        if (amount === null || amount === undefined) return '';
        
        return new Intl.NumberFormat('cs-CZ', {
            style: 'currency',
            currency: currency
        }).format(amount);
    }
    
    /**
     * Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    /**
     * Throttle function
     */
    throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
}

// Initialize application when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.app = new LogisticCRM();
});

// Global functions for HTML onclick handlers
window.showPage = (pageName) => {
    if (window.app) {
        window.app.showPage(pageName);
    }
};

window.logout = () => {
    if (window.app) {
        window.app.logout();
    }
};

window.showProfile = () => {
    // TODO: Implement profile modal
    console.log('Show profile modal');
};

window.showSettings = () => {
    // TODO: Implement settings modal
    console.log('Show settings modal');
};

window.showPasswordReset = () => {
    // TODO: Implement password reset modal
    console.log('Show password reset modal');
};

window.showRegistration = () => {
    // TODO: Implement registration modal
    console.log('Show registration modal');
};

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = LogisticCRM;
}