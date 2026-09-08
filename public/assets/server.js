/* Rotana server-backed application. All mutations require Laravel session + CSRF. */
'use strict';

let lookup = {}, me = {}, currentOrder = null, table = null, chart = null, modalSave = null, availableRoles = null, searchRequest = null, searchTimer = null, searchActiveIndex = -1;
let ui = window.rotanaUi || {};

const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const bdi = value => `<bdi>${esc(value ?? '—')}</bdi>`;
const money = value => (Number(value || 0) / 100).toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);
const can = permissionName => me.permissions?.includes(permissionName);
const timelineDate = value => {
    if (!value) return 'التوقيت غير متاح';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('ar-SA', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};
const api = (path, method = 'GET', data) => $.ajax({
    url: '/api/' + path,
    method,
    data: method === 'GET' ? data : JSON.stringify(data),
    contentType: 'application/json',
});
const statuses = {
    draft: 'مسودة',
    accountant: 'مراجعة المحاسب',
    manager: 'اعتماد المدير',
    supervisor: 'اعتماد المشرف',
    matching: 'مطابقة قبل التحويل',
    ready: 'جاهز للتحويل',
    paid: 'تم التحويل',
    closed: 'مغلق',
    rejected: 'مرفوض',
};
const workflowStages = [
    ['draft', 'مسودة', 'file-pen'],
    ['accountant', 'مراجعة المحاسب', 'calculator'],
    ['manager', 'اعتماد المدير', 'user-tie'],
    ['supervisor', 'اعتماد المشرف', 'user-check'],
    ['matching', 'مطابقة قبل التحويل', 'file-circle-check'],
    ['ready', 'جاهز للتحويل', 'money-bill-wave'],
    ['paid', 'تم التحويل', 'building-columns'],
    ['closed', 'مغلق', 'lock'],
];
const kinds = {
    vehicles: 'السيارات',
    suppliers: 'الموردون',
    items: 'الأصناف',
    regions: 'المناطق',
    branches: 'الفروع',
    'cost-centers': 'مراكز التكلفة',
    warehouses: 'المخازن',
};
const movementTypes = {
    issue: 'صرف على سيارة',
    transfer: 'تحويل بين المخازن',
    return: 'مرتجع إلى المخزن',
    adjust: 'تسوية جرد',
};
const cardStatuses = {
    pending: 'مفتوح',
    waiting_parts: 'بانتظار القطع',
    in_progress: 'قيد الصيانة',
    completed: 'مكتمل',
    closed: 'مغلق',
};
const searchEntityLabels = {
    purchase_orders: 'طلبات الشراء',
    vehicles: 'السيارات',
    maintenance_cards: 'كروت الصيانة',
    suppliers: 'الموردون',
    items: 'الأصناف',
};
const permission = kind => ['vehicles', 'suppliers', 'items'].includes(kind) ? kind + '.manage' : 'masters.manage';
const dtLanguage = {
    processing: 'جارٍ تحميل البيانات...',
    search: 'بحث',
    searchPlaceholder: 'ابحث هنا',
    lengthMenu: 'عرض _MENU_ سجل',
    info: 'عرض _START_ إلى _END_ من أصل _TOTAL_ سجل',
    infoEmpty: 'لا توجد سجلات لعرضها',
    infoFiltered: '(مصفاة من أصل _MAX_ سجل)',
    loadingRecords: 'جارٍ التحميل...',
    zeroRecords: 'لا توجد نتائج مطابقة',
    emptyTable: 'لا توجد بيانات متاحة',
    paginate: { first: 'الأول', previous: 'السابق', next: 'التالي', last: 'الأخير' },
};

$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        Accept: 'application/json',
    },
});

$(document).ajaxError((event, xhr) => {
    if (xhr.status === 401 || xhr.status === 419) {
        location.href = '/login';
        return;
    }

    const messages = Object.values(xhr.responseJSON?.errors || {}).flat().filter(Boolean);
    toastr.error(messages.join('<br>') || esc(xhr.responseJSON?.message) || 'حدث خطأ غير متوقع. حاول مرة أخرى أو تواصل مع مدير النظام.');
});

toastr.options = {
    positionClass: 'toast-top-left',
    escapeHtml: true,
    rtl: true,
    closeButton: true,
    progressBar: true,
};

function roleLabel(name) {
    return ui.role_labels?.[name] || name || 'دور غير معروف';
}

function permissionMeta(name) {
    return ui.permission_catalog?.[name] || { label: 'صلاحية غير معروفة', description: 'صلاحية مسجلة في النظام.' };
}

function field(name, label, value = '', type = 'text', required = true, extra = '') {
    return `<div class="field"><label for="f-${name}">${label}${required ? '' : ' <span class="subtext">اختياري</span>'}</label><input id="f-${name}" class="form-control" name="${name}" type="${type}" value="${esc(value)}" ${required ? 'required' : ''} ${type === 'number' ? 'step="any" min="0"' : ''} ${extra}></div>`;
}

function textareaField(name, label, value = '', required = false, rows = 3) {
    return `<div class="field"><label for="f-${name}">${label}${required ? '' : ' <span class="subtext">اختياري</span>'}</label><textarea id="f-${name}" class="form-control" name="${name}" rows="${rows}" ${required ? 'required' : ''}>${esc(value)}</textarea></div>`;
}

function select(name, label, rows, value = '', quick = '', options = {}) {
    const placeholder = options.placeholder || 'اختر من القائمة';
    const optionLabel = row => row.label || row.name || row.plate || row.title || row.code || row.email;
    return `<div class="field"><label for="f-${name}">${label}</label><div class="picker"><select id="f-${name}" class="form-select searchable" name="${name}" ${options.multiple ? 'multiple' : ''}><option value="">${placeholder}</option>${rows.map(row => `<option value="${esc(row.id)}" ${options.multiple ? ((value || []).map(String).includes(String(row.id)) ? 'selected' : '') : (String(row.id) === String(value) ? 'selected' : '')}>${esc(optionLabel(row))}</option>`).join('')}</select>${quick && can(permission(quick)) ? `<button type="button" class="quick-add" data-quick="${quick}" data-target="${name}"><i class="fa-solid fa-plus"></i>إضافة</button>` : ''}</div></div>`;
}

function multi(name, label, rows, chosen = []) {
    return `<div class="field"><label for="f-${name}">${label}</label><select id="f-${name}" class="form-select searchable" multiple name="${name}">${rows.map(row => `<option value="${esc(row.id)}" ${(chosen || []).map(String).includes(String(row.id)) ? 'selected' : ''}>${esc(row.name)}</option>`).join('')}</select></div>`;
}

function formData(form) {
    return Object.fromEntries(new FormData(form));
}

function clearModalErrors(form) {
    $(form).find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
    $(form).find('.modal-field-error').remove();
}

function applyModalErrors(form, errors = {}) {
    Object.entries(errors).forEach(([name, messages]) => {
        const fieldName = name.replace(/\.\d+.*$/, '');
        const input = $(form).find(`[name="${fieldName}"]`).first();
        if (!input.length) return;
        input.addClass('is-invalid').attr('aria-invalid', 'true');
        input.next('.select2-container').find('.select2-selection').addClass('is-invalid');
        input.closest('.field').append(`<div class="invalid-feedback modal-field-error d-block">${esc((messages || []).join(' '))}</div>`);
    });
}

function head(title, sub = '', actions = '') {
    return `<div class="page-head"><div><div class="breadcrumb-line">الرئيسية / ${esc(title)}</div><h1>${esc(title)}</h1>${sub ? `<p class="subtext">${esc(sub)}</p>` : ''}</div><div class="action-bar">${actions}</div></div>`;
}

function button(label, action, icon = 'plus', extra = '') {
    const semanticClass = /edit|update/.test(action) ? ' action-edit' : ' action-add';
    return `<button type="button" class="btn btn-primary${semanticClass}" data-action="${action}" title="${esc(label)}" ${extra}><i class="fa-solid fa-${icon}"></i>${label}</button>`;
}

function applyActionTitles(root = document) {
    $(root).find('button, a.btn').each(function () {
        const label = this.getAttribute('aria-label') || $(this).text().replace(/\s+/g, ' ').trim();
        if (!this.hasAttribute('title') && label) this.setAttribute('title', label);
        if (this.classList.contains('stage-summary-card')) return;
        if (/حذف/.test(label)) this.classList.add('action-delete');
        else if (/تعديل/.test(label)) this.classList.add('action-edit');
        else if (/تفاصيل|عرض/.test(label)) this.classList.add('action-details');
        else if (/إضافة|جديد|إنشاء/.test(label)) this.classList.add('action-add');
    });
}

function openModal(title, body, save) {
    $('#editor .modal-title').text(title);
    $('#editor .modal-body').html(body);
    modalSave = save;
    $('#editor-form button[type=submit]').show();
    enhance('#editor');
    bootstrap.Modal.getOrCreateInstance('#editor').show();
}

function enhance(root = document) {
    applyActionTitles(root);
    $(root).find('.searchable').each(function () {
        const selectNode = $(this);
        if (selectNode.hasClass('select2-hidden-accessible')) {
            selectNode.select2('destroy');
        }
        selectNode.select2({
            dir: 'rtl',
            width: '100%',
            dropdownParent: selectNode.closest('.modal').length ? $('#editor') : $(document.body),
            language: {
                noResults: () => 'لا توجد نتائج',
                searching: () => 'جارٍ البحث...',
            },
        });
    });
}

function grid(url, columns, options = {}) {
    if (table) {
        table.destroy();
        table = null;
    }
    $('#grid-area').html('<div class="table-responsive"><table id="records" class="table display align-middle"></table></div>');
    table = $('#records').DataTable({
        processing: true,
        serverSide: true,
        ajax: '/api/' + url,
        order: options.order || [[0, 'desc']],
        pageLength: options.pageLength || 10,
        language: dtLanguage,
        autoWidth: false,
        columns: columns.map(column => ({
            defaultContent: '—',
            render: column.render || $.fn.dataTable.render.text(),
            ...column,
        })),
        drawCallback: () => applyActionTitles('#records'),
    });
}

const selectColumn = () => ({ data: 'id', title: '<input type="checkbox" class="select-all" aria-label="تحديد الكل">', orderable: false, searchable: false, className: 'row-selector', render: id => `<input type="checkbox" class="row-select" value="${Number(id)}" aria-label="تحديد السجل">` });

function enableBulkDelete(kind, permissionName, extra = {}) {
    if (!can(permissionName)) return;
    $('#grid-area').before('<div class="action-bar bulk-delete-bar"><button type="button" class="btn btn-light text-danger bulk-delete" disabled><i class="fa-solid fa-trash"></i>حذف المحدد</button><span class="subtext bulk-selection-count">لم يتم تحديد سجلات.</span></div>');
    const container = $('#content');
    const selected = () => $('#records .row-select:checked').map((_, node) => Number(node.value)).get();
    const update = () => {
        const count = selected().length;
        $('.bulk-delete').prop('disabled', !count);
        $('.bulk-selection-count').text(count ? `تم تحديد ${count} سجل.` : 'لم يتم تحديد سجلات.');
        $('#records .select-all').prop('checked', $('.row-select').length > 0 && count === $('.row-select').length);
    };
    // The action bar is a sibling of #grid-area, so bind from the stable page container.
    container.off('change.bulkDelete', '.select-all').on('change.bulkDelete', '.select-all', function () { $('#records .row-select').prop('checked', this.checked); update(); })
        .off('change.bulkDelete', '.row-select').on('change.bulkDelete', '.row-select', update)
        .off('click.bulkDelete', '.bulk-delete').on('click.bulkDelete', '.bulk-delete', async () => {
            const ids = selected();
            const result = await Swal.fire({ title: `حذف ${ids.length} سجل؟`, text: 'لا يمكن التراجع عن الحذف. سيتحقق النظام من العلاقات والأرصدة قبل التنفيذ.', icon: 'warning', showCancelButton: true, confirmButtonText: 'حذف', cancelButtonText: 'إلغاء', reverseButtons: true });
            if (!result.isConfirmed) return;
            await api('records/' + kind, 'DELETE', { ids, ...extra });
            toastr.success('تم حذف السجلات المحددة.');
            table.ajax.reload();
            await refreshLookups();
        });
    $('#records').on('draw.dt', update);
}

function col(data, title, extra = {}) {
    return { data, title, ...extra };
}

function setSearchExpanded(open) {
    $('#global-search-input').attr('aria-expanded', open ? 'true' : 'false');
    $('#global-search-results').prop('hidden', !open);
}

function renderSearchState(message, icon = 'circle-info') {
    $('#global-search-results').html(`<div class="global-search-state"><i class="fa-solid fa-${icon}"></i><span>${esc(message)}</span></div>`);
    searchActiveIndex = -1;
    setSearchExpanded(true);
}

function groupedSearchResults(results) {
    return results.reduce((groups, row) => {
        (groups[row.type] ||= []).push(row);
        return groups;
    }, {});
}

function renderSearchResults(results) {
    if (!results.length) {
        renderSearchState('لا توجد نتائج مطابقة', 'magnifying-glass');
        return;
    }

    const groups = groupedSearchResults(results);
    $('#global-search-results').html(`<div class="global-search-results-head"><span><i class="fa-solid fa-magnifying-glass"></i> نتائج البحث</span><small>${results.length} نتيجة</small></div>` + Object.entries(groups).map(([type, rows]) => `
        <div class="global-search-group">
            <div class="global-search-group-title"><span>${esc(searchEntityLabels[type] || type)}</span><b>${rows.length}</b></div>
            ${rows.map(row => `<a class="global-search-option" role="option" href="${esc(row.url)}" data-search-option tabindex="-1">
                <i class="fa-solid fa-${esc(row.icon || 'circle')}"></i>
                <span><strong>${esc(row.label)}</strong><small>${esc(row.secondary || '')}</small></span>
                <i class="fa-solid fa-arrow-left global-search-option-arrow" aria-hidden="true"></i>
            </a>`).join('')}
        </div>
    `).join(''));
    searchActiveIndex = -1;
    setSearchExpanded(true);
}

function moveSearchSelection(delta) {
    const options = $('[data-search-option]');
    if (!options.length) return;
    searchActiveIndex = (searchActiveIndex + delta + options.length) % options.length;
    options.removeClass('active').attr('aria-selected', 'false');
    const active = options.eq(searchActiveIndex).addClass('active').attr('aria-selected', 'true');
    active[0].scrollIntoView({ block: 'nearest' });
}

function closeGlobalSearch() {
    if (searchRequest) searchRequest.abort();
    clearTimeout(searchTimer);
    setSearchExpanded(false);
    searchActiveIndex = -1;
}

function performGlobalSearch(term) {
    if (searchRequest) searchRequest.abort();
    renderSearchState('جارٍ البحث...', 'spinner');
    searchRequest = api('search', 'GET', { q: term })
        .done(response => renderSearchResults(response.results || []))
        .fail(xhr => {
            if (xhr.statusText !== 'abort') renderSearchState('تعذر إكمال البحث الآن', 'triangle-exclamation');
        })
        .always(() => {
            searchRequest = null;
        });
}

function initGlobalSearch() {
    const input = $('#global-search-input');
    const clear = $('#global-search-clear');
    if (!input.length || input.data('ready')) return;
    input.data('ready', true);

    input.on('input', function () {
        const term = this.value.trim();
        clear.prop('hidden', !term);
        clearTimeout(searchTimer);
        if (term.length < 2) {
            closeGlobalSearch();
            return;
        }
        searchTimer = setTimeout(() => performGlobalSearch(term.slice(0, 80)), 350);
    }).on('keydown', function (event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveSearchSelection(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveSearchSelection(-1);
        } else if (event.key === 'Enter' && searchActiveIndex >= 0) {
            event.preventDefault();
            $('[data-search-option]').eq(searchActiveIndex)[0].click();
        } else if (event.key === 'Escape') {
            closeGlobalSearch();
        }
    });

    clear.on('click', function () {
        input.val('').trigger('input').focus();
    });
    $('#global-search-results').on('click', '[data-search-option]', closeGlobalSearch);
    $(document).on('pointerdown.globalSearch', event => {
        if (!$(event.target).closest('.global-search').length) closeGlobalSearch();
    });
}

function statusLabel(value) {
    return statuses[value] || 'حالة غير معروفة';
}

function workflowSteps(status) {
    const currentIndex = workflowStages.findIndex(([key]) => key === status);
    const isRejected = status === 'rejected';
    const activeStage = workflowStages[Math.max(currentIndex, 0)];
    const progress = isRejected ? 0 : Math.round(((currentIndex + 1) / workflowStages.length) * 100);
    const stages = workflowStages.map(([key, label, icon], index) => {
        const state = isRejected ? '' : index < currentIndex ? ' is-complete' : index === currentIndex ? ' is-current' : '';
        const marker = index < currentIndex && !isRejected ? 'check' : icon;
        return `<li class="workflow-step${state}" ${index === currentIndex ? 'aria-current="step"' : ''}>
            <span class="workflow-step-icon"><i class="fa-solid fa-${marker}"></i></span>
            <span class="workflow-step-copy"><b>${esc(label)}</b><small>${index < currentIndex && !isRejected ? 'مكتملة' : index === currentIndex ? 'المرحلة الحالية' : 'بانتظار التنفيذ'}</small></span>
        </li>`;
    }).join('');
    const rejection = isRejected ? `<div class="workflow-rejected"><i class="fa-solid fa-ban"></i><span><b>تم رفض الطلب</b><small>يمكن إعادة الطلب للتعديل وإرساله من جديد عند الحاجة.</small></span></div>` : '';
    return `<section class="panel workflow-steps workflow-timeline" aria-label="مراحل سير طلب الشراء">
        <header class="workflow-summary">
            <div class="workflow-heading"><span><i class="fa-solid fa-route"></i> مسار الطلب</span><small>${isRejected ? 'مسار متوقف' : `المرحلة الحالية: ${esc(statusLabel(status))}`}</small></div>
            <div class="workflow-status-card${isRejected ? ' is-rejected' : ''}"><span class="workflow-status-icon"><i class="fa-solid fa-${isRejected ? 'ban' : activeStage[2]}"></i></span><span><small>${isRejected ? 'حالة الطلب' : 'أنت الآن في'}</small><b>${isRejected ? 'تم رفض الطلب' : esc(activeStage[1])}</b></span><strong>${progress}%</strong></div>
        </header>
        <div class="workflow-progress" aria-hidden="true"><span style="width:${progress}%"></span></div>
        ${rejection}<ol>${stages}</ol>
    </section>`;
}

function orderTimeline(order) {
    const actionMeta = {
        'orders.submitted': ['تم إرسال الطلب للمراجعة', 'paper-plane', 'sent'],
        'orders.review': ['تمت مراجعة المحاسب', 'calculator', 'approved'],
        'orders.approve_manager': ['تم اعتماد المدير', 'user-tie', 'approved'],
        'orders.approve_supervisor': ['تم اعتماد المشرف', 'user-check', 'approved'],
        'orders.return': ['أُعيد الطلب للتعديل', 'rotate-left', 'returned'],
        'orders.reject': ['تم رفض الطلب', 'ban', 'rejected'],
        'orders.matched': ['تم اعتماد المطابقة', 'check-double', 'approved'],
        'orders.closed': ['تم إغلاق الطلب', 'lock', 'closed'],
    };
    const entries = [{
        id: 'created',
        action: 'created',
        from_status: null,
        to_status: 'draft',
        created_at: order.created_at,
        user: order.creator,
        reason: null,
    }, ...(order.approvals || [])]
        .filter(entry => entry.created_at)
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

    const item = (entry, index) => {
        const meta = entry.action === 'created'
            ? ['تم إنشاء الطلب كمسودة', 'file-circle-plus', 'created']
            : actionMeta[entry.action] || ['تم تحديث مسار الطلب', 'clock-rotate-left', 'updated'];
        const transition = entry.from_status && entry.to_status
            ? `<span class="order-timeline-transition">${esc(statusLabel(entry.from_status))}<i class="fa-solid fa-arrow-left"></i>${esc(statusLabel(entry.to_status))}</span>`
            : `<span class="order-timeline-transition">${esc(statusLabel(entry.to_status || 'draft'))}</span>`;
        return `<article class="order-timeline-item is-${meta[2]}${index > 7 ? ' is-extra' : ''}">
            <div class="order-timeline-marker"><i class="fa-solid fa-${meta[1]}"></i></div>
            <div class="order-timeline-card">
                <div class="order-timeline-card-head"><div><h3>${meta[0]}</h3>${transition}</div><time datetime="${esc(entry.created_at)}"><i class="fa-regular fa-clock"></i>${esc(timelineDate(entry.created_at))}</time></div>
                <div class="order-timeline-actor"><span class="order-timeline-avatar"><i class="fa-solid fa-user"></i></span><span>${esc(entry.user?.name || 'مستخدم النظام')}</span></div>
                ${entry.reason ? `<div class="order-timeline-reason"><i class="fa-solid fa-comment-dots"></i><span>${esc(entry.reason)}</span></div>` : ''}
            </div>
        </article>`;
    };
    const extraCount = Math.max(entries.length - 8, 0);
    return `<section class="panel order-timeline" aria-labelledby="order-timeline-title">
        <header class="order-timeline-head">
            <div><span class="order-timeline-kicker"><i class="fa-solid fa-timeline"></i>متابعة موثّقة</span><h2 id="order-timeline-title" class="section-title">سجل الدورة والاعتمادات</h2><p>تسلسل زمني لأحدث إجراءات الطلب والقرارات المرتبطة به.</p></div>
            <span class="order-timeline-count"><b>${entries.length}</b> ${entries.length === 1 ? 'حدث' : 'أحداث'}</span>
        </header>
        <div class="order-timeline-list">${entries.map(item).join('')}</div>
        ${extraCount ? `<button class="order-timeline-toggle" type="button" aria-expanded="false"><i class="fa-solid fa-chevron-down"></i><span>عرض ${extraCount} أحداث سابقة</span></button>` : ''}
    </section>`;
}

function movementLabel(value) {
    return ui.movement_type_labels?.[value] || movementTypes[value] || 'حركة غير معروفة';
}

function movementBadge(value) {
    const key = Object.prototype.hasOwnProperty.call(movementTypes, value) ? value : 'unknown';
    return `<span class="inventory-badge inventory-movement-${key}">${esc(movementLabel(value))}</span>`;
}

function stockStateBadge(quantity, minimum) {
    const qty = Number(quantity || 0);
    const min = Number(minimum || 0);
    if (qty < 0) return '<span class="inventory-badge inventory-stock-negative">رصيد سالب</span>';
    if (qty < min) return '<span class="inventory-badge inventory-stock-low">أقل من الحد</span>';
    return '<span class="inventory-badge inventory-stock-ok">متاح</span>';
}

function activeBadge(value) {
    return value ? '<span class="state-badge state-active">نشط</span>' : '<span class="state-badge state-inactive">غير نشط</span>';
}

function quantityText(value) {
    return (Number(value || 0) / 1000).toLocaleString('ar-SA', { maximumFractionDigits: 3 });
}

function cardStatusLabel(value) {
    return ui.card_status_labels?.[value] || cardStatuses[value] || 'حالة غير معروفة';
}

function cardStatusBadge(value) {
    const key = Object.prototype.hasOwnProperty.call(cardStatuses, value) ? value : 'unknown';
    return `<span class="card-status card-status-${key}">${esc(cardStatusLabel(value))}</span>`;
}

function renderRolePills(roles) {
    return roles.map(role => `<span class="admin-pill role-pill">${esc(roleLabel(role.name || role))}</span>`).join(' ');
}

function userStatusBadge(value) {
    return value ? '<span class="state-badge state-active">نشط</span>' : '<span class="state-badge state-inactive">معطل</span>';
}

function branchScopeBadge(value) {
    return value ? '<span class="admin-pill scope-pill">جميع الفروع</span>' : '<span class="admin-pill scope-pill">فروع محددة</span>';
}

function activityBadge(value) {
    return `<span class="admin-pill activity-pill">${esc(value || 'نشاط')}</span>`;
}

function nav() {
    const entries = [
        ['dashboard', 'لوحة التحكم', 'gauge-high', 'dashboard.view'],
        ['orders', 'طلبات الشراء', 'file-invoice', 'orders.view'],
        ['inventory', 'المخزون والحركات', 'boxes-stacked', 'inventory.view'],
        ['cards', 'كروت الصيانة', 'screwdriver-wrench', 'cards.view'],
        ...Object.entries(kinds).map(([key, label]) => ['master/' + key, label, key === 'vehicles' ? 'car' : 'database', ['vehicles', 'items', 'suppliers'].includes(key) ? key + '.view' : 'masters.manage']),
        ['reports', 'التقارير', 'chart-line', 'reports.view'],
        ['users', 'المستخدمون', 'users', 'users.manage'],
        ['roles', 'الأدوار والصلاحيات', 'user-shield', 'roles.manage'],
        ['activity', 'سجل النشاطات', 'clock-rotate-left', 'activity.view'],
    ];

    $('#navigation').html(entries.filter(entry => can(entry[3])).map(entry => `<a class="nav-item" href="#${entry[0]}"><i class="fa-solid fa-${entry[2]}"></i>${entry[1]}</a>`).join(''));
}

async function refreshLookups() {
    lookup = await api('lookups');
    ui = { ...ui, ...(lookup.ui || {}) };
}

async function route() {
    if (chart) {
        chart.destroy();
        chart = null;
    }
    if (table) {
        table.destroy();
        table = null;
    }

    const [hash, queryString = ''] = (location.hash.slice(1) || 'dashboard').split('?');
    const routeParams = new URLSearchParams(queryString);
    const requestedCategory = routeParams.get('category');
    const category = Object.hasOwn(lookup.categories, requestedCategory) ? requestedCategory : '';
    $('.nav-item').removeClass('active').filter(`[href="#${hash.split('/')[0]}"]`).addClass('active');
    $('#sidebar').removeClass('open');

    try {
        if (hash.startsWith('order/')) {
            return await detail(hash.split('/')[1]);
        }
        if (hash === 'new') {
            return orderForm(null, category);
        }
        if (hash.startsWith('master/')) {
            const [, kind, id] = hash.split('/');
            return masters(kind, id);
        }

        switch (hash) {
            case 'dashboard': return await dashboard();
            case 'orders': return orders(false, category);
            case 'reports': return orders(true);
            case 'inventory': return inventory(routeParams);
            case 'cards': return cards(routeParams);
            case 'users': return users();
            case 'roles': return await roles();
            case 'activity': return activity();
            default:
                $('#content').html(head('الصفحة غير موجودة', 'تحقق من العنوان المطلوب ثم حاول مرة أخرى.'));
        }
    } catch (error) {
        $('#content').html(head('تعذر تحميل الصفحة', 'تأكد من صلاحياتك أو من توفر الاتصال بالخادم.'));
    }
}

async function dashboard() {
    const data = await api('dashboard');
    const categories = Object.entries(lookup.categories).map(([key, label], index) => `
        <article class="service-card" style="--accent:${['#0866ff', '#00a5b4', '#e28b36'][index % 3]}">
            <div class="service-top">
                <div class="service-copy">
                    <h2>${esc(label)}</h2>
                    <p>طلبات مرتبطة، اعتماد متدرج، ومرفقات متابعة جاهزة.</p>
                </div>
                <div class="service-icon"><i class="fa-solid fa-${['screwdriver-wrench', 'car-burst', 'gears', 'bolt', 'building', 'boxes-stacked', 'file-invoice'][index] || 'folder-open'}"></i></div>
            </div>
            <p class="service-count"><strong>${Number(data.category_counts?.[key] || 0)}</strong> طلب</p>
            <div class="service-actions">
                ${can('orders.view') ? `<a class="service-orders" href="#orders?category=${encodeURIComponent(key)}">عرض الطلبات</a>` : ''}
                ${can('orders.create') ? `<a class="service-add" href="#new?category=${encodeURIComponent(key)}" title="إضافة طلب: ${esc(label)}" aria-label="إضافة طلب: ${esc(label)}"><i class="fa-solid fa-plus" aria-hidden="true"></i></a>` : ''}
            </div>
        </article>
    `).join('');

    $('#content').html(
        head('لوحة التحكم', 'متابعة مؤشرات التشغيل والمشتريات والصيانة من شاشة واحدة.', can('orders.create') ? '<a href="#new" class="btn btn-primary"><i class="fa-solid fa-plus"></i>طلب شراء جديد</a>' : '') +
        `<section class="dashboard-hero"><div><span class="dashboard-eyebrow"><i class="fa-solid fa-sparkles"></i> نظرة تشغيلية مباشرة</span><h2>كل ما يحتاج المتابعة، في مكان واحد.</h2><p>راقب سير الطلبات والسيولة والأصول من ملخص حي وواضح.</p></div><div class="dashboard-hero-pulse"><i class="fa-solid fa-chart-line"></i><span>بيانات محدثة</span></div></section>
        <div class="stats">${[
            [Object.values(data.counts).reduce((sum, count) => sum + Number(count), 0), 'إجمالي الطلبات', 'file-invoice'],
            [money(data.order_total_minor), 'قيمة الطلبات', 'sack-dollar'],
            [money(data.paid_minor), 'إجمالي المدفوع', 'money-bill-transfer'],
            [data.vehicles, 'السيارات النشطة', 'car'],
        ].map(stat => `<div class="stat dashboard-stat"><i class="fa-solid fa-${stat[2]}"></i><div class="dashboard-stat-copy"><strong>${stat[0]}</strong><span>${stat[1]}</span></div></div>`).join('')}</div>
        <div class="service-grid">${categories}</div>
        <div class="dashboard-bottom">
            <section class="panel dashboard-chart-panel">
                <h2 class="section-title"><span><i class="fa-solid fa-chart-column"></i> توزيع حالات الطلبات</span><small>نظرة حسب الحالة</small></h2>
                <div id="chart"></div>
            </section>
            <section class="panel dashboard-followup-panel">
                <h2 class="section-title"><span><i class="fa-solid fa-bolt"></i> ملخص المتابعة</span><small>يتطلب انتباهك</small></h2>
                <div class="dashboard-followup-summary">
                    ${can('cards.view') ? `<a class="dashboard-followup-link" href="#cards?status=open" aria-label="عرض كروت الصيانة المفتوحة">كروت الصيانة المفتوحة: <b>${data.cards}</b><small>عرض الكروت <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></small></a>` : `<p>كروت الصيانة المفتوحة: <b>${data.cards}</b></p>`}
                    ${can('inventory.view') ? `<a class="dashboard-followup-link" href="#inventory?low_stock=1" aria-label="عرض الأصناف الأقل من حد إعادة الطلب">أصناف أقل من حد إعادة الطلب: <b>${data.low_stock}</b><small>عرض الأصناف <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></small></a>` : `<p>أصناف أقل من حد إعادة الطلب: <b>${data.low_stock}</b></p>`}
                </div>
                <div class="dashboard-recent-list">
                    ${(data.recent || []).length ? data.recent.map(order => `<a class="quick-link" href="#order/${order.id}"><span><i class="fa-solid fa-file-lines"></i> ${bdi(order.number)}</span><small>${esc(statusLabel(order.status))}</small></a>`).join('') : '<p class="subtext">لا توجد طلبات حديثة.</p>'}
                </div>
            </section>
        </div>`
    );

    chart = new ApexCharts(document.querySelector('#chart'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, fontFamily: 'Cairo, system-ui, sans-serif' },
        series: [{ name: 'الطلبات', data: Object.values(data.counts).map(Number) }],
        xaxis: {
            categories: Object.keys(data.counts).map(key => statusLabel(key)),
            labels: {
                rotate: -25,
                trim: false,
                hideOverlappingLabels: false,
                style: { fontFamily: 'Cairo, system-ui, sans-serif', fontSize: '12px' },
            },
        },
        yaxis: { labels: { style: { fontFamily: 'Cairo, system-ui, sans-serif' } } },
        tooltip: { theme: 'light' },
        colors: ['#00a5b4'],
        dataLabels: { enabled: false },
        grid: { padding: { left: 12, right: 12, bottom: 6 } },
        responsive: [
            { breakpoint: 1200, options: { chart: { height: 310 } } },
            { breakpoint: 768, options: { chart: { height: 300 }, xaxis: { labels: { rotate: -35 } } } },
        ],
    });
    chart.render();
}

function excelButtons(kind) {
    return `${can('excel.export') ? `<a class="btn btn-light" id="export-link" href="/api/excel/export/${kind}"><i class="fa-solid fa-file-excel"></i>تصدير إكسل</a>` : ''}${kind !== 'orders' && can('excel.import') && can(permission(kind)) ? `<a class="btn btn-light" href="/api/excel/template/${kind}"><i class="fa-solid fa-download"></i>تحميل نموذج الاستيراد</a>${button('استيراد إكسل', 'import', 'file-import', `data-kind="${kind}"`)}` : ''}`;
}

function orders(report = false, category = '') {
    $('#content').html(
        head(report ? 'تقارير الطلبات' : 'طلبات الشراء', 'متابعة الدورة من تسجيل الطلب إلى المطابقة والدفع.', (!report && can('orders.create') ? '<a class="btn btn-primary" href="#new"><i class="fa-solid fa-plus"></i>طلب شراء جديد</a>' : '') + excelButtons('orders')) +
        `<div class="panel po-filter-panel">
            <form id="filters" class="form-grid">
                ${select('status', 'الحالة', Object.entries(statuses).map(([id, name]) => ({ id, name })), '', '', { placeholder: 'كل الحالات' })}
                ${select('branch_id', 'الفرع', lookup.branches, '', '', { placeholder: 'كل الفروع' })}
                ${select('supplier_id', 'المورد', lookup.suppliers, '', 'suppliers', { placeholder: 'كل الموردين' })}
                ${select('vehicle_id', 'السيارة', lookup.vehicles, '', '', { placeholder: 'كل السيارات' })}
                ${select('category', 'نوع الطلب', Object.entries(lookup.categories).map(([id, name]) => ({ id, name })), category, '', { placeholder: 'كل الأنواع' })}
                ${field('from', 'من تاريخ', '', 'date', false)}
                ${field('to', 'إلى تاريخ', '', 'date', false)}
                <div class="field"><label>&nbsp;</label><button class="btn btn-light w-100"><i class="fa-solid fa-filter"></i>تطبيق التصفية</button></div>
            </form>
        </div>
        <div class="panel po-table-panel" id="grid-area"></div>`
    );

    enhance();
    $('#export-link').attr('href', '/api/excel/export/orders?' + new URLSearchParams({ category }));
    grid('orders?' + new URLSearchParams({ category }), [
        selectColumn(),
        col('number', 'رقم الطلب', { className: 'po-code', render: value => bdi(value) }),
        col('date', 'التاريخ', { className: 'po-date', render: value => bdi(value) }),
        col('branch_name', 'الفرع'),
        col('supplier_name', 'المورد'),
        col('vehicle_plate', 'السيارة', { className: 'po-code', render: value => value ? bdi(value) : '—' }),
        col('total', 'الإجمالي', { name: 'total', className: 'po-money', render: value => bdi(value) }),
        col('status_label', 'الحالة', { name: 'status_label', className: 'po-status-cell', render: (value, type, row) => type === 'display' ? `<span class="badge po-status po-status-${esc(row.status)}">${esc(value)}</span>` : value }),
        { data: 'id', title: 'التفاصيل', className: 'po-actions', orderable: false, searchable: false, render: (id, type, row) => `<a class="btn btn-light po-action" href="#order/${Number(id)}" title="عرض تفاصيل ${esc(row.number)}" aria-label="عرض تفاصيل الطلب ${esc(row.number)}"><i class="fa-solid fa-eye"></i><span>عرض</span></a>` },
    ], { order: [[1, 'desc']] });
    $('#grid-area .table-responsive').addClass('po-table-wrap');
    $('#records').addClass('po-table').attr('aria-label', report ? 'جدول تقارير الطلبات' : 'جدول طلبات الشراء');
    if (!report) enableBulkDelete('orders', 'orders.delete');

    $('#filters').on('submit', function (event) {
        event.preventDefault();
        const query = new URLSearchParams(formData(this));
        table.ajax.url('/api/orders?' + query).load();
        $('#export-link').attr('href', '/api/excel/export/orders?' + query);
    });
}

const poOptionLabel = row => row.label || row.name || row.plate || row.title || row.code || row.email;
const poRequiredLabel = required => required ? ' <span class="required" aria-label="مطلوب">*</span><span class="po-required-text">مطلوب</span>' : ' <span class="subtext">اختياري</span>';
const poError = name => `<div class="invalid-feedback po-error" data-error-for="${esc(name)}"></div>`;

function poField(name, label, value = '', type = 'text', required = true, extra = '') {
    return `<div class="field po-field" data-po-field="${esc(name)}"><label for="f-${name}">${label}${poRequiredLabel(required)}</label><input id="f-${name}" class="form-control" name="${name}" type="${type}" value="${esc(value)}" ${required ? 'required' : ''} ${type === 'number' ? 'step="any" min="0"' : ''} ${extra}>${poError(name)}</div>`;
}

function poTextarea(name, label, value = '', required = false, rows = 3) {
    return `<div class="field po-field" data-po-field="${esc(name)}"><label for="f-${name}">${label}${poRequiredLabel(required)}</label><textarea id="f-${name}" class="form-control" name="${name}" rows="${rows}" ${required ? 'required' : ''}>${esc(value)}</textarea>${poError(name)}</div>`;
}

function poSelect(name, label, rows, value = '', quick = '', options = {}) {
    const placeholder = options.placeholder || 'اختر من القائمة';
    const required = options.required !== false;
    return `<div class="field po-field" data-po-field="${esc(name)}"><label for="f-${name}">${label}${poRequiredLabel(required)}</label><div class="picker"><select id="f-${name}" class="form-select searchable" name="${name}" ${required ? 'required' : ''}><option value="">${placeholder}</option>${rows.map(row => `<option value="${esc(row.id)}" ${String(row.id) === String(value) ? 'selected' : ''}>${esc(poOptionLabel(row))}</option>`).join('')}</select>${quick && can(permission(quick)) ? `<button type="button" class="quick-add" data-quick="${quick}" data-target="${name}" aria-label="إضافة ${esc(label)}"><i class="fa-solid fa-plus"></i>إضافة</button>` : ''}</div>${poError(name)}</div>`;
}

function poLineFieldError(index, fieldName) {
    return poError(`lines.${index}.${fieldName}`);
}

function reindexOrderLineErrors() {
    $('#lines tr').each(function (index) {
        ['item_id', 'quantity', 'unit_price'].forEach(fieldName => {
            $(this).find(`[data-error-for$=".${fieldName}"]`).attr('data-error-for', `lines.${index}.${fieldName}`);
        });
    });
}

function poLineTotal(row) {
    const quantity = Number($(row).find('[name=quantity]').val() || 0);
    const price = Number($(row).find('[name=unit_price]').val() || 0);
    return quantity * price;
}

function refreshOrderTotals() {
    const subtotal = $('#lines tr').toArray().reduce((sum, row) => sum + poLineTotal(row), 0);
    const taxPercent = Number($('#order-form [name=tax_percent]').val() || 0);
    const tax = subtotal * taxPercent / 100;
    $('#po-subtotal').text(subtotal.toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    $('#po-tax').text(tax.toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    $('#po-total').text((subtotal + tax).toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}

function updateLineDisplay(row) {
    const selected = $(row).find('[name=item_id] option:selected').text() || '—';
    $(row).find('.po-line-description').text(selected.trim() || '—');
    $(row).find('.po-line-total').text(poLineTotal(row).toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    refreshOrderTotals();
}

function clearOrderFieldError(fieldKey) {
    const errorNode = $(`[data-error-for="${fieldKey.replace(/"/g, '\\"')}"]`);
    errorNode.text('').hide();
    const fieldNode = fieldKey.startsWith('lines.')
        ? errorNode.closest('tr').find(`[name=${fieldKey.split('.').pop() === 'item_id' ? 'item_id' : fieldKey.split('.').pop()}]`)
        : $(`#order-form [name="${fieldKey}"]`);
    fieldNode.removeClass('is-invalid').attr('aria-invalid', 'false');
    if (fieldNode.hasClass('searchable')) {
        fieldNode.next('.select2-container').find('.select2-selection').removeClass('is-invalid');
    }
}

function clearOrderErrors() {
    $('#order-errors').prop('hidden', true).empty();
    $('#order-form .po-error').text('').hide();
    $('#order-form .is-invalid').removeClass('is-invalid').attr('aria-invalid', 'false');
}

function applyOrderErrors(errors = {}) {
    clearOrderErrors();
    const summary = [];
    Object.entries(errors).forEach(([key, messages]) => {
        const message = [].concat(messages).filter(Boolean).join(' ');
        const target = $(`[data-error-for="${key.replace(/"/g, '\\"')}"]`);
        let fieldNode = key.startsWith('lines.')
            ? target.closest('tr').find(`[name=${key.split('.').pop() === 'item_id' ? 'item_id' : key.split('.').pop()}]`)
            : $(`#order-form [name="${key}"]`);
        if (target.length) {
            target.text(message).show();
            fieldNode.addClass('is-invalid').attr('aria-invalid', 'true');
            if (fieldNode.hasClass('searchable')) {
                fieldNode.next('.select2-container').find('.select2-selection').addClass('is-invalid');
            }
        }
        summary.push(message || key);
    });
    if (summary.length) {
        $('#order-errors').html(summary.map(message => `<div>${esc(message)}</div>`).join('')).prop('hidden', false);
    }
    const firstInvalid = $('#order-form .is-invalid').first();
    if (firstInvalid.length) {
        firstInvalid[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        const focusTarget = firstInvalid.hasClass('searchable') ? firstInvalid.next('.select2-container').find('.select2-selection') : firstInvalid;
        focusTarget.trigger('focus');
    }
}

function addLine(line = {}) {
    const index = $('#lines tr').length;
    const row = $(`
        <tr class="po-line-row">
            <td data-label="الصنف">${poSelect('item_id', 'الصنف', lookup.items, line.item_id, 'items')}${poLineFieldError(index, 'item_id')}</td>
            <td data-label="الوصف"><span class="po-line-description">—</span></td>
            <td data-label="الكمية"><input aria-label="الكمية" name="quantity" class="form-control" type="number" min="0.001" step="0.001" required value="${line.quantity_milli ? line.quantity_milli / 1000 : 1}">${poLineFieldError(index, 'quantity')}</td>
            <td data-label="سعر الوحدة"><input aria-label="سعر الوحدة" name="unit_price" class="form-control" type="number" min="0" step="0.01" required value="${line.unit_price_minor ? line.unit_price_minor / 100 : 0}">${poLineFieldError(index, 'unit_price')}</td>
            <td data-label="الإجمالي"><bdi class="po-line-total">0.00</bdi></td>
            <td data-label="إجراء"><button type="button" class="btn btn-light remove-line" aria-label="حذف البند"><i class="fa-solid fa-trash"></i><span class="visually-hidden">حذف البند</span></button></td>
        </tr>
    `);
    $('#lines').append(row);
    reindexOrderLineErrors();
    enhance(row);
    updateLineDisplay(row);
}

function orderForm(order = null, category = '') {
    currentOrder = order;
    $('#content').html(
        head(order ? 'تعديل ' + order.number : 'طلب شراء جديد', 'أدخل بيانات الطلب والبنود ثم احفظ المسودة قبل الإرسال.') +
        `<form id="order-form">
            <div id="order-errors" class="alert alert-danger po-form-errors" role="alert" hidden></div>
            <section class="panel po-form-panel">
                <h2 class="section-title"><span><i class="fa-solid fa-file-invoice"></i>بيانات الطلب</span></h2>
                <div class="form-grid po-form-grid">
                    ${poSelect('category', 'نوع الطلب', Object.entries(lookup.categories).map(([id, name]) => ({ id, name })), order?.category || category || 'maintenance')}
                    ${poField('date', 'التاريخ', order?.date || today(), 'date')}
                    ${poSelect('priority', 'الأولوية', [{ id: 'normal', name: 'عادي' }, { id: 'urgent', name: 'عاجل' }, { id: 'critical', name: 'عاجل جدًا' }], order?.priority || 'normal')}
                    ${poField('quote_number', 'رقم عرض السعر (مطلوب قبل الإرسال للمراجعة)', order?.quote_number || '', 'text', false, 'maxlength="100"')}
                </div>
            </section>
            <section class="panel po-form-panel">
                <h2 class="section-title"><span><i class="fa-solid fa-sitemap"></i>الفرع والأطراف</span></h2>
                <div class="form-grid po-form-grid">
                    ${poSelect('branch_id', 'الفرع', lookup.branches, order?.branch_id, 'branches')}
                    ${poSelect('cost_center_id', 'مركز التكلفة', lookup.cost_centers, order?.cost_center_id, 'cost-centers')}
                    ${poSelect('supplier_id', 'المورد', lookup.suppliers, order?.supplier_id, 'suppliers')}
                    ${poSelect('vehicle_id', 'السيارة', lookup.vehicles, order?.vehicle_id, 'vehicles', { required: false })}
                    ${poSelect('warehouse_id', 'مخزن التوريد', lookup.warehouses, order?.warehouse_id, 'warehouses', { required: false })}
                    ${poField('maintenance_card_id', 'رقم كارت الصيانة الداخلي', order?.maintenance_card_id || '', 'number', false)}
                </div>
            </section>
            <section class="panel po-form-panel">
                <h2 class="section-title"><span><i class="fa-solid fa-list-check"></i>بنود الطلب</span>${button('إضافة بند', 'line-add')}</h2>
                <div class="table-responsive po-lines-wrap">
                    <table class="table po-lines-table">
                        <thead><tr><th>الصنف</th><th>الوصف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th><th>إجراء</th></tr></thead>
                        <tbody id="lines"></tbody>
                    </table>
                </div>
            </section>
            <section class="panel po-form-panel po-summary-panel">
                <h2 class="section-title"><span><i class="fa-solid fa-calculator"></i>الملاحظات والإجمالي</span></h2>
                <div class="form-grid po-form-grid">
                    ${poField('tax_percent', 'نسبة الضريبة', order ? order.tax_basis_points / 100 : 15, 'number')}
                    ${poTextarea('notes', 'ملاحظات الطلب', order?.notes || '', false, 4)}
                    <div class="po-totals" aria-live="polite">
                        <span>قبل الضريبة <bdi id="po-subtotal">0.00</bdi></span>
                        <span>الضريبة <bdi id="po-tax">0.00</bdi></span>
                        <strong>الإجمالي المعروض <bdi id="po-total">0.00</bdi></strong>
                        <small>الإجمالي النهائي يحتسبه الخادم عند الحفظ.</small>
                    </div>
                </div>
            </section>
            <button class="btn btn-primary po-submit" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>حفظ المسودة والمتابعة</span></button>
        </form>`
    );

    enhance();
    (order?.lines || [{}]).forEach(addLine);
    refreshOrderTotals();
    $('#order-form').on('input change', 'input, textarea, select', function () {
        const row = $(this).closest('tr');
        const fieldName = this.name;
        clearOrderFieldError(row.length ? `lines.${row.index()}.${fieldName}` : fieldName);
        if (row.length) {
            updateLineDisplay(row);
        } else if (fieldName === 'tax_percent') {
            refreshOrderTotals();
        }
    }).on('submit', async function (event) {
        event.preventDefault();
        clearOrderErrors();
        reindexOrderLineErrors();
        const data = formData(this);
        ['vehicle_id', 'warehouse_id', 'maintenance_card_id', 'quote_number'].forEach(key => data[key] = data[key] || null);
        data.lines = $('#lines tr').map(function () {
            return {
                item_id: $(this).find('[name=item_id]').val(),
                quantity: $(this).find('[name=quantity]').val(),
                unit_price: $(this).find('[name=unit_price]').val(),
            };
        }).get();

        const submitButton = $(this).find('[type=submit]').prop('disabled', true).addClass('is-loading');
        submitButton.find('span').text('جارٍ الحفظ...');
        try {
            const saved = await api(order ? 'orders/' + order.id : 'orders', order ? 'PUT' : 'POST', data);
            location.hash = 'order/' + saved.id;
            toastr.success('تم حفظ المسودة بنجاح. يمكنك الآن إرفاق المستندات ثم إرسالها للمراجعة.');
        } catch (xhr) {
            if (xhr.responseJSON?.errors) {
                applyOrderErrors(xhr.responseJSON.errors);
            }
        } finally {
            submitButton.prop('disabled', false).removeClass('is-loading');
            submitButton.find('span').text('حفظ المسودة والمتابعة');
        }
    });
}

function ensureOrderDetailStyle() {
    if (!document.getElementById('vehicle-upload-css')) $('<link id="vehicle-upload-css" rel="stylesheet" href="/assets/vehicle-upload.css">').appendTo('head');
    if (document.getElementById('order-detail-style')) return;
    $('<style id="order-detail-style">.order-overview{display:flex;gap:20px;align-items:center;border-right:4px solid var(--blue);padding:18px 22px}.order-metadata{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:17px;flex:1}.order-metadata small,.order-status-pill small{display:block;color:var(--muted);font-weight:700;font-size:12px}.order-metadata strong{display:block;color:#253a5e;font-size:15px;line-height:1.55;overflow-wrap:anywhere}.order-status-pill{background:#e8f8f4;border:1px solid #bce6dc;border-radius:12px;padding:10px 16px;min-width:130px}.order-status-pill b{color:#08785d}.order-indicators{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;margin:0 0 18px}.order-indicators>div{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;padding:14px 16px;border-left:1px solid var(--line)}.order-indicators>div:last-child{border-left:0}.order-indicators i{font-size:22px;color:#1557a5}.order-indicators span{font-weight:700;color:#344968}.order-indicators b{font-size:21px;color:#162e51}.order-detail-layout{display:grid;grid-template-columns:minmax(250px,.78fr) minmax(420px,1.35fr) minmax(300px,1fr);gap:18px;align-items:start}.order-detail-layout .panel{margin:0}.order-approval-column{padding:0;overflow:hidden}.order-approval-column .order-timeline{margin:0;border:0;border-radius:0;box-shadow:none}.order-main-column{display:grid;gap:18px}.quote-file{display:flex;gap:12px;align-items:center;padding:14px;border:1px solid #dce6f3;background:#f8fbff;border-radius:12px;color:var(--ink)}.quote-file>.fa-file-pdf{font-size:30px;color:#db3247}.quote-file span{display:grid;gap:2px;flex:1;min-width:0}.quote-file b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.quote-file small{color:var(--muted)}.vehicle-photo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.order-photo{display:block;background:#f6f8fb;border:1px solid #e0e7f1;border-radius:10px;overflow:hidden;color:#32496b;font-size:12px;font-weight:700;text-align:center}.order-photo img{display:block;width:100%;height:112px;object-fit:cover}.order-photo span{display:block;padding:5px}.order-photos-card .section-title>b{font-size:14px;color:#1557a5;background:#edf5ff;padding:4px 9px;border-radius:999px}@media(max-width:1350px){.order-detail-layout{grid-template-columns:minmax(280px,.9fr) minmax(400px,1.35fr)}.order-photos-card{grid-column:1/-1}.vehicle-photo-grid{grid-template-columns:repeat(5,minmax(0,1fr))}.order-photo img{height:95px}}@media(max-width:900px){.order-metadata{grid-template-columns:repeat(2,minmax(0,1fr))}.order-detail-layout{grid-template-columns:1fr}.order-photos-card{grid-column:auto}.vehicle-photo-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.order-indicators{grid-template-columns:repeat(3,minmax(0,1fr)}}@media(max-width:600px){.order-overview{align-items:flex-start;flex-direction:column}.order-metadata,.order-indicators{grid-template-columns:1fr}.order-indicators>div{border-left:0;border-bottom:1px solid var(--line)}.order-indicators>div:last-child{border-bottom:0}.vehicle-photo-grid{grid-template-columns:repeat(2,minmax(0,1fr)}}</style>').appendTo('head');
}

async function detail(id) {
    ensureOrderDetailStyle();
    const order = await api('orders/' + id);
    currentOrder = order;
    let actions = '';

    if (order.status === 'draft') {
        if (can('orders.update')) actions += button('تعديل المسودة', 'edit-order', 'pen');
        if (can('orders.submit')) actions += button('إرسال للمراجعة', 'transition', 'paper-plane', 'data-next="submit"');
    }
    const approvals = { accountant: 'orders.review', manager: 'orders.approve_manager', supervisor: 'orders.approve_supervisor' };
    if (can(approvals[order.status])) actions += button('اعتماد المرحلة', 'transition', 'check', 'data-next="approve"');
    if (['accountant', 'manager', 'supervisor', 'matching', 'ready'].includes(order.status) && !order.receipt && can('orders.reject')) {
        actions += button('إعادة للتعديل', 'transition', 'rotate-left', 'data-next="return"');
        actions += button('رفض الطلب', 'transition', 'ban', 'data-next="reject"');
    }
    if (['matching', 'ready'].includes(order.status)) {
        if (can('receipts.manage')) actions += button('تسجيل الاستلام', 'receipt', 'box');
        if (can('invoices.manage')) actions += button('تسجيل الفاتورة', 'invoice', 'file-invoice');
        if (order.status === 'matching' && can('orders.match')) actions += button('اعتماد المطابقة', 'transition', 'check-double', 'data-next="match"');
    }
    if (order.status === 'ready' && can('payments.create')) actions += button('تسجيل الحوالة', 'payment', 'money-bill-transfer');
    if (order.status === 'paid' && can('orders.close')) actions += button('إغلاق الطلب', 'transition', 'lock', 'data-next="close"');

    const mediaBy = collection => order.media.filter(media => media.collection === collection);
    const vehiclePhotos = mediaBy('photos_before').reverse();
    const quote = mediaBy('quote')[0];
    const metadata = [
        ['رقم الطلب', bdi(order.number)], ['نوع الطلب', esc(lookup.categories[order.category])], ['المنطقة', esc(order.region_name || '—')], ['المورد', esc(order.supplier_name)],
        ['مركز التكلفة', esc(lookup.cost_centers.find(row => String(row.id) === String(order.cost_center_id))?.name || '—')], ['تاريخ الطلب', bdi(order.date)], ['رقم عرض السعر', bdi(order.quote_number || '—')], ['المبلغ الإجمالي', money(order.total_minor)],
    ];
    const indicators = [['صور السيارة', vehiclePhotos.length, 'car-side'], ['المرفقات', order.media.length, 'paperclip'], ['الاستلام', order.receipt ? 1 : 0, 'cart-flatbed'], ['فاتورة المورد', order.invoice ? 1 : 0, 'file-invoice'], ['الحوالة', order.payment ? 1 : 0, 'building-columns']];
    const renderPhoto = media => `<div class="vehicle-photo-card"><a class="order-photo" href="${esc(media.url)}" data-gallery-id="${media.id}"><img src="${esc(media.url)}" alt="${esc(lookup.photo_labels[media.label] || 'صورة السيارة')}"><span>${esc(lookup.photo_labels[media.label] || 'صورة')}</span></a>${can('orders.update') && (media.collection === 'photos_after' ? ['matching', 'ready'].includes(order.status) : order.status === 'draft') ? `<div class="vehicle-photo-tools"><button type="button" class="btn btn-light" data-action="relabel-photo" data-id="${media.id}" title="تغيير زاوية الصورة"><i class="fa-solid fa-arrows-rotate"></i>تعيين الزاوية</button><button type="button" class="btn btn-light text-danger" data-action="delete-media" data-id="${media.id}" title="حذف الصورة"><i class="fa-solid fa-trash"></i>حذف</button></div>` : ''}</div>`;
    const detailActions = can('media.upload') && !['paid', 'closed', 'rejected'].includes(order.status) ? button('رفع ملف أو تصوير', 'upload', 'camera') : '';

    $('#content').html(
        head(order.number, `${lookup.categories[order.category]} · ${order.branch_name} · ${order.date}`, actions) +
        workflowSteps(order.status) +
        `<section class="order-overview panel"><div class="order-metadata">${metadata.map(([label, value]) => `<div><small>${label}</small><strong>${value}</strong></div>`).join('')}</div><div class="order-status-pill"><small>الحالة</small><b>${esc(statusLabel(order.status))}</b></div></section>
        <section class="order-indicators">${indicators.map(([label, count, icon]) => `<div><i class="fa-solid fa-${icon}"></i><span>${label}</span><b>${Number(count).toLocaleString('ar-SA')}</b></div>`).join('')}</section>
        <section class="order-detail-layout"><aside class="panel order-approval-column">${orderTimeline(order)}</aside><div class="order-main-column"><section class="panel order-quote-card"><h2 class="section-title"><span><i class="fa-solid fa-file-lines"></i> عرض السعر</span>${detailActions}</h2>${quote ? `<a class="quote-file" href="${esc(quote.url)}" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i><span><b>${bdi(quote.name)}</b><small>رقم العرض: ${bdi(order.quote_number)}</small></span><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : '<div class="empty-inline"><i class="fa-solid fa-file-circle-exclamation"></i> لم يُرفع عرض سعر بعد.</div>'}</section><section class="panel order-lines-card">
            <h2 class="section-title">بنود الطلب والمطابقة</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>الصنف</th><th>المطلوب</th><th>المستلم</th><th>بالفاتورة</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
                    <tbody>${order.lines.map(line => `<tr><td>${esc(line.description)}</td><td>${line.quantity_milli / 1000}</td><td>${(order.receipt?.lines.find(item => item.order_line_id === line.id)?.quantity_milli || 0) / 1000}</td><td>${(order.invoice?.lines.find(item => item.order_line_id === line.id)?.quantity_milli || 0) / 1000}</td><td>${money(line.unit_price_minor)}</td><td>${money(line.total_minor)}</td></tr>`).join('')}</tbody>
                </table>
            </div>
            <div class="d-flex gap-4 flex-wrap">
                <span>قبل الضريبة: ${money(order.subtotal_minor)}</span>
                <span>الضريبة: ${money(order.tax_minor)}</span>
                <b>الإجمالي: ${money(order.total_minor)}</b>
            </div>
            <p class="mt-3 ${order.matched ? 'text-success' : 'text-warning'}">${order.matched ? 'المستندات والكميات والقيمة متطابقة.' : 'المطابقة غير مكتملة أو توجد فروقات تحتاج إلى معالجة.'}</p>
            ${order.invoice ? `<p>الفاتورة: ${bdi(order.invoice.number)} بقيمة ${money(order.invoice.total_minor)}</p>` : ''}
            ${order.payment ? `<p class="text-success">الحوالة: ${bdi(order.payment.reference)} بقيمة ${money(order.payment.amount_minor)}</p>` : ''}
            ${order.notes ? `<p>${esc(order.notes)}</p>` : ''}
        </section></div><section class="panel order-photos-card"><h2 class="section-title"><span><i class="fa-solid fa-camera"></i> صور السيارة</span><b>${new Set(vehiclePhotos.map(m => m.label)).size}/9</b></h2>${can('media.upload') && can('orders.update') && ['draft', 'matching', 'ready'].includes(order.status) ? button('إضافة الصور والفيديو', 'vehicle-upload', 'images') : ''}<div class="vehicle-photo-grid">${Object.entries(lookup.photo_labels).map(([label, name]) => { const media = vehiclePhotos.find(m => m.label === label); return media ? renderPhoto(media) : `<div class="vehicle-empty"><i class="fa-solid fa-${photoIcon(label)}"></i><b>${esc(name)}</b><small>لم تُضف صورة</small></div>`; }).join('')}</div>${order.media.filter(m => m.mime.startsWith('video/')).map(m => `<video class="vehicle-video" controls preload="metadata" src="${esc(m.url)}"></video>`).join('')}</section></section>`
    );

    $('#content').off('click.orderTimeline', '.order-timeline-toggle').on('click.orderTimeline', '.order-timeline-toggle', function () {
        const button = $(this);
        const expanded = button.attr('aria-expanded') === 'true';
        button.attr('aria-expanded', String(!expanded));
        button.closest('.order-timeline').toggleClass('is-expanded', !expanded);
        button.find('span').text(expanded ? `عرض ${button.closest('.order-timeline').find('.is-extra').length} أحداث سابقة` : 'إخفاء الأحداث السابقة');
    });
    const afterPhotos = mediaBy('photos_after');
    const seenAngles = new Set();
    const extraPhotos = vehiclePhotos.filter(photo => { if (seenAngles.has(photo.label)) return true; seenAngles.add(photo.label); return false; });
    if (extraPhotos.length) $('.order-photos-card').append(`<h3 class="section-title mt-3">صور إضافية للزوايا</h3><div class="vehicle-photo-grid">${extraPhotos.map(renderPhoto).join('')}</div>`);
    if (afterPhotos.length) $('.order-photos-card').append(`<h3 class="section-title mt-3">صور بعد الإصلاح</h3><div class="vehicle-photo-grid">${afterPhotos.map(renderPhoto).join('')}</div>`);
    $('#content').off('click.vehicleGallery', '[data-gallery-id]').on('click.vehicleGallery', '[data-gallery-id]', function (event) {
        event.preventDefault();
        vehicleGallery(order.media.filter(m => m.mime.startsWith('image/')), Number(this.dataset.galleryId));
    });
    $('#content .order-photo img').on('error', function () {
        $(this).replaceWith('<i class="fa-solid fa-image vehicle-image-unavailable" title="تعذر تحميل الصورة"></i>');
    }).each(function () { if (this.complete && !this.naturalWidth) $(this).trigger('error'); });
}

function masterFields(kind, record = {}) {
    let html = kind === 'vehicles'
        ? field('plate', 'لوحة السيارة', record.plate) +
          field('vin', 'رقم الهيكل', record.vin, 'text', false) +
          field('model', 'الموديل', record.model) +
          field('year', 'سنة الصنع', record.year || 2024, 'number') +
          field('color', 'اللون', record.color || 'أبيض') +
          field('odometer', 'العداد', record.odometer || 0, 'number') +
          select('branch_id', 'الفرع', lookup.branches, record.branch_id) +
          select('cost_center_id', 'مركز التكلفة', lookup.cost_centers, record.cost_center_id)
        : field('name', 'الاسم', record.name);

    if (['branches', 'warehouses', 'suppliers', 'cost-centers'].includes(kind)) html += field('code', 'الكود', record.code);
    if (kind === 'branches') html += select('region_id', 'المنطقة', lookup.regions, record.region_id);
    if (kind === 'warehouses') html += select('branch_id', 'الفرع', lookup.branches, record.branch_id);
    if (kind === 'suppliers') html += field('phone', 'رقم الهاتف', record.phone, 'text', false) + field('email', 'البريد الإلكتروني', record.email, 'email', false) + field('tax_number', 'الرقم الضريبي', record.tax_number, 'text', false) + field('iban', 'رقم الآيبان', record.iban, 'text', false) + textareaField('address', 'العنوان', record.address, false, 3);
    if (kind === 'items') html += field('sku', 'كود الصنف', record.sku) + field('unit', 'الوحدة', record.unit || 'قطعة') + field('unit_cost', 'تكلفة الوحدة', (record.unit_cost_minor || 0) / 100, 'number') + field('minimum', 'حد إعادة الطلب', (record.minimum_milli || 0) / 1000, 'number') + select('track_stock', 'تتبع المخزون', [{ id: 1, name: 'نعم' }, { id: 0, name: 'لا' }], record.track_stock === false ? 0 : 1);
    html += select('active', 'الحالة', [{ id: 1, name: 'نشط' }, { id: 0, name: 'غير نشط' }], record.active === false ? 0 : 1);

    return `<div class="form-grid">${html}</div>`;
}

function masterEditor(kind, record = {}) {
    openModal((record.id ? 'تعديل ' : 'إضافة ') + kinds[kind], masterFields(kind, record), data => api('masters/' + kind + (record.id ? '/' + record.id : ''), record.id ? 'PUT' : 'POST', data));
}

function masterColumns(kind) {
    const base = [selectColumn(), col('id', 'الرقم', { className: 'master-code', render: value => bdi(value) })];
    const columns = {
        vehicles: [
            ...base,
            col('plate', 'لوحة السيارة', { className: 'master-code', render: value => bdi(value) }),
            col('model', 'الموديل'),
            col('branch.name', 'الفرع', { orderable: false }),
            col('year', 'السنة', { className: 'master-number', render: value => bdi(value) }),
            col('odometer', 'قراءة العداد', { className: 'master-number', render: value => bdi(Number(value || 0).toLocaleString('ar-SA')) }),
        ],
        suppliers: [
            ...base,
            col('name', 'المورد'),
            col('code', 'الكود', { className: 'master-code', render: value => bdi(value) }),
            col('phone', 'الهاتف', { className: 'master-code', render: value => value ? bdi(value) : '—' }),
        ],
        items: [
            ...base,
            col('name', 'الصنف'),
            col('sku', 'الكود', { className: 'master-code', render: value => bdi(value) }),
            col('unit', 'الوحدة'),
        ],
        regions: [...base, col('name', 'المنطقة')],
        branches: [...base, col('name', 'الفرع'), col('code', 'الكود', { className: 'master-code', render: value => bdi(value) })],
        'cost-centers': [...base, col('name', 'مركز التكلفة'), col('code', 'الكود', { className: 'master-code', render: value => bdi(value) })],
        warehouses: [...base, col('name', 'المخزن'), col('code', 'الكود', { className: 'master-code', render: value => bdi(value) }), col('branch.name', 'الفرع', { orderable: false })],
    }[kind] || [...base, col('name', 'الاسم')];

    columns.push({ data: 'active', title: 'الحالة', className: 'master-state-cell', render: value => activeBadge(value) });
    if (can(permission(kind))) columns.push({ data: null, title: 'تعديل', orderable: false, searchable: false, className: 'master-actions', render: () => '<button class="btn btn-light row-edit compact-action" aria-label="تعديل"><i class="fa-solid fa-pen"></i><span>تعديل</span></button>' });

    return columns;
}

function masters(kind, focusId = null) {
    if (!kinds[kind]) return;
    const focused = /^\d+$/.test(String(focusId || ''));
    const title = focused ? `تفاصيل ${kinds[kind]}` : kinds[kind];
    const description = focused ? 'نتيجة محددة من البحث السريع. يمكنك مراجعة بياناتها أو العودة للقائمة الكاملة.' : 'إدارة البيانات المرجعية المستخدمة في الشاشات والعمليات اليومية.';
    const actions = (focused ? `<a href="#master/${esc(kind)}" class="btn btn-light"><i class="fa-solid fa-list"></i>عرض كل السجلات</a>` : '') + (can(permission(kind)) ? button('إضافة سجل', 'master-add', 'plus', `data-kind="${kind}"`) : '') + (!focused && ['items', 'vehicles', 'suppliers'].includes(kind) ? excelButtons(kind) : '');
    $('#content').html(head(title, description, actions) + `<div class="panel master-result-panel${focused ? ' is-focused' : ''}" id="grid-area"></div>`);

    grid('masters/' + kind + (focused ? '?id=' + encodeURIComponent(focusId) : ''), masterColumns(kind), { order: [[1, 'desc']] });
    $('#records')
        .addClass('master-table' + (kind === 'vehicles' ? ' vehicle-master-table' : ''))
        .closest('.table-responsive')
        .addClass('master-table-wrap');
    $('#records').on('click', '.row-edit', function () {
        masterEditor(kind, table.row($(this).closest('tr')).data());
    });
    if (!focused) enableBulkDelete(kind, 'masters.delete');
}

function inventoryTable(showMovements, lowStock = false) {
    let warehouseId = $('[name=warehouse_id]').val();
    if (!showMovements && !warehouseId) {
        warehouseId = lookup.warehouses[0]?.id;
        $('[name=warehouse_id]').val(warehouseId).trigger('change');
    }

    const query = new URLSearchParams({ warehouse_id: warehouseId || '' });
    if (!showMovements && lowStock) query.set('low_stock', '1');
    grid('inventory/' + (showMovements ? 'movements' : 'balances') + '?' + query.toString(), showMovements
        ? [
            selectColumn(),
            col('number', 'رقم الحركة', { className: 'inventory-code', render: value => bdi(value) }),
            col('date', 'التاريخ', { className: 'inventory-date', render: value => bdi(value) }),
            col('type', 'نوع الحركة', { className: 'inventory-state-cell', render: value => movementBadge(value) }),
            col('warehouse.name', 'المخزن'),
            col('vehicle.plate', 'السيارة', { className: 'inventory-code', render: value => value ? bdi(value) : '—' }),
            { data: 'lines', title: 'البنود', orderable: false, searchable: false, className: 'inventory-lines', render: lines => (lines || []).map(line => `<span>${esc(line.item?.sku || '')} ${esc(line.item?.name || 'صنف')}</span><bdi>${quantityText(line.quantity_milli)}</bdi>`).join('<br>') || '—' },
            col('notes', 'الملاحظات', { className: 'inventory-notes' }),
        ]
        : [
            selectColumn(),
            col('sku', 'كود الصنف', { className: 'inventory-code', render: value => bdi(value) }),
            col('name', 'الصنف'),
            { data: 'quantity_milli', title: 'الرصيد الحالي', className: 'inventory-number', render: value => bdi(quantityText(value)) },
            { data: 'minimum_milli', title: 'حد إعادة الطلب', className: 'inventory-number', render: value => bdi(quantityText(value)) },
            { data: null, title: 'حالة الرصيد', orderable: false, searchable: false, className: 'inventory-state-cell', render: row => stockStateBadge(row.quantity_milli, row.minimum_milli) },
        ], { order: [[1, 'asc']] });

    $('#records')
        .addClass(showMovements ? 'inventory-table inventory-movements-table' : 'inventory-table inventory-balances-table')
        .closest('.table-responsive')
        .addClass('inventory-table-wrap');
    enableBulkDelete(showMovements ? 'movements' : 'stock-balances', 'inventory.delete', showMovements ? {} : { warehouse_id: Number(warehouseId) });
}

function movement(type) {
    openModal('حركة مخزنية', `<div class="form-grid">${select('warehouse_id', 'المخزن', lookup.warehouses)}${type === 'transfer' ? select('destination_warehouse_id', 'المخزن المستلم', lookup.warehouses) : ''}${type === 'issue' ? select('vehicle_id', 'السيارة', lookup.vehicles) + field('odometer', 'قراءة العداد', 0, 'number') : ''}${type === 'return' ? field('source_movement_id', 'رقم إذن الصرف الأصلي', '', 'number') : ''}${field('date', 'التاريخ', today(), 'date')}${select('item_id', 'الصنف', lookup.items.filter(item => item.track_stock))}${field('quantity', type === 'adjust' ? 'العدد الفعلي بالجرد' : 'الكمية', 1, 'number')}${textareaField('notes', 'سبب الحركة', '', type === 'adjust')}</div>`, data => {
        data.lines = [{ item_id: data.item_id, quantity: data.quantity }];
        data.type = type;
        data.request_key = crypto.randomUUID();
        return api('inventory/movements', 'POST', data);
    });
}

function inventory(routeParams = new URLSearchParams()) {
    const lowStock = routeParams.get('low_stock') === '1';
    const subtitle = lowStock ? 'عرض الأصناف التي تقل أرصدتها عن حد إعادة الطلب في المخزن المحدد.' : 'عرض الأرصدة الفعلية وتسجيل الصرف والتحويل والمرتجعات وتسويات الجرد.';
    $('#content').html(head('المخزون والحركات', subtitle, Object.entries(movementTypes).filter(([key]) => can('inventory.' + key)).map(([key, label]) => button(label, 'movement', 'boxes-stacked', `data-type="${key}"`)).join('')) + `<div class="panel">${lowStock ? '<div class="inventory-filter-notice"><i class="fa-solid fa-triangle-exclamation"></i> يتم عرض الأصناف الأقل من حد إعادة الطلب.</div>' : ''}<div class="form-grid">${select('warehouse_id', 'المخزن', lookup.warehouses)}</div><div class="action-bar">${button('عرض الأرصدة', 'balances', 'cubes')}${button('عرض سجل الحركات', 'movements', 'list')}</div><div id="grid-area"></div></div>`);
    enhance();
    inventoryTable(false, lowStock);
}

function cardFilterQuery(includeStatus = true) {
    const values = {
        vehicle_id: $('#card-vehicle-filter').val(),
        date_from: $('#card-date-from').val(),
        date_to: $('#card-date-to').val(),
        status: includeStatus ? $('#card-stage-filter').val() : ($('#card-stage-filter').val() === 'open' ? 'open' : ''),
    };
    if (values.status === 'open') {
        delete values.status;
        values.open = 1;
    }
    return $.param(Object.fromEntries(Object.entries(values).filter(([, value]) => value)));
}

async function refreshCardSummary() {
    const data = await api('cards/summary?' + cardFilterQuery(false));
    Object.entries(cardStatuses).forEach(([status, label]) => {
        const count = data.counts?.[status] || 0;
        const icons = { pending: 'folder-open', waiting_parts: 'gears', in_progress: 'screwdriver-wrench', completed: 'circle-check', closed: 'lock' };
        $(`#stage-summary-${status}`).html(`<i class="fa-solid fa-${icons[status]}" aria-hidden="true"></i><span>${esc(label)}</span><strong>${count.toLocaleString('ar-SA')}</strong><small>كارت صيانة</small>`);
    });
}

function cardDetails(row) {
    const order = row.order ? `<a class="btn btn-light" href="#order/${row.order.id}"><i class="fa-solid fa-file-invoice"></i>فتح الطلب المرتبط ${bdi(row.order.number)}</a>` : '<span class="subtext">لا يوجد طلب شراء مرتبط بهذا الكارت.</span>';
    openModal(`تفاصيل ${row.number}`, `<div class="card-detail-grid"><div><span>السيارة</span><bdi>${esc(row.vehicle?.plate || '—')}</bdi></div><div><span>التاريخ</span><bdi>${esc(row.date)}</bdi></div><div><span>نوع الصيانة</span><bdi>${esc(row.type)}</bdi></div><div><span>قراءة العداد</span><bdi>${Number(row.odometer || 0).toLocaleString('ar-SA')}</bdi></div><div><span>المرحلة الحالية</span>${cardStatusBadge(row.status)}</div></div><section class="card-detail-notes"><strong>ملاحظات الكارت</strong><p>${esc(row.notes || 'لا توجد ملاحظات مسجلة.')}</p></section><div class="detail-actions">${order}</div>`, () => Promise.resolve());
    $('#editor-form button[type=submit]').hide();
}

function cardEditor(row) {
    openModal(`تعديل ${row.number}`, `<div class="form-grid">${field('date', 'التاريخ', row.date, 'date')}${field('type', 'نوع الصيانة', row.type)}${textareaField('notes', 'ملاحظات', row.notes || '', false, 4)}</div>`, data => api('cards/' + row.id, 'PUT', data).then(() => {
        table.ajax.reload();
        refreshCardSummary();
    }));
}

function cards(routeParams = new URLSearchParams()) {
    const initialStatus = routeParams.get('status') === 'open' ? 'open' : '';
    const stageFilters = `<option value="">كل المراحل</option><option value="open">الكروت المفتوحة</option>${Object.entries(cardStatuses).map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}`;
    const stageSummary = Object.entries(cardStatuses).map(([status]) => `<button type="button" class="stage-summary-card" id="stage-summary-${status}" data-stage="${status}" aria-label="عرض كروت مرحلة ${cardStatuses[status]}"></button>`).join('');
    $('#content').html(head('كروت الصيانة', 'تابع الكروت حسب المرحلة، ثم افتح التفاصيل أو عدّل السجل عند الحاجة.', can('cards.manage') ? button('كارت صيانة جديد', 'card-add') : '') + `<section class="panel card-filter-panel"><div class="card-filter-head"><div><h2 class="section-title">تصفية الكروت</h2><p class="subtext">حدّد التاريخ أو السيارة أو المرحلة لتحديث النتائج.</p></div><button type="button" id="clear-card-filters" class="btn btn-light"><i class="fa-solid fa-rotate-left"></i>مسح الفلاتر</button></div><div class="form-grid card-filters"><div class="field"><label for="card-date-from">من تاريخ</label><input id="card-date-from" class="form-control" type="date"></div><div class="field"><label for="card-date-to">إلى تاريخ</label><input id="card-date-to" class="form-control" type="date"></div><div class="field"><label for="card-vehicle-filter">السيارة</label><select id="card-vehicle-filter" class="form-select searchable"><option value="">كل السيارات</option>${lookup.vehicles.map(vehicle => `<option value="${vehicle.id}">${esc(vehicle.plate)}</option>`).join('')}</select></div><div class="field"><label for="card-stage-filter">المرحلة</label><select id="card-stage-filter" class="form-select">${stageFilters}</select></div></div></section><section class="stage-summary-grid" aria-label="ملخص الكروت حسب المرحلة">${stageSummary}</section><div class="panel" id="grid-area"></div>`);
    enhance();
    $('#card-stage-filter').val(initialStatus);
    const loadGrid = () => {
        grid('cards' + (cardFilterQuery() ? '?' + cardFilterQuery() : ''), [
        col('number', 'رقم الكارت', { className: 'card-code', render: value => bdi(value) }),
        col('vehicle.plate', 'لوحة السيارة', { className: 'card-code', render: value => value ? bdi(value) : '—' }),
        col('date', 'التاريخ', { className: 'card-date', render: value => bdi(value) }),
        col('type', 'نوع الصيانة'),
        { data: 'odometer', title: 'قراءة العداد', className: 'card-number', render: value => bdi(Number(value || 0).toLocaleString('ar-SA')) },
        { data: 'status', title: 'المرحلة الحالية', className: 'card-state-cell', render: value => cardStatusBadge(value) },
        {
            data: null,
            title: 'الإجراءات',
            orderable: false,
            searchable: false,
            className: 'card-actions',
            render: row => `<button class="btn btn-light action-details card-details compact-action" title="عرض تفاصيل الكارت" aria-label="تفاصيل الكارت"><i class="fa-solid fa-eye"></i><span>تفاصيل</span></button>${can('cards.manage') ? '<button class="btn btn-light action-edit card-edit compact-action" title="تعديل الكارت" aria-label="تعديل الكارت"><i class="fa-solid fa-pen"></i><span>تعديل</span></button>' : ''}${can('cards.manage') && row.status !== 'closed' ? '<button class="btn btn-light action-next card-next compact-action" title="نقل الكارت إلى المرحلة التالية" aria-label="المرحلة التالية"><i class="fa-solid fa-arrow-left"></i><span>المرحلة التالية</span></button>' : ''}${row.order ? `<a class="btn btn-light action-details compact-action" title="فتح الطلب المرتبط" aria-label="الطلب المرتبط" href="#order/${row.order.id}"><i class="fa-solid fa-file-invoice"></i><span>الطلب المرتبط</span></a>` : can('orders.create') ? '<button class="btn btn-light action-add card-order compact-action" title="إنشاء طلب مرتبط" aria-label="إنشاء طلب مرتبط"><i class="fa-solid fa-plus"></i><span>إنشاء طلب مرتبط</span></button>' : ''}${can('cards.manage') ? '<button class="btn btn-light action-delete card-delete compact-action" title="حذف الكارت" aria-label="حذف الكارت"><i class="fa-solid fa-trash"></i><span>حذف</span></button>' : ''}`,
        },
        ]);
        $('#records').addClass('maintenance-card-table').closest('.table-responsive').addClass('maintenance-card-table-wrap');
    };
    loadGrid();

    $('#grid-area').on('click', '.card-details', function () {
        cardDetails(table.row($(this).closest('tr')).data());
    }).on('click', '.card-edit', function () {
        cardEditor(table.row($(this).closest('tr')).data());
    }).on('click', '.card-delete', async function () {
        const row = table.row($(this).closest('tr')).data();
        const result = await Swal.fire({ title: 'حذف كارت الصيانة؟', text: `سيتم حذف ${row.number} نهائيًا.`, icon: 'warning', showCancelButton: true, confirmButtonText: 'حذف', cancelButtonText: 'إلغاء', reverseButtons: true });
        if (result.isConfirmed) {
            await api('cards/' + row.id, 'DELETE');
            table.ajax.reload();
            refreshCardSummary();
        }
    }).on('click', '.card-next', async function () {
        const row = table.row($(this).closest('tr')).data();
        const stages = Object.keys(cardStatuses);
        await api('cards/' + row.id, 'PATCH', { status: stages[stages.indexOf(row.status) + 1], notes: row.notes });
        table.ajax.reload();
        refreshCardSummary();
    }).on('click', '.card-order', function () {
        const row = table.row($(this).closest('tr')).data();
        orderForm();
        $('#order-form [name=maintenance_card_id]').val(row.id);
        $('#order-form [name=vehicle_id]').val(row.vehicle_id).trigger('change');
        $('#order-form [name=branch_id]').val(row.branch_id).trigger('change');
    });
    $('#card-date-from, #card-date-to, #card-vehicle-filter, #card-stage-filter').on('change', () => {
        loadGrid();
        refreshCardSummary();
    });
    $('.stage-summary-card').on('click', function () {
        $('#card-stage-filter').val(this.dataset.stage).trigger('change');
    });
    $('#clear-card-filters').on('click', () => {
        $('#card-date-from, #card-date-to, #card-stage-filter').val('');
        $('#card-vehicle-filter').val('').trigger('change');
    });
    refreshCardSummary();
}

function users() {
    $('#content').html(head('المستخدمون', 'إدارة الحسابات والأدوار ونطاق الوصول إلى الفروع.', button('مستخدم جديد', 'user-add')) + '<div class="panel" id="grid-area"></div>');
    grid('admin/users', [
        col('name', 'الاسم'),
        col('email', 'البريد الإلكتروني', { className: 'admin-code', render: value => `<bdi>${esc(value)}</bdi>` }),
        { data: 'roles', title: 'الأدوار', orderable: false, searchable: false, render: roles => renderRolePills(roles) || '—' },
        { data: 'active', title: 'الحالة', className: 'admin-state-cell', render: value => userStatusBadge(value) },
        { data: 'all_branches', title: 'نطاق الفروع', className: 'admin-state-cell', render: value => branchScopeBadge(value) },
        { data: null, title: 'تعديل', orderable: false, searchable: false, className: 'admin-actions', render: () => '<button class="btn btn-light edit-user compact-action" aria-label="تعديل المستخدم"><i class="fa-solid fa-pen"></i><span>تعديل</span></button>' },
    ]);
    $('#records').addClass('admin-table users-table').closest('.table-responsive').addClass('admin-table-wrap');

    $('#records').on('click', '.edit-user', function () {
        userEditor(table.row($(this).closest('tr')).data());
    });
}

async function userEditor(user = {}) {
    const data = await api('admin/roles');
    availableRoles = data;
    ui = { ...ui, role_labels: data.role_labels || ui.role_labels, permission_catalog: data.permission_catalog || ui.permission_catalog, permission_groups: data.permission_groups || ui.permission_groups };

    openModal('بيانات المستخدم', `<div class="form-grid">${field('name', 'الاسم', user.name)}${field('email', 'البريد الإلكتروني', user.email, 'email')}${field('password', user.id ? 'كلمة مرور جديدة' : 'كلمة المرور', '', 'password', !user.id, user.id ? '' : 'minlength="12"')}${select('active', 'الحالة', [{ id: 1, name: 'نشط' }, { id: 0, name: 'معطل' }], user.active === false ? 0 : 1)}${select('all_branches', 'نطاق الفروع', [{ id: 0, name: 'الفروع المحددة' }, { id: 1, name: 'جميع الفروع' }], user.all_branches ? 1 : 0)}${multi('roles', 'الأدوار', data.roles.map(role => ({ id: role.name, name: roleLabel(role.name) })), user.roles?.map(role => role.name))}${multi('branch_ids', 'الفروع', lookup.branches, user.branches?.map(branch => branch.id))}</div>`, (payload, form) => {
        payload.roles = $(form).find('[name=roles]').val();
        payload.branch_ids = $(form).find('[name=branch_ids]').val();
        if (!payload.password) delete payload.password;
        return api('admin/users' + (user.id ? '/' + user.id : ''), user.id ? 'PUT' : 'POST', payload);
    });
}

function permissionEditorHtml(selected) {
    return `
        <div class="permission-toolbar">
            <div>
                <strong>صلاحيات الدور</strong>
                <div class="permission-count" id="selected-count">تم تحديد ${selected.length} صلاحية</div>
            </div>
            <div class="permission-search-row">
                <input type="search" id="permission-search" class="form-control" placeholder="ابحث في الصلاحيات">
            </div>
        </div>
        <div class="permission-grid">
            ${(ui.permission_groups || []).map(group => {
                const permissions = group.permissions.map(name => ({ name, ...permissionMeta(name) }));
                return `<section class="permission-card" data-group="${group.key}">
                    <div class="permission-card-head">
                        <div>
                            <h3>${esc(group.label)}</h3>
                            <p>${permissions.length} صلاحية متاحة</p>
                        </div>
                        <div class="toolbar-inline">
                            <button type="button" class="btn btn-light permission-select-all" data-group="${group.key}">تحديد الكل</button>
                            <button type="button" class="btn btn-light permission-clear-all" data-group="${group.key}">إلغاء الكل</button>
                        </div>
                    </div>
                    <div class="permission-list">
                        ${permissions.map(item => `<label class="permission-line" data-search="${esc((item.label + ' ' + item.description).toLowerCase())}"><input type="checkbox" class="permission-checkbox" value="${esc(item.name)}" ${selected.includes(item.name) ? 'checked' : ''}><div><strong>${esc(item.label)}</strong><span>${esc(item.description)}</span></div></label>`).join('')}
                    </div>
                </section>`;
            }).join('')}
        </div>
    `;
}

function initPermissionEditor(modalRoot) {
    const root = $(modalRoot);
    const updateCount = () => {
        const count = root.find('.permission-checkbox:checked').length;
        root.find('#selected-count').text(`تم تحديد ${count} صلاحية`);
    };

    updateCount();
    root.off('input.permission').on('input.permission', '#permission-search', function () {
        const term = $(this).val().toLowerCase().trim();
        root.find('.permission-line').each(function () {
            $(this).toggle($(this).data('search').includes(term));
        });
    });
    root.off('change.permission').on('change.permission', '.permission-checkbox', updateCount);
    root.off('click.permission').on('click.permission', '.permission-select-all', function () {
        root.find(`.permission-card[data-group="${this.dataset.group}"] .permission-line:visible .permission-checkbox`).prop('checked', true).trigger('change');
    }).on('click.permission', '.permission-clear-all', function () {
        root.find(`.permission-card[data-group="${this.dataset.group}"] .permission-line:visible .permission-checkbox`).prop('checked', false).trigger('change');
    });
}

async function roles() {
    const data = await api('admin/roles');
    availableRoles = data;
    ui = { ...ui, role_labels: data.role_labels || ui.role_labels, permission_catalog: data.permission_catalog || ui.permission_catalog, permission_groups: data.permission_groups || ui.permission_groups };

    $('#content').html(head('الأدوار والصلاحيات', 'عرض عربي منظم للصلاحيات مع بقاء مفاتيح الحفظ الداخلية دون تغيير.', button('دور جديد', 'role-add')) + `<section class="role-summary-grid">${data.roles.map(role => {
        const labels = role.permissions.map(permissionItem => permissionMeta(permissionItem.name).label);
        return `<article class="role-card ${role.name === 'admin' ? 'protected' : ''}">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                <div>
                    <h3>${esc(roleLabel(role.name))}</h3>
                    <p>${ui.role_labels?.[role.name] ? 'دور افتراضي في النظام' : 'دور مخصص'}</p>
                </div>
                <span class="admin-pill role-pill">${role.permissions.length} صلاحية</span>
            </div>
            <p class="list-note">${labels.length ? labels.join('، ') : 'لا توجد صلاحيات محددة لهذا الدور.'}</p>
            <div class="action-bar mb-0 mt-3">${role.name !== 'admin' ? button('تعديل الدور', 'role-edit', 'pen', `data-id="${role.id}"`) : '<span class="subtext">دور مدير النظام محمي</span>'}</div>
        </article>`;
    }).join('')}</section>`);
}

function roleEditor(role = {}) {
    const isSystemRole = !!ui.role_labels?.[role.name];
    const selected = role.permissions?.map(permissionItem => permissionItem.name) || [];
    const nameSection = isSystemRole
        ? `<div class="panel mb-3"><div class="details-grid"><div><small>اسم الدور</small>${esc(roleLabel(role.name))}</div><div><small>نوع الدور</small>دور افتراضي محفوظ</div></div><input type="hidden" name="name" value="${esc(role.name)}"></div>`
        : `<div class="field">${field('name', 'اسم الدور', role.name || '')}<p class="subtext">يمكنك إنشاء دور مخصص باسم عربي مناسب لبيئة العمل.</p></div>`;

    openModal('إعداد الدور', `${nameSection}${permissionEditorHtml(selected)}`, (payload, form) => {
        payload.name = $(form).find('[name=name]').val();
        payload.permissions = $(form).find('.permission-checkbox:checked').map((_, node) => node.value).get();
        return api('admin/roles' + (role.id ? '/' + role.id : ''), role.id ? 'PUT' : 'POST', payload);
    });
    initPermissionEditor('#editor .modal-body');
}

function renderActivityDetails(details) {
    const info = (details.details || []).map(item => `<li><small>${esc(item.label)}</small><b>${esc(item.value)}</b></li>`).join('');
    const changes = (details.changes || []).map(item => `<li><small>${esc(item.field)}</small><div>قبل: <b>${esc(item.before)}</b></div><div>بعد: <b>${esc(item.after)}</b></div></li>`).join('');
    return `<div class="activity-card"><div class="activity-meta"><strong>${esc(details.summary || 'تفاصيل النشاط')}</strong></div>${info ? `<ul class="activity-detail-list">${info}</ul>` : ''}${changes ? `<ul class="activity-change-list">${changes}</ul>` : '<div class="subtext">لا توجد تغييرات تفصيلية إضافية.</div>'}</div>`;
}

function activity() {
    $('#content').html(head('سجل النشاطات', 'متابعة الأنشطة والتغييرات بصياغة عربية واضحة مع احترام صلاحيات الوصول.') + '<div class="panel" id="grid-area"></div>');
    grid('admin/activity', [
        col('created_at', 'الوقت', { className: 'admin-code', render: value => bdi(value) }),
        { data: 'causer.name', title: 'المستخدم', render: value => value ? bdi(value) : 'النظام' },
        col('event_label', 'النشاط', { className: 'admin-state-cell', render: value => activityBadge(value) }),
        { data: null, title: 'السجل المرتبط', render: row => `<div><strong>${esc(row.subject_label || 'سجل')}</strong><div class="subtext">${bdi(row.subject_reference || '—')}</div></div>` },
        { data: 'details', title: 'التفاصيل', orderable: false, searchable: false, render: details => renderActivityDetails(details || {}) },
    ], { order: [[0, 'desc']], pageLength: 8 });
    $('#records').addClass('admin-table activity-table').closest('.table-responsive').addClass('admin-table-wrap');
}

function uploadFile(orderId, collection, file, label = '') {
    const data = new FormData();
    data.append('collection', collection);
    data.append('file', file);
    if (label) data.append('label', label);
    return $.ajax({ url: `/api/orders/${orderId}/media`, method: 'POST', data, processData: false, contentType: false });
}

function photoIcon(label) {
    return ({ front: 'car', back: 'car-rear', right: 'car-side', left: 'car-side', angle_front: 'car', angle_back: 'car-rear', interior: 'couch', odometer: 'gauge-high', damage: 'car-burst', video: 'video' })[label] || 'camera';
}

function vehicleGallery(photos, selectedId) {
    document.getElementById('vehicle-gallery')?.remove();
    const previousFocus = document.activeElement;
    let index = Math.max(0, photos.findIndex(photo => photo.id === selectedId));
    let scale = 1;
    const dialog = document.createElement('dialog');
    dialog.id = 'vehicle-gallery';
    dialog.className = 'vehicle-gallery';
    dialog.setAttribute('aria-label', 'معرض صور السيارة');
    dialog.innerHTML = `<header><div><small>معرض الصور</small><h2 id="gallery-caption"></h2></div><div class="gallery-controls"><span id="gallery-counter" aria-live="polite"></span><button type="button" data-gallery="out" aria-label="تصغير"><i class="fa-solid fa-minus"></i></button><button type="button" data-gallery="zoom" aria-label="تكبير"><i class="fa-solid fa-plus"></i></button><button type="button" data-gallery="reset" aria-label="الحجم الأصلي"><i class="fa-solid fa-expand"></i></button><button type="button" data-gallery="close" aria-label="إغلاق المعرض"><i class="fa-solid fa-xmark"></i></button></div></header><div class="gallery-stage"><button type="button" class="gallery-prev" data-gallery="prev" aria-label="الصورة السابقة"><i class="fa-solid fa-chevron-right"></i></button><div class="gallery-canvas"><img id="gallery-image" alt=""><p id="gallery-error" hidden>تعذر تحميل الصورة. جرّب صورة أخرى.</p></div><button type="button" class="gallery-next" data-gallery="next" aria-label="الصورة التالية"><i class="fa-solid fa-chevron-left"></i></button></div><nav class="gallery-thumbs" aria-label="الصور المصغرة">${photos.map((photo, i) => `<button type="button" data-index="${i}" aria-label="عرض ${esc(lookup.photo_labels[photo.label] || photo.name)}"><img src="${esc(photo.url)}" alt="" loading="lazy"></button>`).join('')}</nav><footer>استخدم الأسهم للتنقل • Escape للإغلاق • انقر مرتين على الصورة للتكبير</footer>`;
    document.body.append(dialog);
    const image = dialog.querySelector('#gallery-image');
    const zoom = amount => { scale = Math.min(3, Math.max(1, amount)); image.style.transform = `scale(${scale})`; image.classList.toggle('is-zoomed', scale > 1); };
    const show = () => {
        const photo = photos[index];
        zoom(1); image.hidden = false;
        dialog.querySelector('#gallery-error').hidden = true;
        image.src = photo.url; image.alt = lookup.photo_labels[photo.label] || photo.name;
        dialog.querySelector('#gallery-caption').textContent = image.alt;
        dialog.querySelector('#gallery-counter').textContent = `${index + 1} / ${photos.length}`;
        dialog.querySelectorAll('[data-index]').forEach((button, i) => { button.classList.toggle('active', i === index); button.setAttribute('aria-current', String(i === index)); });
        dialog.querySelector(`[data-index="${index}"]`).scrollIntoView({ block: 'nearest', inline: 'center' });
    };
    image.onerror = () => { image.hidden = true; dialog.querySelector('#gallery-error').hidden = false; };
    const move = offset => { index = (index + offset + photos.length) % photos.length; show(); };
    dialog.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.index !== undefined) { index = Number(button.dataset.index); show(); return; }
        switch (button.dataset.gallery) {
            case 'close': dialog.close(); break;
            case 'prev': move(-1); break;
            case 'next': move(1); break;
            case 'zoom': zoom(scale + .5); break;
            case 'out': zoom(scale - .5); break;
            case 'reset': zoom(1); break;
        }
    });
    image.addEventListener('dblclick', () => zoom(scale === 1 ? 2 : 1));
    dialog.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); move(event.key === 'ArrowLeft' ? 1 : -1); }
    });
    let startX = null;
    image.addEventListener('touchstart', event => { startX = event.touches[0].clientX; }, { passive: true });
    image.addEventListener('touchend', event => { if (scale === 1 && startX !== null && Math.abs(event.changedTouches[0].clientX - startX) > 50) move(event.changedTouches[0].clientX > startX ? -1 : 1); startX = null; });
    const overflow = document.body.style.overflow;
    dialog.addEventListener('close', () => { document.body.style.overflow = overflow; dialog.remove(); previousFocus?.focus(); }, { once: true });
    dialog.showModal(); document.body.style.overflow = 'hidden'; show();
    dialog.querySelector('[data-gallery="close"]').focus();
}

function vehicleUpload() {
    ensureOrderDetailStyle();
    const order = currentOrder;
    const collection = order.status === 'draft' ? 'photos_before' : 'photos_after';
    const labels = { ...lookup.photo_labels, video: 'فيديو السيارة (اختياري)' };
    const pending = new Map();
    const urls = new Map();
    const existing = new Set(order.media.filter(m => m.collection === collection).map(m => m.label));
    let stream = null;
    let cameraLabel = null;
    let uploading = false;
    const stopCamera = () => { stream?.getTracks().forEach(track => track.stop()); stream = null; $('#vehicle-camera').prop('hidden', true); };
    const slot = label => $(`#vehicle-slot-${label}`);
    const status = (label, text, error = false) => slot(label).find('.vehicle-slot-status').text(text).toggleClass('text-danger', error);
    const setFile = (label, file) => {
        if (!file) return;
        const valid = label === 'video' ? file.type === 'video/mp4' : ['image/jpeg', 'image/png', 'image/webp'].includes(file.type);
        if (!valid || file.size > 10 * 1024 * 1024) {
            status(label, !valid ? (label === 'video' ? 'اختر فيديو MP4.' : 'اختر صورة JPG أو PNG أو WebP.') : 'الحد الأقصى 10 ميجابايت لكل ملف.', true);
            return;
        }
        if (urls.has(label)) URL.revokeObjectURL(urls.get(label));
        const url = URL.createObjectURL(file);
        urls.set(label, url);
        pending.set(label, file);
        slot(label).find('.vehicle-slot-preview').html(label === 'video' ? `<video controls src="${url}"></video>` : `<img src="${url}" alt="${esc(labels[label])}">`);
        status(label, `جاهز للرفع: ${file.name}`);
        slot(label).find('.vehicle-remove').prop('hidden', false);
    };
    const fileControl = (label, camera = false) => `<label class="btn btn-${camera ? 'primary' : 'light'}"><i class="fa-solid fa-${camera ? 'camera' : 'folder-open'}"></i>${camera ? 'كاميرا الهاتف' : 'اختيار ملف'}<input class="vehicle-file-input" data-slot="${label}" type="file" accept="${label === 'video' ? 'video/mp4' : 'image/jpeg,image/png,image/webp'}" ${camera ? 'capture="environment"' : ''} aria-label="${camera ? 'تصوير' : 'اختيار'} ${esc(labels[label])}"></label>`;
    openModal(collection === 'photos_before' ? 'صور السيارة والفيديو — قبل الإصلاح' : 'صور السيارة والفيديو — بعد الإصلاح', `
        <div class="vehicle-upload-toolbar"><label class="btn btn-primary"><i class="fa-solid fa-images"></i>اختيار عدة صور<input id="vehicle-bulk" class="vehicle-file-input" type="file" multiple accept="image/jpeg,image/png,image/webp" aria-label="اختيار عدة صور"></label><p>اختر الصور معًا ثم راجع توزيعها على الزوايا. يمكنك تغيير زاوية كل صورة قبل الرفع. الحد الأقصى لكل صورة أو فيديو: 10 ميجابايت.</p></div>
        <div id="vehicle-camera" class="vehicle-camera" hidden><video id="vehicle-live" autoplay muted playsinline></video><div class="action-bar"><button type="button" id="vehicle-snap" class="btn btn-primary">التقاط الصورة</button><button type="button" id="vehicle-camera-close" class="btn btn-light">إغلاق الكاميرا</button></div></div>
        <div class="vehicle-upload-grid">${Object.entries(labels).map(([label, name], index) => `<section class="vehicle-slot" id="vehicle-slot-${label}"><h3>${index + 1}. ${esc(name)}</h3><div class="vehicle-slot-preview"><i class="fa-solid fa-${photoIcon(label)}"></i></div>${label !== 'video' ? `<select class="form-select vehicle-angle" data-slot="${label}" aria-label="تغيير زاوية ${esc(name)}">${Object.entries(lookup.photo_labels).map(([key, value]) => `<option value="${key}" ${key === label ? 'selected' : ''}>${esc(value)}</option>`).join('')}</select>` : ''}<div class="vehicle-slot-actions">${fileControl(label)}${label !== 'video' ? `${fileControl(label, true)}<button class="btn btn-light vehicle-camera-open" data-slot="${label}" type="button">فتح الكاميرا</button>` : ''}<button type="button" class="btn btn-light vehicle-remove" data-slot="${label}" hidden>إلغاء الاختيار</button></div><span class="vehicle-slot-status">${existing.has(label) ? 'توجد صورة محفوظة لهذه الزاوية' : 'لم يتم اختيار ملف'}</span></section>`).join('')}</div>
        <div id="vehicle-upload-progress" class="vehicle-upload-progress" role="status" aria-live="polite">اختر الملفات ثم اضغط «رفع الملفات المختارة» مرة واحدة.</div>`, async () => {
        if (!pending.size) { $('#vehicle-upload-progress').text('اختر صورة أو فيديو أولًا.'); throw new Error('files-required'); }
        stopCamera(); uploading = true;
        $('#editor [data-bs-dismiss]').prop('disabled', true);
        $('#editor .modal-body input, #editor .modal-body button, #editor .modal-body select').prop('disabled', true);
        let completed = 0;
        const entries = [...pending.entries()];
        try {
            for (const [label, file] of entries) {
                $('#vehicle-upload-progress').text(`جارٍ رفع ${completed + 1} من ${entries.length}…`);
                try {
                    await uploadFile(order.id, label === 'video' ? 'vehicle_video' : collection, file, label === 'video' ? '' : label);
                    pending.delete(label); existing.add(label); completed++;
                    slot(label).find('.vehicle-remove').prop('hidden', true);
                    status(label, 'تم الرفع بنجاح');
                } catch (error) {
                    status(label, error.responseJSON?.message || 'تعذر رفع الملف. يمكنك إعادة المحاولة.', true);
                }
            }
            $('#vehicle-upload-progress').text(`تم رفع ${completed} من ${entries.length}.${pending.size ? ' أعد المحاولة لرفع الملفات المتبقية فقط.' : ''}`);
            if (pending.size) throw new Error('upload-incomplete');
        } finally {
            uploading = false;
            $('#editor [data-bs-dismiss], #editor .modal-body input, #editor .modal-body button, #editor .modal-body select').prop('disabled', false);
        }
    });
    $('#editor-form button[type=submit]').text('رفع الملفات المختارة');
    Object.keys(labels).forEach(label => {
        const saved = [...order.media].reverse().find(m => label === 'video' ? m.collection === 'vehicle_video' : m.collection === collection && m.label === label);
        if (saved) slot(label).find('.vehicle-slot-preview').html(label === 'video' ? `<video controls preload="metadata" src="${esc(saved.url)}"></video>` : `<img src="${esc(saved.url)}" alt="${esc(labels[label])}">`);
    });
    $('#editor .vehicle-slot-preview img').on('error', function () { $(this).replaceWith('<i class="fa-solid fa-image" title="تعذر تحميل الصورة المحفوظة"></i>'); });
    $('#editor').off('.vehicleUpload').on('hide.bs.modal.vehicleUpload', event => { if (uploading) event.preventDefault(); }).on('hidden.bs.modal.vehicleUpload', () => {
        stopCamera(); urls.forEach(url => URL.revokeObjectURL(url));
        $('#editor-form button[type=submit]').text('حفظ البيانات');
        $('#editor').off('.vehicleUpload');
    });
    $('#editor .vehicle-file-input[data-slot]').on('change', function () { setFile(this.dataset.slot, this.files[0]); this.value = ''; });
    $('#vehicle-bulk').on('change', function () {
        const available = Object.keys(lookup.photo_labels).filter(key => !pending.has(key) && !existing.has(key));
        const files = [...this.files];
        files.slice(0, available.length).forEach((file, index) => setFile(available[index], file));
        $('#vehicle-upload-progress').text(files.length > available.length ? `تم توزيع ${available.length} صور. الزوايا ممتلئة؛ لإضافة صورة لزاوية محفوظة استخدم «اختيار ملف» في بطاقتها.` : 'راجع الزوايا المعينة للصور ثم ارفع الملفات المختارة.');
        this.value = '';
    });
    $('.vehicle-remove').on('click', function () { const label = this.dataset.slot; pending.delete(label); URL.revokeObjectURL(urls.get(label)); urls.delete(label); slot(label).find('.vehicle-slot-preview').html(`<i class="fa-solid fa-${photoIcon(label)}"></i>`); status(label, existing.has(label) ? 'توجد صورة محفوظة لهذه الزاوية' : 'لم يتم اختيار ملف'); $(this).prop('hidden', true); });
    $('.vehicle-angle').on('change', function () {
        const from = this.dataset.slot, to = this.value;
        const a = pending.get(from), b = pending.get(to);
        if (a) setFile(to, a); else slot(to).find('.vehicle-remove').trigger('click');
        if (b) setFile(from, b); else slot(from).find('.vehicle-remove').trigger('click');
        this.value = from;
    });
    $('.vehicle-camera-open').on('click', async function () {
        stopCamera(); cameraLabel = this.dataset.slot;
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('camera-unavailable');
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            if (!document.getElementById('vehicle-live') || !$('#editor').hasClass('show')) { stopCamera(); return; }
            $('#vehicle-live')[0].srcObject = stream;
            $('#vehicle-camera').prop('hidden', false)[0].scrollIntoView({ block: 'center' });
        } catch { status(cameraLabel, 'تعذر فتح الكاميرا. اسمح بالوصول إليها أو استخدم كاميرا الهاتف / اختيار ملف.', true); }
    });
    $('#vehicle-camera-close').on('click', stopCamera);
    $('#vehicle-snap').on('click', () => {
        const video = $('#vehicle-live')[0];
        if (!video.videoWidth) return;
        const label = cameraLabel;
        const canvas = document.createElement('canvas'); canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob(blob => { if (blob) setFile(label, new File([blob], `${label}-${Date.now()}.jpg`, { type: 'image/jpeg' })); }, 'image/jpeg', .9);
        stopCamera();
    });
}

function upload() {
    const collections = currentOrder.status === 'draft'
        ? [{ id: 'quote', name: 'عرض السعر' }, { id: 'photos_before', name: 'صور قبل الإصلاح' }, { id: 'attachments', name: 'مرفقات إضافية' }]
        : [{ id: 'photos_after', name: 'صور بعد الإصلاح' }, ...(can('invoices.manage') ? [{ id: 'invoice', name: 'فاتورة المورد' }] : []), ...(can('payments.create') ? [{ id: 'proof', name: 'إثبات الحوالة' }] : [])];

    openModal('رفع المرفقات', `<div class="form-grid">${select('collection', 'نوع المرفق', collections, collections[0]?.id)}${select('label', 'زاوية الصورة', Object.entries(lookup.photo_labels).map(([id, name]) => ({ id, name })), 'front')}</div><div class="action-bar"><label class="btn btn-light"><i class="fa-solid fa-upload"></i>اختيار ملف<input id="upload-file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4" hidden></label><label class="btn btn-primary"><i class="fa-solid fa-camera"></i>التقاط صورة<input id="camera-file" type="file" accept="image/*" capture="environment" hidden></label></div><div id="file-preview" class="text-secondary">الحد الأقصى لحجم الملف 10 ميجابايت.</div>`, async () => {
        const file = $('#upload-file')[0].files[0] || $('#camera-file')[0].files[0];
        if (!file) {
            toastr.error('اختر ملفًا أو التقط صورة أولًا.');
            throw new Error('file-required');
        }
        return uploadFile(currentOrder.id, $('[name=collection]').val(), file, $('[name=label]').val());
    });

    $('#upload-file,#camera-file').on('change', function () {
        const other = this.id === 'upload-file' ? '#camera-file' : '#upload-file';
        $(other).val('');
        const file = this.files[0];
        $('#file-preview').text(file ? file.name : 'لم يتم اختيار أي ملف.');
        if (file?.type.startsWith('image/')) {
            const image = new Image();
            image.className = 'photo-preview mt-2';
            image.onload = () => URL.revokeObjectURL(image.src);
            image.src = URL.createObjectURL(file);
            $('#file-preview').append(image);
        }
    });
}

function documentForm(kind) {
    const order = currentOrder;
    const existing = kind === 'receipt' ? order.receipt : order.invoice;
    let body = `<div class="form-grid">${field('date', 'التاريخ', existing?.date || today(), 'date')}${kind === 'invoice' ? field('number', 'رقم الفاتورة', existing?.number || '') + field('total', 'قيمة الفاتورة', existing ? existing.total_minor / 100 : order.total_minor / 100, 'number') + select('media_id', 'ملف الفاتورة المرفوع', order.media.filter(media => media.collection === 'invoice').map(media => ({ id: media.id, name: media.name })), existing?.media_id) : ''}</div><p class="mt-3">أدخل الكميات الفعلية لكل بند، وارفع ملف الفاتورة قبل حفظ بياناتها.</p>`;
    order.lines.forEach(line => {
        body += field('q-' + line.id, line.description, (existing?.lines.find(item => item.order_line_id === line.id)?.quantity_milli ?? line.quantity_milli) / 1000, 'number');
    });
    openModal(kind === 'receipt' ? 'تسجيل الاستلام الفعلي' : 'بيانات فاتورة المورد', body, data => {
        data.lines = order.lines.map(line => ({ order_line_id: line.id, quantity: data['q-' + line.id] }));
        return api('orders/' + order.id + '/' + kind, 'POST', data);
    });
}

$('#editor-form').on('submit', async function (event) {
    event.preventDefault();
    clearModalErrors(this);
    const buttonNode = $(this).find('[type=submit]').prop('disabled', true).addClass('is-loading').attr('aria-busy', 'true');
    try {
        await modalSave(formData(this), this);
        bootstrap.Modal.getInstance('#editor').hide();
        toastr.success('تم حفظ البيانات بنجاح.');
        await refreshLookups();
        await route();
    } catch (error) {
        if (error?.status === 422) {
            applyModalErrors(this, error.responseJSON?.errors || {});
        }
    } finally {
        buttonNode.prop('disabled', false).removeClass('is-loading').removeAttr('aria-busy');
    }
});

$(document).on('click', '.remove-line', async function () {
    const row = $(this).closest('tr');
    if ($('#lines tr').length <= 1) {
        applyOrderErrors({ lines: ['يجب إضافة بند واحد على الأقل.'] });
        return;
    }
    const result = await Swal.fire({
        title: 'حذف البند؟',
        text: 'سيتم حذف هذا البند من المسودة عند الحفظ.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'حذف',
        cancelButtonText: 'إلغاء',
        reverseButtons: true,
    });
    if (result.isConfirmed) {
        row.remove();
        reindexOrderLineErrors();
        refreshOrderTotals();
    }
}).on('click', '[data-quick]', async function () {
    const kind = this.dataset.quick;
    const target = this.dataset.target;
    const container = $(this).closest('.field');
    const result = await Swal.fire({
        title: 'إضافة سجل جديد',
        html: `<form id="quick-form">${masterFields(kind)}</form>`,
        width: 900,
        showCancelButton: true,
        confirmButtonText: 'حفظ واختيار',
        cancelButtonText: 'إلغاء',
        reverseButtons: true,
        preConfirm: async () => {
            const form = document.querySelector('#quick-form');
            if (!form.reportValidity()) return false;
            try {
                return await api('masters/' + kind, 'POST', formData(form));
            } catch (error) {
                Swal.showValidationMessage('تحقق من البيانات المطلوبة ثم أعد المحاولة.');
                return false;
            }
        },
    });
    if (result.isConfirmed) {
        await refreshLookups();
        const record = result.value;
        container.find('select').append(new Option(record.name || record.plate, record.id, true, true)).trigger('change');
    }
}).on('click', '[data-action]', async function () {
    const action = this.dataset.action;
    switch (action) {
        case 'line-add':
            addLine();
            clearOrderFieldError('lines');
            break;
        case 'edit-order':
            orderForm(currentOrder);
            break;
        case 'upload':
            upload();
            break;
        case 'vehicle-upload':
            vehicleUpload();
            break;
        case 'relabel-photo': {
            const media = currentOrder.media.find(m => m.id === Number(this.dataset.id));
            if (!media) break;
            openModal('إعادة تعيين زاوية الصورة', `<img class="relabel-photo-preview" src="${esc(media.url)}" alt="الصورة المحددة">${select('label', 'الزاوية الجديدة', Object.entries(lookup.photo_labels).map(([id, name]) => ({ id, name })), media.label)}<p class="subtext mt-3">تُحفظ الصورة تحت الزاوية المختارة. يمكنك إضافة صورة جديدة للزاوية الناقصة من «إضافة الصور والفيديو».</p>`, data => $.ajax({ url: '/media/' + media.id, method: 'PATCH', data: { label: data.label } }));
            break;
        }
        case 'receipt':
        case 'invoice':
            documentForm(action);
            break;
        case 'transition': {
            const next = this.dataset.next;
            if (next === 'submit') {
                const missing = [];
                if (!currentOrder.quote_number?.trim()) missing.push('رقم عرض السعر');
                if (!currentOrder.media.some(media => media.collection === 'quote')) missing.push('ملف عرض السعر بصيغة PDF');
                if (currentOrder.vehicle_id) {
                    const requiredPhotos = Object.keys(lookup.photo_labels || {});
                    const uploadedPhotos = currentOrder.media
                        .filter(media => media.collection === 'photos_before')
                        .map(media => media.label);
                    if (requiredPhotos.some(label => !uploadedPhotos.includes(label))) missing.push('صور السيارة التسع قبل الإصلاح');
                }
                if (missing.length) {
                    toastr.error(`أكمل البيانات التالية قبل الإرسال للمراجعة: ${missing.join('، ')}.`);
                    return;
                }
            }
            const needsReason = ['return', 'reject'].includes(next);
            const result = await Swal.fire({
                title: 'تأكيد الإجراء',
                text: next === 'submit' ? 'تم استيفاء رقم عرض السعر ومستند العرض. هل تريد إرسال الطلب للمراجعة؟' : next === 'match' ? 'سيجري النظام مطابقة المستندات والكميات والقيمة على الخادم.' : 'هل تريد تنفيذ هذا الإجراء الآن؟',
                input: needsReason ? 'textarea' : undefined,
                inputLabel: needsReason ? 'سبب الإجراء' : undefined,
                showCancelButton: true,
                confirmButtonText: 'تأكيد',
                cancelButtonText: 'إلغاء',
                reverseButtons: true,
                inputValidator: needsReason ? value => !value?.trim() ? 'سبب الإجراء مطلوب.' : undefined : undefined,
            });
            if (result.isConfirmed) {
                await api(`orders/${currentOrder.id}/actions/${next}`, 'POST', needsReason ? { reason: result.value.trim() } : {});
                await route();
            }
            break;
        }
        case 'payment':
            openModal('تسجيل الحوالة', `<div class="form-grid">${field('reference', 'مرجع التحويل')}${field('date', 'التاريخ', today(), 'date')}${field('amount', 'القيمة', currentOrder.total_minor / 100, 'number')}${select('media_id', 'إثبات الحوالة المرفوع', currentOrder.media.filter(media => media.collection === 'proof').map(media => ({ id: media.id, name: media.name })))}</div>`, data => api('orders/' + currentOrder.id + '/payment', 'POST', data));
            break;
        case 'delete-media': {
            const result = await Swal.fire({ title: 'حذف المرفق', text: 'سيتم حذف هذا المرفق نهائيًا من الطلب.', showCancelButton: true, confirmButtonText: 'حذف', cancelButtonText: 'إلغاء', reverseButtons: true });
            if (result.isConfirmed) {
                await $.ajax({ url: '/media/' + this.dataset.id, method: 'DELETE' });
                await route();
            }
            break;
        }
        case 'master-add':
            masterEditor(this.dataset.kind);
            break;
        case 'movement':
            movement(this.dataset.type);
            break;
        case 'balances':
            inventoryTable(false, new URLSearchParams(location.hash.split('?')[1] || '').get('low_stock') === '1');
            break;
        case 'movements':
            inventoryTable(true);
            break;
        case 'card-add':
            openModal('كارت صيانة جديد', `<div class="form-grid">${select('vehicle_id', 'السيارة', lookup.vehicles)}${field('date', 'التاريخ', today(), 'date')}${field('type', 'نوع الصيانة', 'صيانة دورية')}${field('odometer', 'العداد', 0, 'number')}${textareaField('notes', 'ملاحظات', '', false, 3)}</div>`, data => api('cards', 'POST', data));
            break;
        case 'user-add':
            await userEditor();
            break;
        case 'role-add':
            roleEditor();
            break;
        case 'role-edit':
            roleEditor(availableRoles.roles.find(role => role.id === Number(this.dataset.id)));
            break;
        case 'import':
            openModal('استيراد ' + kinds[this.dataset.kind], '<p class="subtext">استخدم نموذج الاستيراد المعتمد. العملية تُحفظ كاملة أو تُلغى بالكامل عند ظهور أي خطأ، وبحد أقصى 2000 صف.</p><input class="form-control" type="file" name="file" accept=".xlsx,.xls,.csv" required>', (data, form) => $.ajax({ url: '/api/excel/import/' + this.dataset.kind, method: 'POST', data: new FormData(form), processData: false, contentType: false }));
            break;
    }
});

$('#menu-toggle').on('click', () => $('#sidebar').toggleClass('open'));
window.addEventListener('hashchange', route);

(async () => {
    try {
        me = await api('me');
        await refreshLookups();
        nav();
        initGlobalSearch();
        await route();
    } catch (error) {
        $('#content').html('<div class="panel">تعذر الاتصال بالخادم في الوقت الحالي.</div>');
    }
})();
