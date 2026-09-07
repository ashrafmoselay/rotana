<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus as S;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    public function store(Request $r, PurchaseOrder $order)
    {
        Access::allow('media.upload');
        Access::branch($order->branch_id);
        $data = $r->validate(['collection' => ['required', Rule::in(['quote', 'photos_before', 'photos_after', 'attachments', 'invoice', 'proof'])], 'label' => ['nullable', Rule::in(array_keys(config('rotana.photo_labels')))], 'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,mp4']);
        $collection = $data['collection'];

        return DB::transaction(function () use ($r, $order, $data, $collection) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            Access::branch($order->branch_id);
            $permission = match ($collection) {
                'invoice' => 'invoices.manage','proof' => 'payments.create',default => 'orders.update'
            };
            Access::allow($permission);
            if (in_array($collection, ['quote', 'photos_before', 'attachments'])) {
                abort_unless($order->status === S::Draft, 422, 'لا يمكن تغيير مرفقات الطلب بعد الإرسال.');
            }
            if (in_array($collection, ['invoice', 'proof'])) {
                abort_unless(in_array($order->status, [S::Matching, S::Ready]), 422, 'المرفقات غير متاحة بهذه المرحلة.');
            }
            if ($collection === 'photos_after') {
                abort_unless(in_array($order->status, [S::Matching, S::Ready]), 422, 'صور بعد الإصلاح متاحة أثناء الاستلام والمطابقة.');
            }
            if (str_starts_with($collection, 'photos_')) {
                abort_unless(in_array($r->file('file')->getMimeType(), ['image/jpeg', 'image/png', 'image/webp']) && isset($data['label']), 422, 'الصورة والجهة مطلوبة.');
            }
            if ($collection === 'quote') {
                abort_unless($r->file('file')->getMimeType() === 'application/pdf', 422, 'عرض السعر يجب أن يكون PDF.');
            }
            if (in_array($collection, ['invoice', 'proof'])) {
                abort_unless(in_array($r->file('file')->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']), 422, 'ارفع مستند PDF أو صورة.');
            }
            abort_if($order->media()->count() >= 60, 422, 'تم الوصول للحد الأقصى للمرفقات.');
            $m = $order->addMediaFromRequest('file')->withCustomProperties(['label' => $data['label'] ?? null, 'uploaded_by' => auth()->id()])->toMediaCollection($collection, 'private_media');
            Audit::record('media.uploaded', $order, ['branch_id' => $order->branch_id, 'media_id' => $m->id, 'collection' => $collection]);

            return response()->json(['id' => $m->id, 'url' => route('media.show', $m->id)], 201);
        });
    }

    public function show(Media $media)
    {
        Access::allow('orders.view');
        abort_unless($media->model instanceof PurchaseOrder, 404);
        Access::branch($media->model->branch_id);

        return response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(Media $media)
    {
        Access::allow('orders.update');
        $order = $media->model;
        abort_unless($order instanceof PurchaseOrder, 404);
        Access::branch($order->branch_id);
        DB::transaction(function () use ($order, $media) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            Access::branch($order->branch_id);
            abort_unless($order->status === S::Draft, 422, 'المرفقات ثابتة بعد الإرسال.');
            $media->delete();
            Audit::record('media.deleted', $order, ['branch_id' => $order->branch_id, 'media_id' => $media->id]);
        });

        return response()->noContent();
    }
}
