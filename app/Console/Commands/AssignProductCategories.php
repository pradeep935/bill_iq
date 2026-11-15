<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tally's exports carry no item groups, so every product had no category (and the Category filter was empty).
 * Uses the keyword groups from billiq:assign-hsn-gst to create ~15 simple categories and assigns them.
 * Products that already have a category are left alone. Dry-run by default.
 */
class AssignProductCategories extends Command
{
    protected $signature = 'billiq:assign-product-categories {--gstin=05AWGPV0859A1ZM} {--commit}';
    protected $description = 'Create product categories and assign them from the product keyword groups (dry-run by default)';

    private const MAP = [
        'Personal Care' => ['Soap', 'Hair/personal care', 'Oral care', 'Comb', 'Razor', 'Perfumery', 'Essential oil', 'Brush/bamboo'],
        'Ghee & Dairy' => ['Ghee/dairy', 'Dairy'],
        'Edible Oils' => ['Edible oil'],
        'Honey & Sweeteners' => ['Honey', 'Sugar/jaggery', 'Candy/sweets'],
        'Spices & Masala' => ['Spices', 'Food preparation'],
        'Pulses, Grains & Millets' => ['Pulses', 'Flour', 'Millets', 'Rice', 'Salt', 'Seeds'],
        'Snacks & Ready Mixes' => ['Noodles/pasta', 'Bakery', 'Snacks', 'Mixes/premix', 'Cereal products'],
        'Beverages & Syrups' => ['Sharbat/syrup', 'Tea/coffee', 'Vinegar', 'Ice cream/chuski'],
        'Pickles & Preserves' => ['Pickle/preserves'],
        'Cleaning & Household' => ['Cleaning', 'Cleaning tools', 'Pesticide/repellent', 'Tissue paper', 'Mats', 'Textile', 'Medical/disposable', 'Footwear', 'Utensils', 'Misc'],
        'Stationery & Paper' => ['Stationery', 'Paper stationery', 'Kits/boxes'],
        'Bags & Packaging' => ['Bags', 'Jute/fabric goods', 'Packaging', 'Glass/packaging'],
        'Puja & Handicrafts' => ['Puja/dhoop', 'Handicraft/puja', 'Bamboo/wooden', 'Coir/loofah', 'Ayurvedic', 'Camphor'],
        'Electronics & Hardware' => ['Electronics', 'Hardware', 'Appliances', 'Lighting', 'Paint'],
        'Services' => ['Services'],
    ];

    public function handle(): int
    {
        $bid = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail()->id;
        $label2cat = [];
        foreach (self::MAP as $cat => $labels) foreach ($labels as $l) $label2cat[$l] = $cat;
        $rules = (new AssignTallyHsnGst())->rules();

        $assign = []; $counts = []; $other = [];
        foreach (DB::table('products')->where('business_id', $bid)->whereNull('deleted_at')->get(['id', 'name', 'product_type', 'category_id']) as $p) {
            if ($p->category_id) continue;
            $lname = mb_strtolower($p->name);
            $label = $p->product_type === 'service' ? 'Services' : null;
            if (!$label) foreach ($rules as [$re, , , $group]) if (preg_match($re, $lname)) { $label = $group; break; }
            $cat = $label2cat[$label] ?? null;
            if (!$cat) { $other[] = $p->name; $cat = 'Other'; }
            $assign[$p->id] = $cat; $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        arsort($counts);
        foreach ($counts as $c => $n) $this->line(sprintf('  %-28s %d', $c, $n));
        if ($other) $this->line('  unmatched (-> Other): ' . implode(' | ', array_slice($other, 0, 12)));

        if (!$this->option('commit')) { $this->info('DRY RUN: nothing written. Products to categorise: ' . count($assign)); return self::SUCCESS; }
        DB::transaction(function () use ($bid, $assign) {
            $ids = [];
            foreach (array_unique($assign) as $name) {
                $row = DB::table('product_categories')->where('business_id', $bid)->where('name', $name)->first();
                $ids[$name] = $row->id ?? DB::table('product_categories')->insertGetId(['business_id' => $bid, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($assign as $pid => $name) DB::table('products')->where('id', $pid)->update(['category_id' => $ids[$name], 'category' => $name, 'updated_at' => now()]);
        });
        $this->info('COMMITTED: ' . count($assign) . ' products categorised.');
        return self::SUCCESS;
    }
}
