<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8">
<style>
    body { font-family: dejavusans; color: #193453; font-size: 10pt; line-height: 1.35; }
    h1 { font-size: 18pt; margin: 0 0 3mm; }
    h2 { font-size: 12pt; background: #edf3f8; padding: 1.5mm; margin: 3mm 0 2mm; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
    th { background: #193453; color: white; padding: 2mm; font-size: 9pt; }
    td { border-bottom: 1px solid #dce4ed; padding: 1.5mm; vertical-align: top; }
    .meta td { width: 50%; }
    .label { color: #64748b; font-size: 9pt; }
    .ready { color: #08785d; background: #e8f8f4; border: 1px solid #bce6dc; padding: 3mm; }
    .number { direction: ltr; text-align: center; white-space: nowrap; }
    .bank { background: #f5f8fc; padding: 3mm; border: 1px solid #dce4ed; }
    .iban { direction: ltr; text-align: center; font-size: 13pt; font-weight: bold; }
    .note { color: #64748b; font-size: 9pt; }
    .total { font-size: 12pt; font-weight: bold; background: #edf3f8; }
</style>
</head>
<body>
<div class="label">روتانا للسيارات | إدارة الأسطول والمشتريات</div>
<h1>طلب معتمد وجاهز للتحويل</h1>
<div class="ready">الحالة عند إصدار المستند: {{ $order->status->label() }} - {{ $order->isAdvancePayment() ? 'تم اعتماد الدفع المقدم؛ تستكمل المستندات المطلوبة قبل الإغلاق.' : 'اكتملت المطابقة قبل التحويل.' }}</div>
<table class="meta">
    <tr><td><span class="label">رقم الطلب</span><br><span dir="ltr">{{ $order->number }}</span></td><td><span class="label">تاريخ الطلب</span><br><span dir="ltr">{{ $order->date }}</span></td></tr>
    <tr><td><span class="label">نوع الطلب / الفرع</span><br>{{ \App\Support\UiText::categoryLabel($order->category) }} / {{ $order->branch_name }}</td><td><span class="label">المورد المسجل بالطلب</span><br>{{ $order->supplier_name }}</td></tr>
    <tr><td><span class="label">رقم عرض السعر / الفاتورة</span><br><span dir="ltr">{{ $order->quote_number }} / {{ $order->invoice?->number ?? 'لم تسجل بعد' }}</span></td><td><span class="label">منشئ الطلب</span><br>{{ $order->creator?->name ?: 'غير متاح' }}</td></tr>
    @if ($order->vehicle_id)
    <tr><td><span class="label">السيارة</span><br>{{ $order->vehicle_plate }}</td><td><span class="label">قراءة العداد وقت الطلب</span><br>{{ $order->odometer ?? 'غير مسجل' }}</td></tr>
    @endif
</table>

<h2>بيانات المستفيد والحساب البنكي</h2>
<div class="bank">
    <span class="label">اسم المورد / المستفيد</span><br><strong>{{ $order->supplier->name }}</strong><br>
    <span class="label">رقم الآيبان المسجل للمستفيد</span>
    <div class="iban">{{ $order->supplier->iban }}</div>
    <div class="note">بيانات الحساب من سجل المستفيد وقت إصدار المستند.</div>
</div>

<h2>بنود الطلب</h2>
<table>
    <thead><tr><th>#</th><th>كود الصنف</th><th>الوصف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
    <tbody>
    @foreach ($order->lines as $line)
        <tr><td class="number">{{ $loop->iteration }}</td><td>{{ $line->sku }}</td><td>{{ $line->description }}</td><td class="number">{{ \App\Support\Amounts::quantity($line->quantity_milli) }}</td><td class="number">{{ \App\Support\Amounts::money($line->unit_price_minor) }}</td><td class="number">{{ \App\Support\Amounts::money($line->total_minor) }}</td></tr>
    @endforeach
    </tbody>
</table>
<table>
    <tr><td>الإجمالي قبل الضريبة</td><td class="number">{{ \App\Support\Amounts::money($order->subtotal_minor) }}</td></tr>
    <tr><td>ضريبة القيمة المضافة ({{ $order->tax_basis_points / 100 }}%)</td><td class="number">{{ \App\Support\Amounts::money($order->tax_minor) }}</td></tr>
    <tr class="total"><td>المبلغ المطلوب تحويله (ريال سعودي)</td><td class="number">{{ \App\Support\Amounts::money($order->total_minor) }}</td></tr>
</table>

<h2>سجل الاعتمادات والإجراءات</h2>
<table>
    <thead><tr><th>الإجراء</th><th>بواسطة</th><th>التاريخ والوقت</th></tr></thead>
    <tbody>
    @forelse ($order->approvals as $approval)
        <tr><td>{{ \App\Support\UiText::activityEventLabel($approval->action) }}</td><td>{{ $approval->user?->name ?: 'غير متاح' }}</td><td class="number">{{ $approval->created_at->format('Y-m-d H:i') }}</td></tr>
    @empty
        <tr><td colspan="3">لا يوجد سجل إجراءات تفصيلي لهذا الطلب.</td></tr>
    @endforelse
    </tbody>
</table>
@if ($order->notes)<h2>ملاحظات الطلب</h2><div>{{ $order->notes }}</div>@endif
<p class="note">هذا المستند مرفق لتنفيذ التحويل عبر البنك، وليس إثباتًا لتنفيذ التحويل. يعبّر عن حالة الطلب وقت الإصدار.</p>
<p class="note">تاريخ ووقت الإصدار: <span dir="ltr">{{ $generatedAt->format('Y-m-d H:i') }}</span></p>
</body>
</html>
