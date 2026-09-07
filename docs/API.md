# واجهة HTTP الداخلية

تسجيل الدخول بـ `POST /login` ثم استخدام Session Cookie. كل طلب تعديل يتطلب `X-CSRF-TOKEN` من الصفحة. أرسل `Accept: application/json`. لا توجد مصادقة Token أو API عام في هذه النسخة.

| المسار | الطرق / الغرض |
|---|---|
| /api/me, /api/lookups, /api/dashboard | GET المستخدم والصلاحيات والقوائم ولوحة التحكم |
| /api/orders | GET DataTables، POST مسودة جديدة |
| /api/orders/{id} | GET تفاصيل، PUT تعديل مسودة |
| /api/orders/{id}/actions/{action} | POST submit/approve/return/reject/match/close؛ reason للإعادة والرفض |
| /api/orders/{id}/receipt | POST date, lines[{order_line_id,quantity}] |
| /api/orders/{id}/invoice | POST date, number, total, media_id, lines |
| /api/orders/{id}/payment | POST date, reference, amount, media_id |
| /api/orders/{id}/media | POST multipart: file, collection, label |
| /media/{id} | GET ملف خاص، DELETE مرفق مسودة |
| /api/masters/{kind} | GET DataTables، POST سجل |
| /api/masters/{kind}/{id} | PUT سجل |
| /api/inventory/balances | GET مع warehouse_id |
| /api/inventory/movements | GET سجل؛ POST حركة |
| /api/cards | GET DataTables، POST كارت |
| /api/cards/{id} | PATCH الانتقال للمرحلة التالية |
| /api/admin/users | GET، POST؛ PUT /{id} |
| /api/admin/roles | GET، POST؛ PUT /{id} |
| /api/admin/activity | GET سجل النشاطات |
| /api/excel/template/{kind} | GET قالب XLSX |
| /api/excel/import/{kind} | POST multipart file |
| /api/excel/export/{kind} | GET XLSX |

`kind`: vehicles/suppliers/items/regions/branches/cost-centers/warehouses. Excel استيراد وقوالب: vehicles/suppliers/items فقط؛ تصدير: الثلاثة السابقة + orders.

طلبات DataTables تقبل draw/start/length/search[value]/order/columns وفق بروتوكول الحزمة. فلاتر الطلبات والتصدير: status, vehicle_id, supplier_id, category, branch_id, from, to.

الحركة: request_key UUID، type issue/transfer/return/adjust، warehouse_id، date، notes، lines[{item_id,quantity}]؛ للصرف vehicle_id وodometer، للتحويل destination_warehouse_id، للمرتجع source_movement_id. quantity في adjust هو العدد الفعلي الجديد، وليس الفرق.

استجابات متوقعة: 401 جلسة مفقودة، 403 صلاحية/فرع، 419 CSRF، 422 تحقق/مرحلة غير صحيحة، 409 تعارض قيود البيانات. لا تعيد محاولة دفع أو حركة عمياء عند انقطاع الشبكة؛ تحقق من السجل أولًا.
