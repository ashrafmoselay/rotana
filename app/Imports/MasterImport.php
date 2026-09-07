<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Services\MasterService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MasterImport implements ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public function __construct(private string $kind) {}

    public function collection(Collection $rows)
    {
        if ($rows->count() > 2000) {
            throw ValidationException::withMessages(['file' => 'الحد الأقصى 2000 صف لكل ملف.']);
        }
        foreach ($rows as $i => $row) {
            $d = $row->toArray();
            if (! array_filter($d, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            try {
                if ($this->kind === 'vehicles') {
                    $d['branch_id'] = Branch::where('code', (string) ($d['branch_code'] ?? ''))->value('id');
                    $d['cost_center_id'] = CostCenter::where('code', (string) ($d['cost_center_code'] ?? ''))->value('id');
                }
                if ($this->kind === 'items') {
                    if (! in_array((string) ($d['track_stock'] ?? ''), ['0', '1'], true)) {
                        throw ValidationException::withMessages(['track_stock' => 'استخدم 0 أو 1 لتتبع المخزون.']);
                    }
                    $d['track_stock'] = (string) $d['track_stock'] === '1';
                }
                $d['active'] = true;
                app(MasterService::class)->save($this->kind, $d);
                $this->imported++;
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['file' => 'الصف '.($i + 2).': '.collect($e->errors())->flatten()->implode(' / ')]);
            }
        }
    }
}
