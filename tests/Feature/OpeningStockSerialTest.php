<?php
namespace Tests\Feature;
use App\Models\Product;
use App\Models\ProductSerialNumber;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class OpeningStockSerialTest extends TestCase
{
    use RefreshDatabase;
    private function setupStock(): array {
        $f = $this->fixture();
        $this->actingAs($f[1])->withSession(['business_id' => $f[0]]);
        return $f;
    }
    private function payload($f, $serials, $qty = 1, $status = 'posted'): array {
        return ['branch_id'=>$f[3], 'warehouse_id'=>$f[4], 'opening_date'=>date('Y-m-d'), 'status'=>$status,
            'items'=>[['product_id'=>$f[2]->id, 'quantity'=>$qty, 'purchase_cost'=>100, 'serials'=>$serials]]];
    }
    private function existing($f, array $extra = []) {
        return ProductSerialNumber::create(array_merge(['business_id'=>$f[0], 'product_id'=>$f[2]->id, 'branch_id'=>$f[3], 'warehouse_id'=>$f[4],
            'serial_number'=>'WATCH-001', 'normalized_serial_number'=>'WATCH001', 'status'=>'in_stock', 'current_status'=>'in_stock', 'condition'=>'new'], $extra));
    }
    public function test_existing_serial_posts_once_and_reverses_with_history(): void {
        $f=$this->setupStock(); $serial=$this->existing($f);
        $rows=[['serial_number_id'=>$serial->id,'serial_number'=>$serial->serial_number]];
        $id=$this->postJson('/app/inventory/opening-stock',$this->payload($f,$rows))->assertCreated()->json('voucher.id');
        $this->assertEquals(1, StockLedger::sum('quantity_in'));
        $this->assertEquals(1, ProductSerialNumber::count());
        $this->assertDatabaseHas('serial_number_histories',['serial_number_id'=>$serial->id,'event_type'=>'opening_stock','voucher_id'=>$id]);
        $this->getJson('/app/sales/invoices/serials?'.http_build_query(['product_id'=>$f[2]->id,'branch_id'=>$f[3],'warehouse_id'=>$f[4]]))->assertOk()->assertJsonCount(1);
        $this->postJson('/app/inventory/opening-stock',$this->payload($f,$rows))->assertUnprocessable();
        $this->postJson("/app/inventory/opening-stock/$id/reverse",['remarks'=>'Test'])->assertOk();
        $this->assertEquals(1, StockLedger::sum('quantity_out'));
        $this->assertDatabaseHas('product_serial_numbers',['id'=>$serial->id,'current_status'=>'blocked']);
        $this->assertDatabaseHas('stock_ledgers',['serial_id'=>$serial->id,'transaction_type'=>'opening_stock_reversal']);
    }
    public function test_count_duplicates_integer_and_rollback(): void {
        $f=$this->setupStock();
        foreach ([[[],1],[[['serial_number'=>'A']],2],[[['serial_number'=>'A'],['serial_number'=>'A']],2],[[['serial_number'=>'A']],1.5]] as [$rows,$qty]) {
            $this->postJson('/app/inventory/opening-stock',$this->payload($f,$rows,$qty))->assertUnprocessable();
        }
        $this->existing($f);
        $this->postJson('/app/inventory/opening-stock',$this->payload($f,[['serial_number'=>'NEW'],['serial_number'=>'WATCH-001']],2))->assertUnprocessable();
        $this->assertEquals(0, StockLedger::count());
        $this->assertEquals(1, ProductSerialNumber::count());
        $this->assertDatabaseMissing('product_serial_numbers',['serial_number'=>'NEW']);
    }
    public function test_wrong_product_location_and_status_rejected(): void {
        $f=$this->setupStock(); $serial=$this->existing($f);
        $otherProduct=Product::create(['business_id'=>$f[0], 'company_id'=>$f[0], 'name'=>'Other', 'sku'=>'OTHER', 'status'=>'active']);
        $otherBranch=DB::table('branches')->insertGetId(['business_id'=>$f[0], 'name'=>'Other', 'code'=>'OTHER', 'status'=>'active']);
        foreach ([['product_id'=>$otherProduct->id],['branch_id'=>$otherBranch],['warehouse_id'=>$f[5]],['current_status'=>'sold'],['current_status'=>'blocked'],['condition'=>'damaged']] as $change) {
            $original=$serial->getAttributes(); $serial->update($change);
            $this->postJson('/app/inventory/opening-stock',$this->payload($f,[['serial_number_id'=>$serial->id,'serial_number'=>'WATCH-001']]))->assertUnprocessable();
            $serial->setRawAttributes($original); $serial->save();
            // Restore using an explicit test fixture update, never application data.
            DB::table('product_serial_numbers')->where('id',$serial->id)->update($original);
        }
        $this->assertEquals(0,StockLedger::count());
    }
    public function test_draft_then_multi_serial_post(): void {
        $f=$this->setupStock();
        $id=$this->postJson('/app/inventory/opening-stock',$this->payload($f,[['serial_number'=>'NEW-1']],2,'draft'))->assertCreated()->json('voucher.id');
        $this->assertEquals(0,ProductSerialNumber::count()); $this->assertEquals(0,StockLedger::count());
        $this->postJson("/app/inventory/opening-stock/$id/approve")->assertUnprocessable();
        $this->putJson("/app/inventory/opening-stock/$id",$this->payload($f,[['serial_number'=>'NEW-1'],['serial_number'=>'NEW-2']],2))->assertOk();
        $this->assertEquals(2,ProductSerialNumber::count()); $this->assertEquals(2,StockLedger::sum('quantity_in'));
        $this->putJson("/app/inventory/opening-stock/$id",$this->payload($f,[],2,'draft'))->assertUnprocessable();
    }
    public function test_pos_requires_serial_then_sells_and_prevents_opening_reversal(): void {
        $f=$this->setupStock();
        $id=$this->postJson('/app/inventory/opening-stock',$this->payload($f,[['serial_number'=>'POS-1']]))->assertCreated()->json('voucher.id');
        $serial=ProductSerialNumber::first();
        $sale=['branch_id'=>$f[3],'warehouse_id'=>$f[4],'invoice_date'=>date('Y-m-d'),'sale_type'=>'cash','invoice_type'=>'retail_invoice','tax_type'=>'nil_rated','status'=>'approved',
            'items'=>[['product_id'=>$f[2]->id,'quantity'=>1,'selling_rate'=>100,'gst_rate'=>0,'serials'=>[]]]];
        $this->postJson('/app/sales/invoices',$sale)->assertUnprocessable();
        $sale['items'][0]['serials']=[['serial_number_id'=>$serial->id,'serial_number'=>'POS-1']];
        $this->postJson('/app/sales/invoices',$sale)->assertCreated();
        $this->assertDatabaseHas('product_serial_numbers',['id'=>$serial->id,'current_status'=>'sold']);
        $this->assertDatabaseHas('stock_ledgers',['serial_id'=>$serial->id,'transaction_type'=>'sale','quantity_out'=>1]);
        $this->getJson('/app/sales/invoices/serials?'.http_build_query(['product_id'=>$f[2]->id,'branch_id'=>$f[3],'warehouse_id'=>$f[4]]))->assertOk()->assertJsonCount(0);
        $this->postJson("/app/inventory/opening-stock/$id/reverse",['remarks'=>'Test'])->assertUnprocessable();
        $this->assertEquals(0,StockLedger::sum(DB::raw('quantity_in - quantity_out')));
    }
    public function test_five_serials_reconcile_and_cross_product_duplicate_rolls_back(): void {
        $f=$this->setupStock();
        $rows=array_map(fn ($i)=>['serial_number'=>'FIVE-'.$i], range(1,5));
        $this->postJson('/app/inventory/opening-stock',$this->payload($f,$rows,5))->assertCreated();
        $this->assertEquals(5,ProductSerialNumber::where('current_status','in_stock')->count());
        $this->assertEquals(5,StockLedger::sum('quantity_in'));
        $this->assertDatabaseCount('serial_number_histories',10);
        $other=Product::create(['business_id'=>$f[0],'company_id'=>$f[0],'name'=>'Other serial product','sku'=>'SER-OTHER','tracking_type'=>'serial','serial_required'=>true,'status'=>'active']);
        $bad=$this->payload($f,[['serial_number'=>'FIVE-1']]);$bad['items'][0]['product_id']=$other->id;
        $this->postJson('/app/inventory/opening-stock',$bad)->assertUnprocessable();
        $this->assertEquals(5,ProductSerialNumber::count());
    }
    public function test_normal_and_batch_opening_stock_remain_supported(): void {
        $f=$this->setupStock();$f[2]->update(['serial_required'=>false,'tracking_type'=>'none']);
        $id=$this->postJson('/app/inventory/opening-stock',$this->payload($f,[],3))->assertCreated()->json('voucher.id');
        $this->postJson("/app/inventory/opening-stock/$id/reverse",['remarks'=>'test'])->assertOk();
        $f[2]->update(['tracking_type'=>'batch_expiry','batch_required'=>true,'expiry_required'=>true]);
        $data=$this->payload($f,[],2);$data['items'][0]['batch_no']='B-1';$data['items'][0]['expiry_date']=now()->addYear()->toDateString();
        $this->postJson('/app/inventory/opening-stock',$data)->assertCreated();
        $this->assertEquals(2,StockLedger::sum(DB::raw('quantity_in - quantity_out')));
    }
    private function fixture(): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'ABC Retail Pvt Ltd', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['role_id' => 1, 'is_active' => 1, 'status' => 'active']);
        $branch = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherWarehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Backroom', 'code' => 'BK', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Serial Product', 'sku' => 'SER-1', 'tracking_type' => 'serial', 'serial_required' => true, 'batch_required' => false, 'status' => 'active']);

        return [$businessId, $user, $product, $branch, $warehouse, $otherWarehouse];
    }

}
