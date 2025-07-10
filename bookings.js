/**
 * Bookings Management JavaScript
 * Logistic CRM System
 */

class BookingsManager {
    constructor() {
        this.apiBase = 'api';
        this.currentPage = 1;
        this.itemsPerPage = 20;
        this.currentFilters = {};
        this.selectedBookings = [];
        
        this.init();
    }
    
    init() {
        this.initializeEventListeners();
        this.loadBookings();
    }
    
    initializeEventListeners() {
        // Filter form
        const filterForm = document.getElementById('bookings-filter-form');
        if (filterForm) {
            filterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.applyFilters();
            });
        }
        
        // Search input
        const searchInput = document.getElementById('bookings-search');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.currentFilters.search = e.target.value;
                    this.loadBookings();
                }, 300);
            });
        }
        
        // Status filter
        const statusFilter = document.getElementById('status-filter');
        if (statusFilter) {
            statusFilter.addEventListener('change', (e) => {
                this.currentFilters.status = e.target.value;
                this.loadBookings();
            });
        }
        
        // Date filters
        const dateFromFilter = document.getElementById('date-from-filter');
        const dateToFilter = document.getElementById('date-to-filter');
        
        if (dateFromFilter) {
            dateFromFilter.addEventListener('change', (e) => {
                this.currentFilters.date_from = e.target.value;
                this.loadBookings();
            });
        }
        
        if (dateToFilter) {
            dateToFilter.addEventListener('change', (e) => {
                this.currentFilters.date_to = e.target.value;
                this.loadBookings();
            });
        }
        
        // Warehouse filter
        const warehouseFilter = document.getElementById('warehouse-filter');
        if (warehouseFilter) {
            warehouseFilter.addEventListener('change', (e) => {
                this.currentFilters.warehouse_id = e.target.value;
                this.loadBookings();
            });
        }
        
        // Bulk actions
        const bulkActionBtn = document.getElementById('bulk-action-btn');
        if (bulkActionBtn) {
            bulkActionBtn.addEventListener('click', () => {
                this.showBulkActionModal();
            });
        }
        
        // Export button
        const exportBtn = document.getElementById('export-bookings-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
                this.exportBookings();
            });
        }
        
        // Refresh button
        const refreshBtn = document.getElementById('refresh-bookings-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.loadBookings();
            });
        }
    }
    
    /**
     * Load bookings from API
     */
    async loadBookings() {
        try {
            this.showLoadingState();
            
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.itemsPerPage,
                ...this.currentFilters
            });
            
            const response = await fetch(`${this.apiBase}/bookings.php?${params}`, {
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.renderBookings(data.bookings);
                this.renderPagination(data.pagination);
                this.updateBookingCounts(data.pagination.total);
            } else {
                throw new Error(data.error || 'Failed to load bookings');
            }
            
        } catch (error) {
            console.error('Load bookings error:', error);
            this.showError('Chyba při načítání rezervací: ' + error.message);
            
        } finally {
            this.hideLoadingState();
        }
    }
    
    /**
     * Render bookings table
     */
    renderBookings(bookings) {
        const tbody = document.getElementById('bookings-table-body');
        if (!tbody) return;
        
        if (bookings.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center">
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>Žádné rezervace</h3>
                            <p>Nebyly nalezeny žádné rezervace odpovídající zadaným filtrům.</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }
        
        tbody.innerHTML = bookings.map(booking => this.renderBookingRow(booking)).join('');
        
        // Initialize row actions
        this.initializeRowActions();
    }
    
    /**
     * Render single booking row
     */
    renderBookingRow(booking) {
        const statusClass = this.getStatusClass(booking.status);
        const statusText = this.getStatusText(booking.status);
        
        return `
            <tr data-booking-id="${booking.id}">
                <td>
                    <input type="checkbox" class="booking-checkbox" value="${booking.id}">
                </td>
                <td>
                    <div class="booking-info">
                        <strong>${booking.booking_number}</strong>
                        <small class="text-muted">${booking.reference_number || ''}</small>
                    </div>
                </td>
                <td>
                    <div class="datetime-info">
                        <strong>${this.formatDate(booking.slot_date)}</strong>
                        <small>${booking.slot_time_start} - ${booking.slot_time_end}</small>
                    </div>
                </td>
                <td>
                    <div class="warehouse-info">
                        <strong>${booking.warehouse_name}</strong>
                        ${booking.zone_name ? `<small>${booking.zone_name}</small>` : ''}
                    </div>
                </td>
                <td>
                    <div class="driver-info">
                        ${booking.driver_name ? `
                            <strong>${booking.driver_name}</strong>
                            <small>${booking.vehicle_license || ''}</small>
                        ` : '<span class="text-muted">Nepřiřazen</span>'}
                    </div>
                </td>
                <td>
                    <span class="status-badge ${statusClass}">${statusText}</span>
                </td>
                <td>
                    <span class="booking-type">${this.getBookingTypeText(booking.booking_type)}</span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-outline" onclick="bookingsManager.viewBooking(${booking.id})" title="Zobrazit">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="bookingsManager.editBooking(${booking.id})" title="Upravit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline dropdown-toggle" data-toggle="dropdown" title="Více akcí">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                ${this.renderBookingActions(booking)}
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }
    
    /**
     * Render booking actions dropdown
     */
    renderBookingActions(booking) {
        const actions = [];
        
        // Check-in/Check-out actions
        if (booking.status === 'confirmed' && !booking.check_in_time) {
            actions.push(`<a href="#" onclick="bookingsManager.checkIn(${booking.id})"><i class="fas fa-sign-in-alt"></i> Check-in</a>`);
        }
        
        if (booking.check_in_time && !booking.check_out_time) {
            actions.push(`<a href="#" onclick="bookingsManager.checkOut(${booking.id})"><i class="fas fa-sign-out-alt"></i> Check-out</a>`);
        }
        
        // Status change actions
        if (booking.status === 'pending') {
            actions.push(`<a href="#" onclick="bookingsManager.approveBooking(${booking.id})"><i class="fas fa-check"></i> Schválit</a>`);
        }
        
        if (['pending', 'confirmed', 'approved'].includes(booking.status)) {
            actions.push(`<a href="#" onclick="bookingsManager.cancelBooking(${booking.id})"><i class="fas fa-times"></i> Zrušit</a>`);
        }
        
        // Other actions
        actions.push(`<a href="#" onclick="bookingsManager.duplicateBooking(${booking.id})"><i class="fas fa-copy"></i> Duplikovat</a>`);
        actions.push(`<a href="#" onclick="bookingsManager.showQRCode(${booking.id})"><i class="fas fa-qrcode"></i> QR kód</a>`);
        actions.push(`<a href="#" onclick="bookingsManager.printBooking(${booking.id})"><i class="fas fa-print"></i> Tisknout</a>`);
        
        if (actions.length > 0) {
            actions.push('<div class="dropdown-divider"></div>');
        }
        
        actions.push(`<a href="#" onclick="bookingsManager.deleteBooking(${booking.id})" class="text-danger"><i class="fas fa-trash"></i> Smazat</a>`);
        
        return actions.join('');
    }
    
    /**
     * Initialize row actions
     */
    initializeRowActions() {
        // Checkbox selection
        const checkboxes = document.querySelectorAll('.booking-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const bookingId = parseInt(e.target.value);
                if (e.target.checked) {
                    this.selectedBookings.push(bookingId);
                } else {
                    this.selectedBookings = this.selectedBookings.filter(id => id !== bookingId);
                }
                this.updateBulkActionButton();
            });
        });
        
        // Select all checkbox
        const selectAllCheckbox = document.getElementById('select-all-bookings');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                checkboxes.forEach(checkbox => {
                    checkbox.checked = e.target.checked;
                    const bookingId = parseInt(checkbox.value);
                    if (e.target.checked) {
                        if (!this.selectedBookings.includes(bookingId)) {
                            this.selectedBookings.push(bookingId);
                        }
                    } else {
                        this.selectedBookings = this.selectedBookings.filter(id => id !== bookingId);
                    }
                });
                this.updateBulkActionButton();
            });
        }
        
        // Dropdown toggles
        const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
        dropdownToggles.forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                const dropdown = toggle.nextElementSibling;
                dropdown.classList.toggle('show');
            });
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });
    }
    
    /**
     * Update bulk action button
     */
    updateBulkActionButton() {
        const bulkActionBtn = document.getElementById('bulk-action-btn');
        if (bulkActionBtn) {
            bulkActionBtn.disabled = this.selectedBookings.length === 0;
            bulkActionBtn.textContent = `Hromadné akce (${this.selectedBookings.length})`;
        }
    }
    
    /**
     * Render pagination
     */
    renderPagination(pagination) {
        const paginationContainer = document.getElementById('bookings-pagination');
        if (!paginationContainer) return;
        
        const { page, pages, total } = pagination;
        
        if (pages <= 1) {
            paginationContainer.innerHTML = '';
            return;
        }
        
        let paginationHTML = '<nav><ul class="pagination">';
        
        // Previous button
        if (page > 1) {
            paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="bookingsManager.changePage(${page - 1})">&laquo;</a></li>`;
        }
        
        // Page numbers
        const startPage = Math.max(1, page - 2);
        const endPage = Math.min(pages, page + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHTML += `<li class="page-item ${i === page ? 'active' : ''}">
                <a class="page-link" href="#" onclick="bookingsManager.changePage(${i})">${i}</a>
            </li>`;
        }
        
        // Next button
        if (page < pages) {
            paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="bookingsManager.changePage(${page + 1})">&raquo;</a></li>`;
        }
        
        paginationHTML += '</ul></nav>';
        
        // Add page info
        paginationHTML += `<div class="pagination-info">
            Zobrazeno ${((page - 1) * this.itemsPerPage) + 1} - ${Math.min(page * this.itemsPerPage, total)} z ${total} rezervací
        </div>`;
        
        paginationContainer.innerHTML = paginationHTML;
    }
    
    /**
     * Change page
     */
    changePage(page) {
        this.currentPage = page;
        this.loadBookings();
    }
    
    /**
     * Apply filters
     */
    applyFilters() {
        const form = document.getElementById('bookings-filter-form');
        if (!form) return;
        
        const formData = new FormData(form);
        this.currentFilters = {};
        
        for (const [key, value] of formData.entries()) {
            if (value) {
                this.currentFilters[key] = value;
            }
        }
        
        this.currentPage = 1;
        this.loadBookings();
    }
    
    /**
     * Clear filters
     */
    clearFilters() {
        this.currentFilters = {};
        this.currentPage = 1;
        
        // Reset filter form
        const form = document.getElementById('bookings-filter-form');
        if (form) {
            form.reset();
        }
        
        this.loadBookings();
    }
    
    /**
     * View booking details
     */
    async viewBooking(bookingId) {
        try {
            const response = await fetch(`${this.apiBase}/bookings.php?booking_id=${bookingId}`, {
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showBookingModal(data.booking, data.documents, 'view');
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('View booking error:', error);
            this.showError('Chyba při načítání rezervace: ' + error.message);
        }
    }
    
    /**
     * Edit booking
     */
    async editBooking(bookingId) {
        try {
            const response = await fetch(`${this.apiBase}/bookings.php?booking_id=${bookingId}`, {
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showBookingModal(data.booking, data.documents, 'edit');
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('Edit booking error:', error);
            this.showError('Chyba při načítání rezervace: ' + error.message);
        }
    }
    
    /**
     * Create new booking
     */
    createBooking() {
        this.showBookingModal(null, [], 'create');
    }
    
    /**
     * Check-in booking
     */
    async checkIn(bookingId) {
        try {
            const response = await fetch(`${this.apiBase}/bookings.php?action=checkin&booking_id=${bookingId}`, {
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Check-in byl úspěšný');
                this.loadBookings();
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('Check-in error:', error);
            this.showError('Chyba při check-in: ' + error.message);
        }
    }
    
    /**
     * Check-out booking
     */
    async checkOut(bookingId) {
        try {
            const response = await fetch(`${this.apiBase}/bookings.php?action=checkout&booking_id=${bookingId}`, {
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Check-out byl úspěšný');
                this.loadBookings();
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('Check-out error:', error);
            this.showError('Chyba při check-out: ' + error.message);
        }
    }
    
    /**
     * Approve booking
     */
    async approveBooking(bookingId) {
        if (!confirm('Opravdu chcete schválit tuto rezervaci?')) {
            return;
        }
        
        try {
            const response = await fetch(`${this.apiBase}/bookings.php`, {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    booking_id: bookingId,
                    action: 'approve'
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Rezervace byla schválena');
                this.loadBookings();
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('Approve booking error:', error);
            this.showError('Chyba při schvalování: ' + error.message);
        }
    }
    
    /**
     * Cancel booking
     */
    async cancelBooking(bookingId) {
        const reason = prompt('Důvod zrušení (volitelný):');
        if (reason === null) return; // User clicked cancel
        
        try {
            const response = await fetch(`${this.apiBase}/bookings.php`, {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    booking_id: bookingId,
                    action: 'cancel',
                    reason: reason
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Rezervace byla zrušena');
                this.loadBookings();
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('Cancel booking error:', error);
            this.showError('Chyba při rušení: ' + error.message);
        }
    }
    
    /**
     * Delete booking
     */
    async deleteBooking(bookingId) {
        if (!confirm('Opravdu chcete smazat tuto rezervaci?')) {
            return;
        }
        
        try {
            const response = await fetch(`${this.apiBase}/bookings.php?booking_id=${bookingId}`, {
                method: 'DELETE',
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Rezervace byla smazána');
                this.loadBookings();
            } else {
                throw new Error(data.error);
            }
            
        } catch (error) {
            console.error('Delete booking error:', error);
            this.showError('Chyba při mazání: ' + error.message);
        }
    }
    
    /**
     * Show QR code
     */
    showQRCode(bookingId) {
        // TODO: Implement QR code display
        console.log('Show QR code for booking:', bookingId);
    }
    
    /**
     * Print booking
     */
    printBooking(bookingId) {
        // TODO: Implement booking printing
        console.log('Print booking:', bookingId);
    }
    
    /**
     * Duplicate booking
     */
    duplicateBooking(bookingId) {
        // TODO: Implement booking duplication
        console.log('Duplicate booking:', bookingId);
    }
    
    /**
     * Export bookings
     */
    async exportBookings() {
        try {
            const params = new URLSearchParams({
                export: 'csv',
                ...this.currentFilters
            });
            
            const response = await fetch(`${this.apiBase}/bookings.php?${params}`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `bookings_${new Date().toISOString().split('T')[0]}.csv`;
                a.click();
                window.URL.revokeObjectURL(url);
            } else {
                throw new Error('Export failed');
            }
            
        } catch (error) {
            console.error('Export error:', error);
            this.showError('Chyba při exportu: ' + error.message);
        }
    }
    
    // Utility methods
    
    getStatusClass(status) {
        const classes = {
            'pending': 'status-pending',
            'approved': 'status-approved',
            'confirmed': 'status-confirmed',
            'in_progress': 'status-in-progress',
            'delayed': 'status-delayed',
            'completed': 'status-completed',
            'cancelled': 'status-cancelled'
        };
        return classes[status] || 'status-unknown';
    }
    
    getStatusText(status) {
        const texts = {
            'pending': 'Čeká',
            'approved': 'Schváleno',
            'confirmed': 'Potvrzeno',
            'in_progress': 'Probíhá',
            'delayed': 'Zpožděno',
            'completed': 'Dokončeno',
            'cancelled': 'Zrušeno'
        };
        return texts[status] || 'Neznámý';
    }
    
    getBookingTypeText(type) {
        const texts = {
            'loading': 'Nakládka',
            'unloading': 'Vykládka',
            'universal': 'Univerzální'
        };
        return texts[type] || 'Neznámý';
    }
    
    formatDate(dateString) {
        if (!dateString) return '';
        
        const date = new Date(dateString);
        return date.toLocaleDateString('cs-CZ');
    }
    
    formatDateTime(dateTimeString) {
        if (!dateTimeString) return '';
        
        const date = new Date(dateTimeString);
        return date.toLocaleString('cs-CZ');
    }
    
    showLoadingState() {
        const tbody = document.getElementById('bookings-table-body');
        if (tbody) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center">
                        <div class="loading-spinner">
                            <div class="spinner"></div>
                            <p>Načítání rezervací...</p>
                        </div>
                    </td>
                </tr>
            `;
        }
    }
    
    hideLoadingState() {
        // Loading state will be replaced by actual content
    }
    
    updateBookingCounts(total) {
        const countElement = document.getElementById('bookings-count');
        if (countElement) {
            countElement.textContent = total;
        }
    }
    
    showError(message) {
        if (window.app) {
            window.app.showError(message);
        } else {
            alert(message);
        }
    }
    
    showSuccess(message) {
        if (window.app) {
            window.app.showSuccess(message);
        } else {
            alert(message);
        }
    }
    
    showBookingModal(booking, documents, mode) {
        // TODO: Implement booking modal
        console.log('Show booking modal:', { booking, documents, mode });
    }
    
    showBulkActionModal() {
        // TODO: Implement bulk action modal
        console.log('Show bulk action modal for:', this.selectedBookings);
    }
}

// Initialize bookings manager when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('bookings-page')) {
        window.bookingsManager = new BookingsManager();
    }
});

// Global functions for HTML onclick handlers
window.createBooking = () => {
    if (window.bookingsManager) {
        window.bookingsManager.createBooking();
    }
};

window.applyFilters = () => {
    if (window.bookingsManager) {
        window.bookingsManager.applyFilters();
    }
};

window.clearFilters = () => {
    if (window.bookingsManager) {
        window.bookingsManager.clearFilters();
    }
};

window.exportBookings = () => {
    if (window.bookingsManager) {
        window.bookingsManager.exportBookings();
    }
};