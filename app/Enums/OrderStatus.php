<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Accountant = 'accountant';
    case Manager = 'manager';
    case Supervisor = 'supervisor';
    case Matching = 'matching';
    case Ready = 'ready';
    case Paid = 'paid';
    case Closed = 'closed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',self::Accountant => 'مراجعة المحاسب',self::Manager => 'اعتماد المدير',self::Supervisor => 'اعتماد المشرف',self::Matching => 'مطابقة قبل التحويل',self::Ready => 'جاهز للتحويل',self::Paid => 'تم التحويل',self::Closed => 'مغلق',self::Rejected => 'مرفوض'
        };
    }
}
