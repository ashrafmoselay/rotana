/* Rotana server-backed application. All mutations require Laravel session + CSRF. */
'use strict';

let lookup = {}, me = {}, currentOrder = null, table = null, chart = null, modalSave = null, availableRoles = null;
let ui = window.rotanaUi || {};

const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const bdi = value => `<bdi>${esc(value ?? '—')}</bdi>`;
const money = value => (Number(value || 0) / 100).toLocaleString('ar-SA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);
const can = permissionName => me.permissions?.includes(permissionName);
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

function head(title, sub = '', actions = '') {
    return `<div class="page-head"><div><div class="breadcrumb-line">الرئيسية / ${esc(title)}</div><h1>${esc(title)}</h1>${sub ? `<p class="subtext">${esc(sub)}</p>` : ''}</div><div class="action-bar">${actions}</div></div>`;
}

function button(label, action, icon = 'plus', extra = '') {
    return `<button type="button" class="btn btn-primary" data-action="${action}" ${extra}><i class="fa-solid fa-${icon}"></i>${label}</button>`;
}

function openModal(title, body, save) {
    $('#editor .modal-title').text(title);
    $('#editor .modal-body').html(body);
    modalSave = save;
    enhance('#editor');
    bootstrap.Modal.getOrCreateInstance('#editor').show();
}

function enhance(root = document) {
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
    });
}

function col(data, title, extra = {}) {
    return { data, title, ...extra };
}

function statusLabel(value) {
    return statuses[value] || 'حالة غير معروفة';
}

function movementLabel(value) {
    return ui.movement_type_labels?.[value] || movementTypes[value] || 'حركة غير معروفة';
}

function cardStatusLabel(value) {
    return ui.card_status_labels?.[value] || cardStatuses[value] || 'حالة غير معروفة';
}

function renderRolePills(roles) {
    return roles.map(role => `<span class="badge">${esc(roleLabel(role.name || role))}</span>`).join(' ');
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

    const hash = location.hash.slice(1) || 'dashboard';
    $('.nav-item').removeClass('active').filter(`[href="#${hash.split('/')[0]}"]`).addClass('active');
    $('#sidebar').removeClass('open');

    try {
        if (hash.startsWith('order/')) {
            return await detail(hash.split('/')[1]);
        }
        if (hash === 'new') {
            return orderForm();
        }
        if (hash.startsWith('master/')) {
            return masters(hash.split('/')[1]);
        }

        switch (hash) {
            case 'dashboard': return await dashboard();
            case 'orders': return orders(false);
            case 'reports': return orders(true);
            case 'inventory': return inventory();
            case 'cards': return cards();
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
                <div>
                    <h2>${esc(label)}</h2>
                    <p>طلبات مرتبطة، اعتماد متدرج، ومرفقات متابعة جاهزة.</p>
                </div>
                <div class="service-icon"><i class="fa-solid fa-${['screwdriver-wrench', 'car-burst', 'gears', 'bolt', 'building', 'boxes-stacked', 'file-invoice'][index] || 'folder-open'}"></i></div>
            </div>
            <a href="#orders" data-category="${key}">عرض الطلبات</a>
        </article>
    `).join('');

    $('#content').html(
        head('لوحة التحكم', 'متابعة مؤشرات التشغيل والمشتريات والصيانة من شاشة واحدة.', can('orders.create') ? '<a href="#new" class="btn btn-primary"><i class="fa-solid fa-plus"></i>طلب شراء جديد</a>' : '') +
        `<div class="stats">${[
            [Object.values(data.counts).reduce((sum, count) => sum + Number(count), 0), 'إجمالي الطلبات', 'file-invoice'],
            [money(data.order_total_minor), 'قيمة الطلبات', 'sack-dollar'],
            [money(data.paid_minor), 'إجمالي المدفوع', 'money-bill-transfer'],
            [data.vehicles, 'السيارات النشطة', 'car'],
        ].map(stat => `<div class="stat"><i class="fa-solid fa-${stat[2]}"></i><div><strong>${stat[0]}</strong><span>${stat[1]}</span></div></div>`).join('')}</div>
        <div class="service-grid">${categories}</div>
        <div class="dashboard-bottom">
            <section class="panel">
                <h2 class="section-title">توزيع حالات الطلبات</h2>
                <div id="chart"></div>
            </section>
            <section class="panel">
                <h2 class="section-title">ملخص المتابعة</h2>
                <p>كروت الصيانة المفتوحة: <b>${data.cards}</b></p>
                <p>أصناف أقل من حد إعادة الطلب: <b>${data.low_stock}</b></p>
                ${(data.recent || []).length ? data.recent.map(order => `<a class="quick-link" href="#order/${order.id}"><span><i class="fa-solid fa-file-lines"></i> ${bdi(order.number)}</span><small>${esc(statusLabel(order.status))}</small></a>`).join('') : '<p class="subtext">لا توجد طلبات حديثة.</p>'}
            </section>
        </div>`
    );

    chart = new ApexCharts(document.querySelector('#chart'), {
        chart: { type: 'bar', height: 300, toolbar: { show: false }, fontFamily: 'Cairo, system-ui, sans-serif' },
        series: [{ name: 'الطلبات', data: Object.values(data.counts).map(Number) }],
        xaxis: { categories: Object.keys(data.counts).map(key => statusLabel(key)), labels: { style: { fontFamily: 'Cairo, system-ui, sans-serif' } } },
        yaxis: { labels: { style: { fontFamily: 'Cairo, system-ui, sans-serif' } } },
        tooltip: { theme: 'light' },
        colors: ['#00a5b4'],
        dataLabels: { enabled: false },
    });
    chart.render();
}

function excelButtons(kind) {
    return `${can('excel.export') ? `<a class="btn btn-light" id="export-link" href="/api/excel/export/${kind}"><i class="fa-solid fa-file-excel"></i>تصدير إكسل</a>` : ''}${kind !== 'orders' && can('excel.import') && can(permission(kind)) ? `<a class="btn btn-light" href="/api/excel/template/${kind}"><i class="fa-solid fa-download"></i>تحميل نموذج الاستيراد</a>${button('استيراد إكسل', 'import', 'file-import', `data-kind="${kind}"`)}` : ''}`;
}

function orders(report = false) {
    $('#content').html(
        head(report ? 'تقارير الطلبات' : 'طلبات الشراء', 'متابعة الدورة من تسجيل الطلب إلى المطابقة والدفع.', (!report && can('orders.create') ? '<a class="btn btn-primary" href="#new"><i class="fa-solid fa-plus"></i>طلب شراء جديد</a>' : '') + excelButtons('orders')) +
        `<div class="panel">
            <form id="filters" class="form-grid">
                ${select('status', 'الحالة', Object.entries(statuses).map(([id, name]) => ({ id, name })), '', '', { placeholder: 'كل الحالات' })}
                ${select('branch_id', 'الفرع', lookup.branches, '', '', { placeholder: 'كل الفروع' })}
                ${select('vehicle_id', 'السيارة', lookup.vehicles, '', '', { placeholder: 'كل السيارات' })}
                ${select('category', 'نوع الطلب', Object.entries(lookup.categories).map(([id, name]) => ({ id, name })), '', '', { placeholder: 'كل الأنواع' })}
                ${field('from', 'من تاريخ', '', 'date', false)}
                ${field('to', 'إلى تاريخ', '', 'date', false)}
                <div class="field"><label>&nbsp;</label><button class="btn btn-light w-100"><i class="fa-solid fa-filter"></i>تطبيق التصفية</button></div>
            </form>
        </div>
        <div class="panel" id="grid-area"></div>`
    );

    enhance();
    grid('orders', [
        col('number', 'رقم الطلب', { render: value => bdi(value) }),
        col('date', 'التاريخ'),
        col('branch_name', 'الفرع'),
        col('supplier_name', 'المورد'),
        col('vehicle_plate', 'السيارة', { render: value => value ? bdi(value) : '—' }),
        col('total', 'الإجمالي'),
        col('status_label', 'الحالة'),
        { data: 'id', title: 'التفاصيل', orderable: false, searchable: false, render: id => `<a class="btn btn-light" href="#order/${Number(id)}"><i class="fa-solid fa-eye"></i>عرض</a>` },
    ]);

    $('#filters').on('submit', function (event) {
        event.preventDefault();
        const query = new URLSearchParams(formData(this));
        table.ajax.url('/api/orders?' + query).load();
        $('#export-link').attr('href', '/api/excel/export/orders?' + query);
    });
}

function addLine(line = {}) {
    const row = $(`
        <tr>
            <td>${select('item', 'الصنف', lookup.items, line.item_id, 'items')}</td>
            <td><input aria-label="الكمية" name="quantity" class="form-control" type="number" min="0.001" step="0.001" required value="${line.quantity_milli ? line.quantity_milli / 1000 : 1}"></td>
            <td><input aria-label="سعر الوحدة" name="price" class="form-control" type="number" min="0" step="0.01" required value="${line.unit_price_minor ? line.unit_price_minor / 100 : 0}"></td>
            <td><button type="button" class="btn btn-light remove-line" aria-label="حذف البند"><i class="fa-solid fa-trash"></i></button></td>
        </tr>
    `);
    $('#lines').append(row);
    enhance(row);
}

function orderForm(order = null) {
    currentOrder = order;
    $('#content').html(
        head(order ? 'تعديل ' + order.number : 'طلب شراء جديد', 'أدخل بيانات الطلب والبنود ثم احفظ المسودة قبل الإرسال.') +
        `<form id="order-form">
            <section class="panel">
                <h2 class="section-title"><span><i class="fa-solid fa-file-invoice"></i>بيانات الطلب</span></h2>
                <div class="form-grid">
                    ${select('category', 'نوع الطلب', Object.entries(lookup.categories).map(([id, name]) => ({ id, name })), order?.category || 'maintenance')}
                    ${field('date', 'التاريخ', order?.date || today(), 'date')}
                    ${select('priority', 'الأولوية', [{ id: 'normal', name: 'عادي' }, { id: 'urgent', name: 'عاجل' }, { id: 'critical', name: 'عاجل جدًا' }], order?.priority || 'normal')}
                    ${select('branch_id', 'الفرع', lookup.branches, order?.branch_id, 'branches')}
                    ${select('cost_center_id', 'مركز التكلفة', lookup.cost_centers, order?.cost_center_id, 'cost-centers')}
                    ${select('supplier_id', 'المورد', lookup.suppliers, order?.supplier_id, 'suppliers')}
                    ${select('vehicle_id', 'السيارة', lookup.vehicles, order?.vehicle_id, 'vehicles')}
                    ${select('warehouse_id', 'مخزن التوريد', lookup.warehouses, order?.warehouse_id, 'warehouses')}
                    ${field('maintenance_card_id', 'رقم كارت الصيانة الداخلي', order?.maintenance_card_id || '', 'number', false)}
                    ${field('quote_number', 'رقم عرض السعر', order?.quote_number || '', 'text', false)}
                    ${field('tax_percent', 'نسبة الضريبة', order ? order.tax_basis_points / 100 : 15, 'number')}
                    ${textareaField('notes', 'ملاحظات الطلب', order?.notes || '', false, 4)}
                </div>
            </section>
            <section class="panel">
                <h2 class="section-title">بنود الطلب ${button('إضافة بند', 'line-add')}</h2>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>إجراء</th></tr></thead>
                        <tbody id="lines"></tbody>
                    </table>
                </div>
                <p class="subtext">يحتسب الخادم الإجمالي والضريبة بعد الحفظ، ويمكن إرفاق المستندات من شاشة تفاصيل المسودة.</p>
            </section>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i>حفظ المسودة والمتابعة</button>
        </form>`
    );

    enhance();
    (order?.lines || [{}]).forEach(addLine);
    $('#order-form').on('submit', async function (event) {
        event.preventDefault();
        const data = formData(this);
        ['vehicle_id', 'warehouse_id', 'maintenance_card_id', 'quote_number'].forEach(key => data[key] = data[key] || null);
        data.lines = $('#lines tr').map(function () {
            return {
                item_id: $(this).find('[name=item]').val(),
                quantity: $(this).find('[name=quantity]').val(),
                unit_price: $(this).find('[name=price]').val(),
            };
        }).get();

        const submitButton = $(this).find('[type=submit]').prop('disabled', true);
        try {
            const saved = await api(order ? 'orders/' + order.id : 'orders', order ? 'PUT' : 'POST', data);
            location.hash = 'order/' + saved.id;
            toastr.success('تم حفظ المسودة بنجاح. يمكنك الآن إرفاق المستندات ثم إرسالها للمراجعة.');
        } finally {
            submitButton.prop('disabled', false);
        }
    });
}

async function detail(id) {
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

    $('#content').html(
        head(order.number, `${lookup.categories[order.category]} · ${order.branch_name} · ${order.date}`, actions) +
        `<div class="panel workflow-steps">${Object.entries(statuses).filter(([key]) => key !== 'rejected').map(([key, label]) => `<span class="${order.status === key ? 'current' : ''}">${label}</span>`).join('')}</div>
        <div class="stats">${[
            [order.supplier_name, 'المورد'],
            [order.vehicle_plate || '—', 'السيارة'],
            [money(order.total_minor), 'إجمالي الطلب'],
            [statusLabel(order.status), 'الحالة'],
        ].map(stat => `<div class="stat"><div><span>${stat[1]}</span><strong style="font-size:18px">${stat[1] === 'السيارة' ? bdi(stat[0]) : esc(stat[0])}</strong></div></div>`).join('')}</div>
        <section class="panel mt-3">
            <h2 class="section-title">البنود والمطابقة</h2>
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
        </section>
        <section class="panel">
            <h2 class="section-title">المرفقات وصور السيارة ${can('media.upload') && !['paid', 'closed', 'rejected'].includes(order.status) ? button('رفع ملف أو تصوير', 'upload', 'camera') : ''}</h2>
            <div class="document-grid">${order.media.map(media => `<div class="document-card">${media.mime.startsWith('image/') ? `<img class="photo-preview" src="${esc(media.url)}" alt="${esc(media.label)}">` : '<i class="fa-solid fa-file-pdf text-danger fs-2"></i>'}<p class="small my-2">${esc(lookup.photo_labels[media.label] || media.collection)}<br><a href="${esc(media.url)}" target="_blank" rel="noopener">${bdi(media.name)}</a></p>${order.status === 'draft' && can('orders.update') ? `<button class="btn btn-light" data-action="delete-media" data-id="${media.id}">حذف المرفق</button>` : ''}</div>`).join('') || '<p class="subtext">لا توجد مرفقات حتى الآن.</p>'}</div>
            ${order.vehicle_id ? `<p class="subtext mt-3">صور ما قبل الإصلاح المطلوبة: ${Object.values(lookup.photo_labels).join('، ')}.</p>` : ''}
        </section>
        <section class="panel">
            <h2 class="section-title">سجل الدورة والاعتمادات</h2>
            ${order.approvals.length ? order.approvals.map(entry => `<p><i class="fa-solid fa-circle-check text-primary"></i> ${esc(statusLabel(entry.from_status))} ← ${esc(statusLabel(entry.to_status))} · ${bdi(entry.user?.name)} <small>${esc(entry.created_at)}</small>${entry.reason ? `<br>${esc(entry.reason)}` : ''}</p>`).join('') : '<p class="subtext">لم يُرسل الطلب إلى الاعتماد بعد.</p>'}
        </section>`
    );
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

function masters(kind) {
    if (!kinds[kind]) return;
    $('#content').html(head(kinds[kind], 'إدارة البيانات المرجعية المستخدمة في الشاشات والعمليات اليومية.', (can(permission(kind)) ? button('إضافة سجل', 'master-add', 'plus', `data-kind="${kind}"`) : '') + (['items', 'vehicles', 'suppliers'].includes(kind) ? excelButtons(kind) : '')) + '<div class="panel" id="grid-area"></div>');

    const columns = kind === 'vehicles'
        ? [col('id', 'الرقم'), col('plate', 'لوحة السيارة', { render: value => bdi(value) }), col('model', 'الموديل'), col('year', 'السنة'), col('odometer', 'العداد')]
        : [col('id', 'الرقم'), col('name', 'الاسم'), ...(['items'].includes(kind) ? [col('sku', 'الكود'), col('unit', 'الوحدة')] : kind === 'regions' ? [] : [col('code', 'الكود')])];

    columns.push({ data: 'active', title: 'الحالة', render: value => value ? 'نشط' : 'غير نشط' });
    if (can(permission(kind))) columns.push({ data: null, title: 'تعديل', orderable: false, searchable: false, render: () => '<button class="btn btn-light row-edit"><i class="fa-solid fa-pen"></i>تعديل</button>' });

    grid('masters/' + kind, columns);
    $('#records').on('click', '.row-edit', function () {
        masterEditor(kind, table.row($(this).closest('tr')).data());
    });
}

function inventoryTable(showMovements) {
    let warehouseId = $('[name=warehouse_id]').val();
    if (!showMovements && !warehouseId) {
        warehouseId = lookup.warehouses[0]?.id;
        $('[name=warehouse_id]').val(warehouseId).trigger('change');
    }

    grid('inventory/' + (showMovements ? 'movements' : 'balances') + '?warehouse_id=' + (warehouseId || ''), showMovements
        ? [
            col('number', 'رقم الحركة', { render: value => bdi(value) }),
            col('date', 'التاريخ'),
            col('type', 'النوع', { render: value => esc(movementLabel(value)) }),
            col('warehouse.name', 'المخزن'),
            col('vehicle.plate', 'السيارة', { render: value => value ? bdi(value) : '—' }),
            { data: 'lines', title: 'البنود', orderable: false, searchable: false, render: lines => lines.map(line => `${esc(line.item?.name)} × ${(line.quantity_milli || 0) / 1000}`).join('<br>') || '—' },
            col('notes', 'الملاحظات'),
        ]
        : [
            col('sku', 'كود الصنف'),
            col('name', 'الصنف'),
            { data: 'quantity_milli', title: 'الرصيد الحالي', render: value => value / 1000 },
            { data: 'minimum_milli', title: 'حد إعادة الطلب', render: value => value / 1000 },
        ]);
}

function movement(type) {
    openModal('حركة مخزنية', `<div class="form-grid">${select('warehouse_id', 'المخزن', lookup.warehouses)}${type === 'transfer' ? select('destination_warehouse_id', 'المخزن المستلم', lookup.warehouses) : ''}${type === 'issue' ? select('vehicle_id', 'السيارة', lookup.vehicles) + field('odometer', 'قراءة العداد', 0, 'number') : ''}${type === 'return' ? field('source_movement_id', 'رقم إذن الصرف الأصلي', '', 'number') : ''}${field('date', 'التاريخ', today(), 'date')}${select('item_id', 'الصنف', lookup.items.filter(item => item.track_stock))}${field('quantity', type === 'adjust' ? 'العدد الفعلي بالجرد' : 'الكمية', 1, 'number')}${textareaField('notes', 'سبب الحركة', '', type === 'adjust')}</div>`, data => {
        data.lines = [{ item_id: data.item_id, quantity: data.quantity }];
        data.type = type;
        data.request_key = crypto.randomUUID();
        return api('inventory/movements', 'POST', data);
    });
}

function inventory() {
    $('#content').html(head('المخزون والحركات', 'عرض الأرصدة الفعلية وتسجيل الصرف والتحويل والمرتجعات وتسويات الجرد.', Object.entries(movementTypes).filter(([key]) => can('inventory.' + key)).map(([key, label]) => button(label, 'movement', 'boxes-stacked', `data-type="${key}"`)).join('')) + `<div class="panel"><div class="form-grid">${select('warehouse_id', 'المخزن', lookup.warehouses)}</div><div class="action-bar">${button('عرض الأرصدة', 'balances', 'cubes')}${button('عرض سجل الحركات', 'movements', 'list')}</div><div id="grid-area"></div></div>`);
    enhance();
    inventoryTable(false);
}

function cards() {
    $('#content').html(head('كروت الصيانة', 'إدارة استقبال السيارة ومراحل الصيانة والطلبات المرتبطة بها.', can('cards.manage') ? button('كارت صيانة جديد', 'card-add') : '') + '<div class="panel" id="grid-area"></div>');
    grid('cards', [
        col('number', 'رقم الكارت', { render: value => bdi(value) }),
        col('vehicle.plate', 'السيارة', { render: value => value ? bdi(value) : '—' }),
        col('date', 'التاريخ'),
        col('type', 'نوع الصيانة'),
        { data: 'status', title: 'الحالة', render: value => esc(cardStatusLabel(value)) },
        {
            data: null,
            title: 'الإجراءات',
            orderable: false,
            searchable: false,
            render: row => `${can('cards.manage') && row.status !== 'closed' ? '<button class="btn btn-light card-next">المرحلة التالية</button>' : ''}${row.order ? `<a class="btn btn-light" href="#order/${row.order.id}">الطلب المرتبط</a>` : can('orders.create') ? '<button class="btn btn-light card-order">إنشاء طلب مرتبط</button>' : ''}`,
        },
    ]);

    $('#records').on('click', '.card-next', async function () {
        const row = table.row($(this).closest('tr')).data();
        const stages = Object.keys(cardStatuses);
        await api('cards/' + row.id, 'PATCH', { status: stages[stages.indexOf(row.status) + 1], notes: row.notes });
        table.ajax.reload();
    }).on('click', '.card-order', function () {
        const row = table.row($(this).closest('tr')).data();
        orderForm();
        $('#order-form [name=maintenance_card_id]').val(row.id);
        $('#order-form [name=vehicle_id]').val(row.vehicle_id).trigger('change');
        $('#order-form [name=branch_id]').val(row.branch_id).trigger('change');
    });
}

function users() {
    $('#content').html(head('المستخدمون', 'إدارة الحسابات والأدوار ونطاق الوصول إلى الفروع.', button('مستخدم جديد', 'user-add')) + '<div class="panel" id="grid-area"></div>');
    grid('admin/users', [
        col('name', 'الاسم'),
        col('email', 'البريد الإلكتروني', { render: value => `<span dir="ltr">${esc(value)}</span>` }),
        { data: 'roles', title: 'الأدوار', orderable: false, searchable: false, render: roles => renderRolePills(roles) || '—' },
        { data: 'active', title: 'الحالة', render: value => value ? 'نشط' : 'معطل' },
        { data: 'all_branches', title: 'نطاق الفروع', render: value => value ? 'جميع الفروع' : 'فروع محددة' },
        { data: null, title: 'تعديل', orderable: false, searchable: false, render: () => '<button class="btn btn-light edit-user">تعديل</button>' },
    ]);

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
                <span class="badge">${role.permissions.length} صلاحية</span>
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
        col('created_at', 'الوقت'),
        { data: 'causer.name', title: 'المستخدم', render: value => value ? bdi(value) : 'النظام' },
        col('event_label', 'النشاط'),
        { data: null, title: 'السجل المرتبط', render: row => `<div><strong>${esc(row.subject_label || 'سجل')}</strong><div class="subtext">${bdi(row.subject_reference || '—')}</div></div>` },
        { data: 'details', title: 'التفاصيل', orderable: false, searchable: false, render: details => renderActivityDetails(details || {}) },
    ], { order: [[0, 'desc']], pageLength: 8 });
}

function uploadFile(orderId, collection, file, label = '') {
    const data = new FormData();
    data.append('collection', collection);
    data.append('file', file);
    if (label) data.append('label', label);
    return $.ajax({ url: `/api/orders/${orderId}/media`, method: 'POST', data, processData: false, contentType: false });
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
    const buttonNode = $(this).find('[type=submit]').prop('disabled', true);
    try {
        await modalSave(formData(this), this);
        bootstrap.Modal.getInstance('#editor').hide();
        toastr.success('تم حفظ البيانات بنجاح.');
        await refreshLookups();
        await route();
    } finally {
        buttonNode.prop('disabled', false);
    }
});

$(document).on('click', '.remove-line', function () {
    $(this).closest('tr').remove();
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
            break;
        case 'edit-order':
            orderForm(currentOrder);
            break;
        case 'upload':
            upload();
            break;
        case 'receipt':
        case 'invoice':
            documentForm(action);
            break;
        case 'transition': {
            const next = this.dataset.next;
            const result = await Swal.fire({
                title: 'تأكيد الإجراء',
                text: next === 'match' ? 'سيجري النظام مطابقة المستندات والكميات والقيمة على الخادم.' : 'هل تريد تنفيذ هذا الإجراء الآن؟',
                input: ['return', 'reject'].includes(next) ? 'textarea' : undefined,
                inputLabel: ['return', 'reject'].includes(next) ? 'سبب الإجراء' : undefined,
                showCancelButton: true,
                confirmButtonText: 'تأكيد',
                cancelButtonText: 'إلغاء',
                reverseButtons: true,
                inputValidator: ['return', 'reject'].includes(next) ? value => !value?.trim() ? 'سبب الإجراء مطلوب.' : undefined : undefined,
            });
            if (result.isConfirmed) {
                await api(`orders/${currentOrder.id}/actions/${next}`, 'POST', { reason: result.value || null });
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
            inventoryTable(false);
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
        await route();
    } catch (error) {
        $('#content').html('<div class="panel">تعذر الاتصال بالخادم في الوقت الحالي.</div>');
    }
})();
