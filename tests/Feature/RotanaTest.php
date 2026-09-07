<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Amounts;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RotanaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Session::start();
        $token = csrf_token();
        $this->withSession(['_token' => $token]);
        $this->withHeader('X-CSRF-TOKEN', $token);
        config(['rotana.demo_password' => 'Testing-Rotana-2026']);
        Storage::fake('private_media');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@rotana.test')->firstOrFail());
    }

    public function test_seed_has_every_order_and_card_stage(): void
    {
        foreach (array_keys(['draft' => 1, 'accountant' => 1, 'manager' => 1, 'supervisor' => 1, 'matching' => 1, 'ready' => 1, 'paid' => 1, 'closed' => 1, 'rejected' => 1]) as $s) {
            $this->assertDatabaseHas('purchase_orders', ['status' => $s]);
        }$this->assertDatabaseCount('users', 9);
        $this->assertDatabaseCount('maintenance_cards', 5);
    }

    public function test_authenticated_views_and_datatables(): void
    {
        $this->get('/')->assertOk()->assertSee('server.js');
        foreach (['/api/dashboard', '/api/lookups', '/api/orders?draw=1&start=0&length=10', '/api/admin/users', '/api/admin/roles', '/api/admin/activity', '/api/cards', '/api/inventory/balances?warehouse_id=1'] as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->getJson('/api/orders')->assertJsonStructure(['data', 'recordsTotal', 'recordsFiltered']);
        $lookups = $this->getJson('/api/lookups')->assertOk()->json();
        $this->assertSame('مدير النظام', data_get($lookups, 'ui.role_labels.admin'));
        $this->assertSame('عرض طلبات الشراء', $lookups['ui']['permission_catalog']['orders.view']['label'] ?? null);
        $roles = $this->getJson('/api/admin/roles')->assertOk()->json();
        $this->assertSame('اعتماد المطابقة', $roles['permission_catalog']['orders.match']['label'] ?? null);
        $this->assertSame('لوحة التحكم', data_get($roles, 'permission_groups.0.label'));
    }

    public function test_permissions_and_branch_boundaries(): void
    {
        $u = User::where('email', 'employee@rotana.test')->first();
        $this->actingAs($u);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/orders/'.PurchaseOrder::where('status', 'accountant')->first()->id.'/actions/approve')->assertForbidden();
        $foreign = Vehicle::where('branch_id', 2)->first();
        $this->putJson('/api/masters/vehicles/'.$foreign->id, [])->assertForbidden();
        $this->getJson('/api/inventory/balances?warehouse_id=2')->assertForbidden();
    }

    public function test_guest_and_inactive_user_cannot_access(): void
    {
        auth()->logout();
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/orders')->assertUnauthorized();
        $u = User::first();
        $u->update(['active' => false]);
        $this->actingAs($u)->getJson('/api/orders')->assertForbidden();
    }

    public function test_invoice_difference_prevents_payment(): void
    {
        $o = PurchaseOrder::where('status', 'matching')->latest('id')->first();
        $this->postJson('/api/orders/'.$o->id.'/actions/match')->assertUnprocessable();
        $this->postJson('/api/orders/'.$o->id.'/payment', ['reference' => 'BAD', 'date' => today()->toDateString(), 'amount' => '1', 'media_id' => $o->invoice->media_id])->assertUnprocessable();
        $this->assertNull($o->fresh()->payment);
    }

    public function test_payment_then_close_and_duplicate_protection(): void
    {
        $o = PurchaseOrder::where('status', 'ready')->first();
        $file = UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf');
        $m = $this->postJson('/api/orders/'.$o->id.'/media', ['collection' => 'proof', 'file' => $file])->assertCreated()->json('id');
        $d = ['reference' => 'NEW-TRANSFER', 'date' => today()->toDateString(), 'amount' => Amounts::money($o->total_minor), 'media_id' => $m];
        $this->postJson('/api/orders/'.$o->id.'/payment', $d)->assertOk();
        $this->postJson('/api/orders/'.$o->id.'/payment', $d)->assertUnprocessable();
        $this->postJson('/api/orders/'.$o->id.'/actions/close')->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id' => $o->id, 'status' => 'closed']);
    }

    public function test_receipt_correction_applies_only_delta(): void
    {
        $o = PurchaseOrder::where('status', 'matching')->first();
        $before = StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli');
        $lines = $o->lines->map(fn ($l) => ['order_line_id' => $l->id, 'quantity' => $l->quantity_milli / 1000])->all();
        $lines[0]['quantity'] -= 1;
        $payload = ['date' => today()->toDateString(), 'lines' => $lines];
        $this->postJson('/api/orders/'.$o->id.'/receipt', $payload)->assertOk();
        $this->assertEquals($before - 1000, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
        $this->postJson('/api/orders/'.$o->id.'/receipt', $payload)->assertOk();
        $this->assertEquals($before - 1000, StockBalance::where('warehouse_id', 1)->where('item_id', 1)->value('quantity_milli'));
    }

    private function movement(array $extra = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'type' => 'issue', 'warehouse_id' => 1, 'vehicle_id' => 1, 'odometer' => 45101, 'date' => today()->toDateString(), 'notes' => 'test', 'lines' => [['item_id' => 1, 'quantity' => 1]]], $extra);
    }

    public function test_stock_insufficiency_is_atomic(): void
    {
        $before = StockBalance::pluck('quantity_milli', 'id')->all();
        $count = StockMovement::count();
        $this->postJson('/api/inventory/movements', $this->movement(['lines' => [['item_id' => 1, 'quantity' => 1], ['item_id' => 2, 'quantity' => 999999]]]))->assertUnprocessable();
        $this->assertSame($before, StockBalance::pluck('quantity_milli', 'id')->all());
        $this->assertSame($count, StockMovement::count());
    }

    public function test_transfer_conserves_stock_and_uuid_cannot_repeat(): void
    {
        $before = StockBalance::where('item_id', 1)->sum('quantity_milli');
        $d = $this->movement(['type' => 'transfer', 'destination_warehouse_id' => 2]);
        $this->postJson('/api/inventory/movements', $d)->assertCreated();
        $this->postJson('/api/inventory/movements', $d)->assertUnprocessable();
        $this->assertEquals($before, StockBalance::where('item_id', 1)->sum('quantity_milli'));
    }

    public function test_return_cannot_exceed_original_issue(): void
    {
        $source = StockMovement::where('type', 'issue')->first();
        $this->postJson('/api/inventory/movements', $this->movement(['type' => 'return', 'source_movement_id' => $source->id, 'lines' => [['item_id' => 1, 'quantity' => 4]]]))->assertUnprocessable();
    }

    public function test_private_media_is_scoped_and_not_public(): void
    {
        $o = PurchaseOrder::first();
        $m = $o->getFirstMedia('quote');
        $this->get('/media/'.$m->id)->assertOk();
        $o->update(['branch_id' => 2]);
        $this->actingAs(User::where('email', 'employee@rotana.test')->first());
        $this->get('/media/'.$m->id)->assertForbidden();
        $this->getJson('/api/orders/'.$o->id)->assertForbidden();
    }

    public function test_excel_import_rolls_back_all_rows_on_failure(): void
    {
        $before = Item::count();
        $csv = "sku,name,unit,track_stock,unit_cost,minimum\nNEW-1,Valid,piece,1,12.50,2\nITM-001,Duplicate,piece,1,20,1\n";
        $file = UploadedFile::fake()->createWithContent('items.csv', $csv);
        $this->postJson('/api/excel/import/items', ['file' => $file])->assertUnprocessable();
        $this->assertEquals($before, Item::count());
        $this->assertDatabaseMissing('items', ['sku' => 'NEW-1']);
    }

    public function test_excel_export_neutralizes_formula_strings(): void
    {
        Item::first()->update(['name' => '=HYPERLINK("evil")']);
        $response = $this->get('/api/excel/export/items')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($file);
        $cell = $book->getActiveSheet()->getCell('B2');
        $this->assertSame('s', $cell->getDataType());
        $this->assertSame('=HYPERLINK("evil")', $cell->getValue());
    }

    public function test_decimal_rounding_and_last_admin_protection(): void
    {
        $this->assertSame(1501, Amounts::line(1000, 1501));
        $this->assertSame(225, Amounts::tax(1501, 1500));
        $this->assertSame('15000000000000.01', Amounts::money(1500000000000001));
        $u = auth()->user();
        $this->putJson('/api/admin/users/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'active' => false, 'all_branches' => true, 'roles' => ['admin'], 'branch_ids' => []])->assertUnprocessable();
    }

    public function test_vehicle_order_requires_all_nine_photos(): void
    {
        $o = PurchaseOrder::where('category', 'maintenance')->first();
        $o->update(['status' => 'draft']);
        $m = $o->getFirstMedia('photos_before');
        $m->delete();
        $this->postJson('/api/orders/'.$o->id.'/actions/submit')->assertUnprocessable();
        $this->postJson('/api/orders/'.$o->id.'/media', ['collection' => 'photos_before', 'label' => 'front', 'file' => UploadedFile::fake()->image('front.jpg')])->assertCreated();
        $this->postJson('/api/orders/'.$o->id.'/actions/submit')->assertOk();
    }

    public function test_user_creation_roles_and_excel_success(): void
    {
        $this->postJson('/api/admin/users', ['name' => 'New employee', 'email' => 'new@example.test', 'password' => 'Secure-Test-2026', 'active' => true, 'all_branches' => false, 'roles' => ['employee'], 'branch_ids' => [1]])->assertCreated();
        $u = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($u->hasRole('employee'));
        $this->assertFalse($u->mayAccessBranch(2));
        $this->postJson('/api/admin/roles', ['name' => 'custom-reader', 'permissions' => ['orders.view']])->assertCreated();
        $file = UploadedFile::fake()->createWithContent('items.csv', "sku,name,unit,track_stock,unit_cost,minimum\nNEW-OK,New item,piece,1,12.50,2\n");
        $this->postJson('/api/excel/import/items', ['file' => $file])->assertOk()->assertJsonPath('rows', 1);
        $this->assertDatabaseHas('items', ['sku' => 'NEW-OK', 'unit_cost_minor' => 1250]);
    }

    public function test_cannot_approve_own_request_without_explicit_permission(): void
    {
        $u = User::where('email', 'accountant@rotana.test')->first();
        $o = PurchaseOrder::where('status', 'accountant')->first();
        $o->update(['created_by' => $u->id]);
        $this->actingAs($u);
        $this->postJson('/api/orders/'.$o->id.'/actions/approve')->assertUnprocessable();
    }

    public function test_draft_can_be_created_and_updated_via_validated_api(): void
    {
        $d = ['category' => 'stock', 'branch_id' => 1, 'cost_center_id' => 1, 'supplier_id' => 1, 'warehouse_id' => 1, 'date' => today()->toDateString(), 'priority' => 'normal', 'quote_number' => 'TEST-Q', 'tax_percent' => '15', 'lines' => [['item_id' => 1, 'quantity' => '2', 'unit_price' => '10.01']]];
        $id = $this->postJson('/api/orders', $d)->assertCreated()->json('id');
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'total_minor' => 2302]);
        $d['lines'][0]['quantity'] = '3';
        $this->putJson('/api/orders/'.$id, $d)->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id' => $id, 'total_minor' => 3453]);
        $d['lines'][] = $d['lines'][0];
        $this->putJson('/api/orders/'.$id, $d)->assertUnprocessable();
    }
}
