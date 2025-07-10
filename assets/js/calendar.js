/**
 * Modern Calendar for Slot Management
 * Logistic CRM System
 * 
 * Advanced calendar with drag & drop, color coding, and real-time updates
 */

class ModernCalendar {
    constructor() {
        this.currentView = 'week'; // day, week, month
        this.currentDate = new Date();
        this.selectedWarehouse = null;
        this.slots = [];
        this.bookings = [];
        this.draggedSlot = null;
        this.isLoading = false;
        
        // Color schemes for different statuses
        this.statusColors = {
            'available': '#10b981',      // Green
            'partial': '#f59e0b',        // Orange
            'full': '#ef4444',           // Red
            'blocked': '#6b7280',        // Gray
            'in_progress': '#3b82f6',    // Blue
            'completed': '#8b5cf6',      // Purple
            'delayed': '#f97316'         // Deep orange
        };
        
        this.bookingStatusColors = {
            'pending': '#fbbf24',        // Yellow
            'confirmed': '#10b981',      // Green
            'in_progress': '#3b82f6',    // Blue
            'completed': '#8b5cf6',      // Purple
            'cancelled': '#ef4444',      // Red
            'delayed': '#f97316'         // Orange
        };
        
        this.init();
    }
    
    init() {
        this.setupEventListeners();
        this.setupDragAndDrop();
        this.setupKeyboardShortcuts();
        this.loadWarehouses();
        this.renderCalendar();
        this.startAutoRefresh();
    }
    
    setupEventListeners() {
        // View toggle buttons
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.changeView(btn.dataset.view);
            });
        });
        
        // Navigation buttons
        document.getElementById('prev-period')?.addEventListener('click', () => this.navigatePrevious());
        document.getElementById('next-period')?.addEventListener('click', () => this.navigateNext());
        document.getElementById('today-btn')?.addEventListener('click', () => this.goToToday());
        
        // Warehouse selector
        document.getElementById('warehouse-selector')?.addEventListener('change', (e) => {
            this.selectedWarehouse = e.target.value || null;
            this.renderCalendar();
        });
        
        // Add slot button
        document.getElementById('add-slot-btn')?.addEventListener('click', () => this.showAddSlotModal());
        
        // Refresh button
        document.getElementById('refresh-calendar')?.addEventListener('click', () => this.refreshCalendar());
        
        // Filter toggles
        document.querySelectorAll('.filter-toggle').forEach(toggle => {
            toggle.addEventListener('change', () => this.applyFilters());
        });
        
        // Window resize handler
        window.addEventListener('resize', () => this.debounce(() => this.renderCalendar(), 250));
    }
    
    setupDragAndDrop() {
        document.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('slot-block')) {
                this.draggedSlot = JSON.parse(e.target.dataset.slot);
                e.dataTransfer.effectAllowed = 'move';
                e.target.classList.add('dragging');
            }
        });
        
        document.addEventListener('dragend', (e) => {
            if (e.target.classList.contains('slot-block')) {
                e.target.classList.remove('dragging');
                this.draggedSlot = null;
            }
        });
        
        document.addEventListener('dragover', (e) => {
            if (e.target.classList.contains('drop-zone')) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                e.target.classList.add('drag-over');
            }
        });
        
        document.addEventListener('dragleave', (e) => {
            if (e.target.classList.contains('drop-zone')) {
                e.target.classList.remove('drag-over');
            }
        });
        
        document.addEventListener('drop', (e) => {
            if (e.target.classList.contains('drop-zone')) {
                e.preventDefault();
                e.target.classList.remove('drag-over');
                this.handleSlotDrop(e.target, this.draggedSlot);
            }
        });
    }
    
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.navigatePrevious();
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.navigateNext();
                        break;
                    case 'Home':
                        e.preventDefault();
                        this.goToToday();
                        break;
                    case 'n':
                        e.preventDefault();
                        this.showAddSlotModal();
                        break;
                    case 'r':
                        e.preventDefault();
                        this.refreshCalendar();
                        break;
                }
            }
            
            // View switching
            if (e.altKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        this.changeView('day');
                        break;
                    case '2':
                        e.preventDefault();
                        this.changeView('week');
                        break;
                    case '3':
                        e.preventDefault();
                        this.changeView('month');
                        break;
                }
            }
        });
    }
    
    async loadWarehouses() {
        try {
            const response = await fetch('api/warehouses.php', {
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.renderWarehouseSelector(data.warehouses);
            }
        } catch (error) {
            console.error('Error loading warehouses:', error);
        }
    }
    
    renderWarehouseSelector(warehouses) {
        const selector = document.getElementById('warehouse-selector');
        if (!selector) return;
        
        selector.innerHTML = `
            <option value="">Všechny sklady</option>
            ${warehouses.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
        `;
    }
    
    changeView(view) {
        this.currentView = view;
        
        // Update active button
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
        });
        
        this.renderCalendar();
    }
    
    navigatePrevious() {
        switch(this.currentView) {
            case 'day':
                this.currentDate.setDate(this.currentDate.getDate() - 1);
                break;
            case 'week':
                this.currentDate.setDate(this.currentDate.getDate() - 7);
                break;
            case 'month':
                this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                break;
        }
        this.renderCalendar();
    }
    
    navigateNext() {
        switch(this.currentView) {
            case 'day':
                this.currentDate.setDate(this.currentDate.getDate() + 1);
                break;
            case 'week':
                this.currentDate.setDate(this.currentDate.getDate() + 7);
                break;
            case 'month':
                this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                break;
        }
        this.renderCalendar();
    }
    
    goToToday() {
        this.currentDate = new Date();
        this.renderCalendar();
    }
    
    async renderCalendar() {
        this.updateCalendarTitle();
        this.showLoading();
        
        try {
            await this.loadCalendarData();
            
            switch(this.currentView) {
                case 'day':
                    this.renderDayView();
                    break;
                case 'week':
                    this.renderWeekView();
                    break;
                case 'month':
                    this.renderMonthView();
                    break;
            }
        } catch (error) {
            console.error('Error rendering calendar:', error);
            this.showError('Chyba při načítání kalendáře');
        } finally {
            this.hideLoading();
        }
    }
    
    async loadCalendarData() {
        const { startDate, endDate } = this.getDateRange();
        
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate
        });
        
        if (this.selectedWarehouse) {
            params.append('warehouse_id', this.selectedWarehouse);
        }
        
        const response = await fetch(`api/slots.php?${params}`, {
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            this.slots = data.slots;
            await this.loadBookingsForSlots();
        } else {
            throw new Error(data.error || 'Failed to load calendar data');
        }
    }
    
    async loadBookingsForSlots() {
        if (this.slots.length === 0) return;
        
        const slotIds = this.slots.map(s => s.id);
        const params = new URLSearchParams({
            slot_ids: slotIds.join(',')
        });
        
        try {
            const response = await fetch(`api/bookings.php?${params}`, {
                credentials: 'include'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.bookings = data.bookings;
                this.groupBookingsBySlot();
            }
        } catch (error) {
            console.error('Error loading bookings:', error);
        }
    }
    
    groupBookingsBySlot() {
        const bookingsBySlot = {};
        
        this.bookings.forEach(booking => {
            const slotId = booking.time_slot_id;
            if (!bookingsBySlot[slotId]) {
                bookingsBySlot[slotId] = [];
            }
            bookingsBySlot[slotId].push(booking);
        });
        
        // Add bookings to slots
        this.slots.forEach(slot => {
            slot.bookings = bookingsBySlot[slot.id] || [];
        });
    }
    
    getDateRange() {
        const start = new Date(this.currentDate);
        const end = new Date(this.currentDate);
        
        switch(this.currentView) {
            case 'day':
                return {
                    startDate: this.formatDate(start),
                    endDate: this.formatDate(end)
                };
            case 'week':
                // Get Monday of current week
                const monday = new Date(start);
                monday.setDate(start.getDate() - start.getDay() + 1);
                const sunday = new Date(monday);
                sunday.setDate(monday.getDate() + 6);
                
                return {
                    startDate: this.formatDate(monday),
                    endDate: this.formatDate(sunday)
                };
            case 'month':
                const monthStart = new Date(start.getFullYear(), start.getMonth(), 1);
                const monthEnd = new Date(start.getFullYear(), start.getMonth() + 1, 0);
                
                return {
                    startDate: this.formatDate(monthStart),
                    endDate: this.formatDate(monthEnd)
                };
        }
    }
    
    renderDayView() {
        const container = document.getElementById('calendar-grid');
        if (!container) return;
        
        const daySlots = this.slots.filter(slot => 
            slot.slot_date === this.formatDate(this.currentDate)
        );
        
        // Group slots by hour
        const hourlySlots = {};
        daySlots.forEach(slot => {
            const hour = slot.slot_time_start.substring(0, 2);
            if (!hourlySlots[hour]) {
                hourlySlots[hour] = [];
            }
            hourlySlots[hour].push(slot);
        });
        
        // Generate time slots (6 AM to 10 PM)
        const timeSlots = [];
        for (let hour = 6; hour < 22; hour++) {
            timeSlots.push({
                hour: hour.toString().padStart(2, '0'),
                displayHour: hour > 12 ? `${hour - 12}:00 PM` : `${hour}:00 AM`,
                slots: hourlySlots[hour.toString().padStart(2, '0')] || []
            });
        }
        
        container.innerHTML = `
            <div class="day-view">
                <div class="day-header">
                    <h2>${this.formatDateFull(this.currentDate)}</h2>
                    <div class="day-summary">
                        <span class="slot-count">${daySlots.length} slotů</span>
                        <span class="booking-count">${this.bookings.length} rezervací</span>
                    </div>
                </div>
                
                <div class="time-grid">
                    ${timeSlots.map(timeSlot => `
                        <div class="time-slot">
                            <div class="time-header">
                                <span class="time-label">${timeSlot.displayHour}</span>
                            </div>
                            <div class="time-content drop-zone" data-hour="${timeSlot.hour}">
                                ${timeSlot.slots.map(slot => this.renderSlotBlock(slot)).join('')}
                                ${timeSlot.slots.length === 0 ? '<div class="empty-slot">Žádné sloty</div>' : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
        
        this.setupSlotInteractions();
    }
    
    renderWeekView() {
        const container = document.getElementById('calendar-grid');
        if (!container) return;
        
        const weekStart = new Date(this.currentDate);
        weekStart.setDate(this.currentDate.getDate() - this.currentDate.getDay() + 1);
        
        const weekDays = [];
        for (let i = 0; i < 7; i++) {
            const day = new Date(weekStart);
            day.setDate(weekStart.getDate() + i);
            weekDays.push(day);
        }
        
        // Group slots by day
        const dailySlots = {};
        this.slots.forEach(slot => {
            if (!dailySlots[slot.slot_date]) {
                dailySlots[slot.slot_date] = [];
            }
            dailySlots[slot.slot_date].push(slot);
        });
        
        container.innerHTML = `
            <div class="week-view">
                <div class="week-header">
                    <div class="time-column"></div>
                    ${weekDays.map(day => `
                        <div class="day-column">
                            <div class="day-label">
                                <span class="day-name">${this.getDayName(day)}</span>
                                <span class="day-date">${day.getDate()}</span>
                            </div>
                        </div>
                    `).join('')}
                </div>
                
                <div class="week-grid">
                    ${this.generateWeekTimeSlots(weekDays, dailySlots)}
                </div>
            </div>
        `;
        
        this.setupSlotInteractions();
    }
    
    generateWeekTimeSlots(weekDays, dailySlots) {
        let html = '';
        
        for (let hour = 6; hour < 22; hour++) {
            const hourStr = hour.toString().padStart(2, '0');
            const displayHour = hour > 12 ? `${hour - 12}:00 PM` : `${hour}:00 AM`;
            
            html += `
                <div class="time-row">
                    <div class="time-label">${displayHour}</div>
                    ${weekDays.map(day => {
                        const dayStr = this.formatDate(day);
                        const daySlots = dailySlots[dayStr] || [];
                        const hourSlots = daySlots.filter(slot => 
                            slot.slot_time_start.substring(0, 2) === hourStr
                        );
                        
                        return `
                            <div class="day-cell drop-zone" data-date="${dayStr}" data-hour="${hourStr}">
                                ${hourSlots.map(slot => this.renderSlotBlock(slot, true)).join('')}
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }
        
        return html;
    }
    
    renderMonthView() {
        const container = document.getElementById('calendar-grid');
        if (!container) return;
        
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startDate = new Date(firstDay);
        startDate.setDate(firstDay.getDate() - firstDay.getDay() + 1);
        
        const weeks = [];
        const currentWeek = [];
        
        for (let i = 0; i < 42; i++) {
            const date = new Date(startDate);
            date.setDate(startDate.getDate() + i);
            
            currentWeek.push(date);
            
            if (currentWeek.length === 7) {
                weeks.push([...currentWeek]);
                currentWeek.length = 0;
            }
        }
        
        // Group slots by date
        const dailySlots = {};
        this.slots.forEach(slot => {
            if (!dailySlots[slot.slot_date]) {
                dailySlots[slot.slot_date] = [];
            }
            dailySlots[slot.slot_date].push(slot);
        });
        
        container.innerHTML = `
            <div class="month-view">
                <div class="month-header">
                    <div class="weekday-labels">
                        ${['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'].map(day => 
                            `<div class="weekday-label">${day}</div>`
                        ).join('')}
                    </div>
                </div>
                
                <div class="month-grid">
                    ${weeks.map(week => `
                        <div class="week-row">
                            ${week.map(date => {
                                const dateStr = this.formatDate(date);
                                const daySlots = dailySlots[dateStr] || [];
                                const isCurrentMonth = date.getMonth() === month;
                                const isToday = this.isToday(date);
                                
                                return `
                                    <div class="month-cell drop-zone ${isCurrentMonth ? 'current-month' : 'other-month'} ${isToday ? 'today' : ''}" 
                                         data-date="${dateStr}">
                                        <div class="date-header">
                                            <span class="date-number">${date.getDate()}</span>
                                            <span class="slot-count">${daySlots.length}</span>
                                        </div>
                                        <div class="date-content">
                                            ${daySlots.slice(0, 3).map(slot => this.renderSlotBlock(slot, true)).join('')}
                                            ${daySlots.length > 3 ? `<div class="more-slots">+${daySlots.length - 3} dalších</div>` : ''}
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
        
        this.setupSlotInteractions();
    }
    
    renderSlotBlock(slot, compact = false) {
        const statusClass = this.getSlotStatusClass(slot);
        const bookingCount = slot.bookings ? slot.bookings.length : 0;
        const utilizationPercent = slot.capacity > 0 ? (bookingCount / slot.capacity) * 100 : 0;
        
        return `
            <div class="slot-block ${statusClass} ${compact ? 'compact' : ''}" 
                 data-slot='${JSON.stringify(slot)}'
                 draggable="true"
                 title="${this.getSlotTooltip(slot)}">
                
                <div class="slot-header">
                    <span class="slot-time">${slot.slot_time_start} - ${slot.slot_time_end}</span>
                    ${slot.is_blocked ? '<i class="fas fa-ban slot-blocked"></i>' : ''}
                </div>
                
                ${!compact ? `
                    <div class="slot-info">
                        <div class="slot-warehouse">${slot.warehouse_name}</div>
                        ${slot.zone_name ? `<div class="slot-zone">${slot.zone_name}</div>` : ''}
                        <div class="slot-capacity">
                            <span class="capacity-text">${bookingCount}/${slot.capacity}</span>
                            <div class="capacity-bar">
                                <div class="capacity-fill" style="width: ${utilizationPercent}%"></div>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                ${slot.bookings && slot.bookings.length > 0 ? `
                    <div class="slot-bookings">
                        ${slot.bookings.map(booking => `
                            <div class="booking-item" style="border-left-color: ${this.bookingStatusColors[booking.status]}">
                                <span class="booking-driver">${booking.driver_name || 'Nepřiřazen'}</span>
                                <span class="booking-status">${this.getBookingStatusText(booking.status)}</span>
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
                
                <div class="slot-actions">
                    <button class="slot-action-btn" onclick="calendar.editSlot(${slot.id})" title="Upravit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="slot-action-btn" onclick="calendar.addBooking(${slot.id})" title="Přidat rezervaci">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
        `;
    }
    
    setupSlotInteractions() {
        // Click handlers for slots
        document.querySelectorAll('.slot-block').forEach(block => {
            block.addEventListener('click', (e) => {
                if (!e.target.closest('.slot-actions')) {
                    const slot = JSON.parse(block.dataset.slot);
                    this.showSlotDetails(slot);
                }
            });
            
            block.addEventListener('dblclick', (e) => {
                const slot = JSON.parse(block.dataset.slot);
                this.editSlot(slot.id);
            });
        });
        
        // Context menu for slots
        document.querySelectorAll('.slot-block').forEach(block => {
            block.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                const slot = JSON.parse(block.dataset.slot);
                this.showSlotContextMenu(e, slot);
            });
        });
        
        // Empty slot creation
        document.querySelectorAll('.drop-zone').forEach(zone => {
            zone.addEventListener('dblclick', (e) => {
                if (e.target === zone) {
                    const date = zone.dataset.date;
                    const hour = zone.dataset.hour;
                    this.createSlotAt(date, hour);
                }
            });
        });
    }
    
    // Utility methods
    
    getSlotStatusClass(slot) {
        if (slot.is_blocked) return 'slot-blocked';
        
        const bookingCount = slot.bookings ? slot.bookings.length : 0;
        const utilizationRatio = slot.capacity > 0 ? bookingCount / slot.capacity : 0;
        
        if (utilizationRatio >= 1) return 'slot-full';
        if (utilizationRatio > 0) return 'slot-partial';
        return 'slot-available';
    }
    
    getSlotTooltip(slot) {
        const bookingCount = slot.bookings ? slot.bookings.length : 0;
        let tooltip = `${slot.warehouse_name}\n`;
        tooltip += `${slot.slot_time_start} - ${slot.slot_time_end}\n`;
        tooltip += `Kapacita: ${bookingCount}/${slot.capacity}\n`;
        
        if (slot.zone_name) {
            tooltip += `Zóna: ${slot.zone_name}\n`;
        }
        
        if (slot.is_blocked) {
            tooltip += `⚠️ Blokováno: ${slot.block_reason}`;
        }
        
        return tooltip;
    }
    
    getBookingStatusText(status) {
        const texts = {
            'pending': 'Čeká',
            'confirmed': 'Potvrzeno',
            'in_progress': 'Probíhá',
            'completed': 'Dokončeno',
            'cancelled': 'Zrušeno',
            'delayed': 'Zpožděno'
        };
        return texts[status] || status;
    }
    
    updateCalendarTitle() {
        const titleElement = document.getElementById('calendar-title');
        if (!titleElement) return;
        
        let title = '';
        switch(this.currentView) {
            case 'day':
                title = this.formatDateFull(this.currentDate);
                break;
            case 'week':
                const weekStart = new Date(this.currentDate);
                weekStart.setDate(this.currentDate.getDate() - this.currentDate.getDay() + 1);
                const weekEnd = new Date(weekStart);
                weekEnd.setDate(weekStart.getDate() + 6);
                title = `${this.formatDateShort(weekStart)} - ${this.formatDateShort(weekEnd)}`;
                break;
            case 'month':
                title = this.currentDate.toLocaleDateString('cs-CZ', { 
                    year: 'numeric', 
                    month: 'long' 
                });
                break;
        }
        
        titleElement.textContent = title;
    }
    
    formatDate(date) {
        return date.toISOString().split('T')[0];
    }
    
    formatDateFull(date) {
        return date.toLocaleDateString('cs-CZ', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
    
    formatDateShort(date) {
        return date.toLocaleDateString('cs-CZ', {
            day: 'numeric',
            month: 'short'
        });
    }
    
    getDayName(date) {
        return date.toLocaleDateString('cs-CZ', { weekday: 'short' });
    }
    
    isToday(date) {
        const today = new Date();
        return date.toDateString() === today.toDateString();
    }
    
    showLoading() {
        const container = document.getElementById('calendar-grid');
        if (container) {
            container.innerHTML = `
                <div class="loading-state">
                    <div class="spinner"></div>
                    <p>Načítání kalendáře...</p>
                </div>
            `;
        }
    }
    
    hideLoading() {
        // Will be replaced by actual content
    }
    
    showError(message) {
        const container = document.getElementById('calendar-grid');
        if (container) {
            container.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>${message}</p>
                    <button onclick="calendar.renderCalendar()">Zkusit znovu</button>
                </div>
            `;
        }
    }
    
    refreshCalendar() {
        this.renderCalendar();
        this.showToast('Kalendář byl obnovен', 'success');
    }
    
    startAutoRefresh() {
        // Auto-refresh every 30 seconds
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.renderCalendar();
            }
        }, 30000);
    }
    
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
    
    showToast(message, type = 'info') {
        if (window.app) {
            window.app.showToast(message, type);
        }
    }
    
    // Public methods for external use
    
    showSlotDetails(slot) {
        // TODO: Implement slot details modal
        console.log('Show slot details:', slot);
    }
    
    editSlot(slotId) {
        // TODO: Implement slot editing
        console.log('Edit slot:', slotId);
    }
    
    addBooking(slotId) {
        // TODO: Implement booking creation
        console.log('Add booking to slot:', slotId);
    }
    
    createSlotAt(date, hour) {
        // TODO: Implement slot creation at specific time
        console.log('Create slot at:', date, hour);
    }
    
    showSlotContextMenu(event, slot) {
        // TODO: Implement context menu
        console.log('Show context menu for slot:', slot);
    }
    
    handleSlotDrop(dropZone, slot) {
        if (!slot) return;
        
        const newDate = dropZone.dataset.date;
        const newHour = dropZone.dataset.hour;
        
        if (!newDate) return;
        
        // Calculate new time if hour is specified
        let newStartTime = slot.slot_time_start;
        let newEndTime = slot.slot_time_end;
        
        if (newHour) {
            const [oldHour, oldMinute] = slot.slot_time_start.split(':');
            const duration = this.calculateDuration(slot.slot_time_start, slot.slot_time_end);
            
            newStartTime = `${newHour}:${oldMinute}`;
            newEndTime = this.addMinutes(newStartTime, duration);
        }
        
        this.moveSlot(slot.id, newDate, newStartTime, newEndTime);
    }
    
    async moveSlot(slotId, newDate, newStartTime, newEndTime) {
        try {
            const response = await fetch('api/slots.php', {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    slot_id: slotId,
                    slot_date: newDate,
                    slot_time_start: newStartTime,
                    slot_time_end: newEndTime
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast('Slot byl úspěšně přesunut', 'success');
                this.renderCalendar();
            } else {
                throw new Error(data.error || 'Přesun slotu se nezdařil');
            }
            
        } catch (error) {
            console.error('Move slot error:', error);
            this.showToast('Chyba při přesunu slotu: ' + error.message, 'error');
        }
    }
    
    calculateDuration(startTime, endTime) {
        const [startHour, startMinute] = startTime.split(':').map(Number);
        const [endHour, endMinute] = endTime.split(':').map(Number);
        
        const startMinutes = startHour * 60 + startMinute;
        const endMinutes = endHour * 60 + endMinute;
        
        return endMinutes - startMinutes;
    }
    
    addMinutes(time, minutes) {
        const [hour, minute] = time.split(':').map(Number);
        const totalMinutes = hour * 60 + minute + minutes;
        
        const newHour = Math.floor(totalMinutes / 60);
        const newMinute = totalMinutes % 60;
        
        return `${newHour.toString().padStart(2, '0')}:${newMinute.toString().padStart(2, '0')}`;
    }
    
    applyFilters() {
        const filters = {};
        
        document.querySelectorAll('.filter-toggle').forEach(toggle => {
            if (toggle.checked) {
                filters[toggle.dataset.filter] = toggle.value;
            }
        });
        
        // Apply filters and re-render
        this.currentFilters = filters;
        this.renderCalendar();
    }
    
    showAddSlotModal() {
        // TODO: Implement add slot modal
        console.log('Show add slot modal');
    }
    
    // Export calendar data
    async exportCalendar(format = 'csv') {
        try {
            const { startDate, endDate } = this.getDateRange();
            const params = new URLSearchParams({
                start_date: startDate,
                end_date: endDate,
                export: format
            });
            
            if (this.selectedWarehouse) {
                params.append('warehouse_id', this.selectedWarehouse);
            }
            
            const response = await fetch(`api/slots.php?${params}`, {
                credentials: 'include'
            });
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `calendar_${startDate}_${endDate}.${format}`;
                a.click();
                window.URL.revokeObjectURL(url);
                
                this.showToast('Kalendář byl exportován', 'success');
            } else {
                throw new Error('Export failed');
            }
            
        } catch (error) {
            console.error('Export error:', error);
            this.showToast('Chyba při exportu: ' + error.message, 'error');
        }
    }
    
    // Print calendar
    printCalendar() {
        const printWindow = window.open('', '_blank');
        const calendarHTML = document.getElementById('calendar-grid').outerHTML;
        
        printWindow.document.write(`
            <html>
                <head>
                    <title>Kalendář slotů - ${this.formatDateFull(this.currentDate)}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .slot-block { border: 1px solid #ddd; margin: 2px; padding: 5px; }
                        .slot-available { background: #e8f5e8; }
                        .slot-partial { background: #fff3cd; }
                        .slot-full { background: #f8d7da; }
                        .slot-blocked { background: #e9ecef; }
                        @media print {
                            .slot-actions { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Kalendář slotů</h1>
                    <p>Datum: ${this.formatDateFull(this.currentDate)}</p>
                    <p>Pohled: ${this.currentView}</p>
                    ${calendarHTML}
                </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.print();
    }
    
    // Keyboard shortcuts help
    showKeyboardShortcuts() {
        const shortcuts = [
            { key: 'Ctrl + ←', action: 'Předchozí období' },
            { key: 'Ctrl + →', action: 'Následující období' },
            { key: 'Ctrl + Home', action: 'Dnešní datum' },
            { key: 'Ctrl + N', action: 'Nový slot' },
            { key: 'Ctrl + R', action: 'Obnovit kalendář' },
            { key: 'Alt + 1', action: 'Denní pohled' },
            { key: 'Alt + 2', action: 'Týdenní pohled' },
            { key: 'Alt + 3', action: 'Měsíční pohled' },
            { key: 'Double-click', action: 'Upravit slot / Vytvořit nový slot' },
            { key: 'Drag & Drop', action: 'Přesunout slot' },
            { key: 'Right-click', action: 'Kontextové menu' }
        ];
        
        const modal = document.createElement('div');
        modal.className = 'keyboard-shortcuts-modal';
        modal.innerHTML = `
            <div class="modal-overlay">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Klávesové zkratky</h3>
                        <button onclick="this.closest('.keyboard-shortcuts-modal').remove()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <table class="shortcuts-table">
                            ${shortcuts.map(shortcut => `
                                <tr>
                                    <td><kbd>${shortcut.key}</kbd></td>
                                    <td>${shortcut.action}</td>
                                </tr>
                            `).join('')}
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Close on escape
        document.addEventListener('keydown', function closeOnEscape(e) {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', closeOnEscape);
            }
        });
        
        // Close on click outside
        modal.addEventListener('click', (e) => {
            if (e.target === modal.querySelector('.modal-overlay')) {
                modal.remove();
            }
        });
    }
}

// Initialize calendar when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('calendar-page')) {
        window.calendar = new ModernCalendar();
    }
});

// Global functions for HTML onclick handlers
window.navigateCalendar = (direction) => {
    if (window.calendar) {
        switch(direction) {
            case 'prev':
                window.calendar.navigatePrevious();
                break;
            case 'next':
                window.calendar.navigateNext();
                break;
            case 'today':
                window.calendar.goToToday();
                break;
        }
    }
};

window.showNewSlotModal = () => {
    if (window.calendar) {
        window.calendar.showAddSlotModal();
    }
};

window.refreshTodaySlots = () => {
    if (window.calendar) {
        window.calendar.refreshCalendar();
    }
};

window.exportCalendar = (format = 'csv') => {
    if (window.calendar) {
        window.calendar.exportCalendar(format);
    }
};

window.printCalendar = () => {
    if (window.calendar) {
        window.calendar.printCalendar();
    }
};

window.showCalendarHelp = () => {
    if (window.calendar) {
        window.calendar.showKeyboardShortcuts();
    }
};