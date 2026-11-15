<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Best-effort HSN (4-digit) + GST rate for products imported from Tally, which exports neither.
 *  GST rate priority: (1) latest rate actually charged on a single-item Tally voucher for that product,
 *                     (2) latest evidence for another size/variant of the same product family,
 *                     (3) default rate of the matching keyword rule.
 *  HSN: first matching keyword rule (4-digit heading is sufficient for small taxpayers).
 * Everything is written as tax_source=master_suggested and NOT marked confirmed. A review CSV is produced.
 */
class AssignTallyHsnGst extends Command
{
    protected $signature = 'billiq:assign-hsn-gst
        {--gstin=05AWGPV0859A1ZM}
        {--csv= : Review CSV path}
        {--commit}';

    protected $description = 'Suggest HSN/GST for Tally-imported products from voucher evidence and keyword rules (dry-run by default)';

    private const STD = [0, 5, 12, 18, 28];

    /** [regex on lowercase name, 4-digit HSN, default GST %, group label] first match wins */
    private function rules(): array
    {
        return [
            // services & non-goods first
            ['/printing|laser engraving|warranty|e-linses|tally prime|anti ?virus|oneassist|^freight$|^cartage$/', '9983', 18, 'Services'],
            // tea / coffee before bags
            ['/tea bag|herbal tea|green tea|\btea\b|coffee|nescafe|typoo/', '0902', 5, 'Tea/coffee'],
            // personal care
            ['/soap/', '3401', 18, 'Soap'],
            ['/hand ?wash|handwash|shaving bar/', '3401', 18, 'Soap'],
            ['/toothpaste|tooth paste|dant manjan|colgate|promise tooth|sfg-?\s?tooth|fg-?\s?tooth|toothpaste tablet/', '3306', 18, 'Oral care'],
            ['/toothbrush|tooth brush|tongue cleaner|toung cleaner|tounge cleaner|meswak|ear ?buds|earbuds/', '9603', 18, 'Brush/bamboo'],
            ['/comb\b|combs?\(/', '9615', 18, 'Comb'],
            ['/razor|shaving kit/', '8212', 18, 'Razor'],
            ['/hair oil|almond hair|shampoo|conditioner|hair color|ubtan\/face|face ?wash|ubtan powder|multani mitti powder|shikakai|triphala|reetha/', '3305', 18, 'Hair/personal care'],
            ['/mo?i?st[uo]ri[sz]er|lotion|facemask|face mask/', '3304', 18, 'Hair/personal care'],
            ['/itra|attar|odonil|room fresh|aloe e ?cell|oxy ?ga?u?rd|oxygengaurd|vanity kit|venity kit|pc rose/', '3307', 18, 'Perfumery'],
            ['/citronella|jasmine oil|sandal wood oil|green apple oil|gulab jal|rose water/', '3301', 18, 'Essential oil'],
            ['/dhoop|dhup|sambrani|hawan samagri|kumkum|gulal|agarbatti|cowdung (?:chandan|dhoop)/', '3307', 12, 'Puja/dhoop'],
            ['/kapoor|camphor/', '2914', 18, 'Camphor'],
            // dairy / oils / honey
            ['/ghee/', '0405', 5, 'Ghee/dairy'],
            ['/butter(?! ?sc)/', '0405', 12, 'Ghee/dairy'],
            ['/everyday dc/', '0402', 5, 'Dairy'],
            ['/honey/', '0409', 5, 'Honey'],
            ['/mustard oil/', '1514', 5, 'Edible oil'],
            ['/sesame oil|seasame oil/', '1515', 5, 'Edible oil'],
            ['/coconut oil/', '1513', 5, 'Edible oil'],
            ['/groundnut oil/', '1508', 5, 'Edible oil'],
            // cleaning / chemicals / paint
            ['/vedic (?:suraksha|emulsion|primer)|surak?sha ?ka?wach|kawach|emulsion|primer/', '3209', 18, 'Paint'],
            ['/wall putty|putty/', '3214', 18, 'Paint'],
            ['/surface|bathroom cleaner|toilet cleaner|laundry wash|washing cleaner|dishwashing|\btaski\b|\bsani fresh|pheneyl|phenyl|acid hcl|disinfectant/', '3402', 18, 'Cleaning'],
            ['/mosquito|napthalene|naphthalene/', '3808', 18, 'Pesticide/repellent'],
            ['/gau mutra|snjeevani ras|\bark\b/', '3004', 12, 'Ayurvedic'],
            // cleaning tools
            ['/\bmop\b|broom|jhadu|wiper|squeez|duster|dust ?pan|pocha|brush|dustabin|cob web|micro ?fib/', '9603', 18, 'Cleaning tools'],
            // electronics
            ['/laptop bag|backpack/', '4202', 18, 'Bags'],
            ['/laptop|ideapad|keyboard|mouse(?! pad)|combo/', '8471', 18, 'Electronics'],
            ['/printer|cartidge|cartridge/', '8443', 18, 'Electronics'],
            ['/\bssd\b|ssd casing|caddy/', '8523', 18, 'Electronics'],
            ['/processor|intelo|core i/', '8542', 18, 'Electronics'],
            ['/\bram\b|dimm|mother ?board|graphic|geonix/', '8473', 18, 'Electronics'],
            ['/\bled ips|acer led|monitor/', '8528', 18, 'Electronics'],
            ['/samsung|anti radiation chip|smartdukaan/', '8517', 18, 'Electronics'],
            ['/speaker/', '8518', 18, 'Electronics'],
            ['/scooter/', '8711', 18, 'Electronics'],
            ['/deep freezer/', '8418', 18, 'Electronics'],
            ['/daikin|\bsac\b/', '8415', 18, 'Electronics'],
            ['/heater/', '8516', 18, 'Electronics'],
            ['/power sup|usb adapter|wireless-n|fingers|ups\b|zeb power|cabinet|electronic machine|label printer|kores/', '8504', 18, 'Electronics'],
            ['/zeb computer mouse pad|mouse pad/', '3926', 18, 'Electronics'],
            ['/led blub|led bulb|hanging light|mashroom/', '9405', 18, 'Lighting'],
            // hardware
            ['/aluminium foil/', '7607', 18, 'Hardware'],
            ['/hanger|bracket|curtain pipe|screw|pvc sleev|pipe\b|aluminium handle|aluminium pole/', '7326', 18, 'Hardware'],
            ['/ss utensils|utensil|steel wiper|tea container|s s tea/', '7323', 12, 'Utensils'],
            // bamboo/wooden
            ['/bamboo|wheatstraw|wheat straw|neem wood|wooden|cutlery|lampshade|stirer|toothpick|paper straw|mug\b|bamboo straw/', '4421', 18, 'Bamboo/wooden'],
            // glass / packaging
            ['/milk bott|botlle|glass bottle|oil bottle|honey jar|itc square jar|\bjar\b|bottle\b/', '7010', 18, 'Glass/packaging'],
            ['/hotel (?:dental |shaving )?kit|kit box|gift box|deepawali|dental kit|medi kit|seminar kit|conference kit|d kit|\bkit\b/', '4819', 18, 'Kits/boxes'],
            ['/boxes|box\b|lug cap|round container|hdp can|container/', '3923', 18, 'Packaging'],
            ['/garbage bag|biodegradable (?:pouch|carry)|laundry bag|pouch/', '3923', 18, 'Bags'],
            ['/jute|canvas|cotton (?:bag|kalava|carry)|fabric|potli|potlis/', '6305', 12, 'Jute/fabric goods'],
            ['/sleeper|slipper/', '6405', 18, 'Footwear'],
            ['/door mat|doormat/', '5705', 12, 'Mats'],
            ['/bed ?sheet/', '6302', 5, 'Textile'],
            ['/coir|scrub pad|loofah|loofaha|loofa/', '5601', 5, 'Coir/loofah'],
            ['/glove|medical|shower cap|bouffant|disposable/', '3926', 18, 'Medical/disposable'],
            ['/wc band|brown king lace|\blace\b/', '3924', 18, 'Misc'],
            // paper
            ['/paper napkin|napkin|tissue|toilet paper|kitchen towel/', '4818', 18, 'Tissue paper'],
            ['/wra[p]+ing paper|wraping/', '4823', 18, 'Paper stationery'],
            ['/pen stand|mobile stand|footrest/', '9701', 12, 'Handicraft/puja'],
            ['/pencil|\bpen\b|pen with|seed pen|paper seed|click pen/', '9609', 18, 'Stationery'],
            ['/notepad|note book|notebook|diary|diaries|dairies|copier|envelop|visiting card|business card|id card|folder|\bfile\b|conference|coaster|butter paper|paper (?:bag|cover)|handmade paper|a5 spiral|wiro|tote bag sample|paper bag sample|convacation|docket|browny|punchless|coibra|ring thick|carry bag|carry bags/', '4820', 18, 'Paper stationery'],
            ['/\bbag\b|bags\b/', '4202', 18, 'Bags'],
            // food
            ['/sharbat|sherbet|syrup|thandai|aam pana|bael|bela |beal /', '2106', 18, 'Sharbat/syrup'],
            ['/vinegar|sirka/', '2209', 18, 'Vinegar'],
            ['/kulfi|chuski|\bice\b/', '2105', 18, 'Ice cream/chuski'],
            ['/murabba|pickle|pickel|achar|chutni|\bjam\b|papad/', '2001', 5, 'Pickle/preserves'],
            ['/mishri|khand\b|gur khand|khandsari|dhaga mishri/', '1701', 5, 'Sugar/jaggery'],
            ['/candy|laddu|ladoo|laddo|toffee|chikki|gulkand|mukhwas|goli|churan|anardana|anar vati|vati\b|hajma|imli|kachi kari/', '1704', 18, 'Candy/sweets'],
            ['/noodles|pasta|vermicelli|sevaiyan|noodle/', '1902', 5, 'Noodles/pasta'],
            ['/cookies|biscuit|pancake/', '1905', 18, 'Bakery'],
            ['/namkeen|bhujiya|chivda|chidwa|puff|samosa|nutri bar|healthy nutri/', '2106', 12, 'Snacks'],
            ['/poha|flaxes|flakes/', '1904', 18, 'Cereal products'],
            ['/premix|\bmix\b|mix\(|kichdi|khichdi|dosa|idli|upma/', '1901', 18, 'Mixes/premix'],
            ['/sugar|sachet|\bgur\b|shakar|shakhar|saunth gur|khandsari|kali mirch gur/', '1701', 5, 'Sugar/jaggery'],
            ['/salt|namak(?! rice)/', '2501', 0, 'Salt'],
            ['/rice\b|basmati|red rice|kala namak rice/', '1006', 0, 'Rice'],
            ['/atta|flour|besan|sattu|dalia/', '1102', 0, 'Flour'],
            ['/millet|jowar|ragi|barnyard|foxtail|kodo|kuttu|singhare|bajra|kangni|browntop/', '1008', 0, 'Millets'],
            ['/kala bh?tt|bhatt|bhtt/', '1201', 0, 'Pulses'],
            ['/daal|\bdal\b|chana|chane|masoor|moong|urad|rajma|lobia|lobiya|kulath|\bmoth\b|arhar|chilka|dhuli|sabut(?! jeera)|safed chan|kale chan|bhuna chana|harshal/', '0713', 0, 'Pulses'],
            ['/haldi|turmeric/', '0910', 5, 'Spices'],
            ['/dhaniya|coriander|jeera|cumin|saunf|fennel|ajwain|bishop|kalonji|kalongi|black cumin|methi|kasuri|mustard seed|starphool|star anise|javetri|mace|jayfal|netmeg|netmag|nutmeg|shah jeera/', '0909', 5, 'Spices'],
            ['/mirch|chilli|chili|kashmiri red|pepper/', '0904', 5, 'Spices'],
            ['/elaichi|cardamom/', '0908', 5, 'Spices'],
            ['/laung|clove/', '0907', 5, 'Spices'],
            ['/dalchini|cinnamon|tej pata|bay leaf/', '0906', 5, 'Spices'],
            ['/heeng|hing|asafoetida/', '0910', 5, 'Spices'],
            ['/masala|chaat|chole|biryani|marinade|sabji|veg stuff|pav bhaji|paneer masala|mutton|biji special|shahi biji/', '0910', 5, 'Spices'],
            ['/bhang|seeds/', '1207', 5, 'Seeds'],
            ['/amla|chandan powder|powder/', '2106', 12, 'Food preparation'],
            // handicraft
            ['/cowdung|mitti|diya|murti|shankh|chidiya|swastik|ganesh|cotton kalava|kalava|\bmala\b|keychain|keyring|footrest|mobile stand|pen stand|shubh labh|adhiyogi|l\.?g\.? ?murti/', '9701', 12, 'Handicraft/puja'],
            ['/accessories set|otis guard|blender/', '8509', 18, 'Appliances'],
            ['/swm05|storia/', '8516', 18, 'Appliances'],
        ];
    }

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        $company = Company::whereRaw('UPPER(gstin)=?', [strtoupper($this->option('gstin'))])->firstOrFail();
        $bid = $company->id;

        // (1) evidence: latest effective rate on single-item vouchers
        $ev = [];
        foreach (['sales' => ['sales_vouchers', 'sales_items', 'sales_voucher_id', 'invoice_date'], 'purchase' => ['purchase_vouchers', 'purchase_items', 'purchase_voucher_id', 'purchase_date']] as $kind => [$vt, $it, $fk, $dc]) {
            $rows = DB::select("select i.product_id, v.$dc d, v.taxable_amount t, (v.cgst_amount+v.sgst_amount+v.igst_amount) tax
                from $vt v join $it i on i.$fk=v.id join products p on p.id=i.product_id
                where v.business_id=? and p.product_type='goods' and v.taxable_amount>0
                  and (select count(*) from $it x where x.$fk=v.id)=1", [$bid]);
            foreach ($rows as $r) {
                $eff = $r->tax * 100 / $r->t;
                foreach (self::STD as $s) if (abs($eff - $s) <= 0.3) { $ev[$r->product_id][] = [$r->d, (float) $s]; break; }
            }
        }
        $latest = [];
        foreach ($ev as $pid => $list) { usort($list, fn ($a, $b) => strcmp($b[0], $a[0])); $latest[$pid] = [$list[0][1], $list[0][0], count($list)]; }

        $products = Product::where('business_id', $bid)->get();
        $family = [];
        foreach ($products as $p) {
            if (isset($latest[$p->id])) {
                $k = $this->family($p->name);
                if (!isset($family[$k]) || $latest[$p->id][1] > $family[$k][1]) $family[$k] = [$latest[$p->id][0], $latest[$p->id][1]];
            }
        }

        $rules = $this->rules();
        $hsnRows = DB::table('hsn_masters')->whereNull('business_id')->where('code_type', 'HSN')->whereRaw('char_length(hsn_code)=4')->pluck('id', 'hsn_code')->all();
        $out = []; $stats = ['evidence' => 0, 'family' => 0, 'rule' => 0, 'no_hsn' => 0, 'no_rate' => 0];
        foreach ($products as $p) {
            $lname = mb_strtolower($p->name);
            $hsn = null; $ruleRate = null; $group = null;
            foreach ($rules as [$re, $code, $rate, $label]) {
                if (preg_match($re, $lname)) { $hsn = $code; $ruleRate = $rate; $group = $label; break; }
            }
            if ($p->product_type === 'service') { $hsn = '9965'; $group = 'Services'; $ruleRate = 18; }
            [$rate, $src] = [null, 'none'];
            if (isset($latest[$p->id])) { $rate = $latest[$p->id][0]; $src = 'own vouchers (latest ' . $latest[$p->id][1] . ', n=' . $latest[$p->id][2] . ')'; $stats['evidence']++; }
            elseif (isset($family[$this->family($p->name)])) { $rate = $family[$this->family($p->name)][0]; $src = 'same product family (latest ' . $family[$this->family($p->name)][1] . ')'; $stats['family']++; }
            elseif ($ruleRate !== null) { $rate = (float) $ruleRate; $src = 'keyword rule default (UNVERIFIED)'; $stats['rule']++; }
            if (!$hsn) $stats['no_hsn']++;
            if ($rate === null) $stats['no_rate']++;
            $out[] = ['p' => $p, 'hsn' => $hsn, 'rate' => $rate, 'src' => $src, 'group' => $group, 'hsn_id' => $hsn ? ($hsnRows[$hsn] ?? null) : null];
        }

        $this->info('Products: ' . count($out) . ' | rate from own vouchers: ' . $stats['evidence'] . ' | from family: ' . $stats['family'] . ' | rule default only: ' . $stats['rule'] . ' | no HSN: ' . $stats['no_hsn'] . ' | no rate: ' . $stats['no_rate']);
        $byGroup = collect($out)->groupBy(fn ($r) => $r['group'] ?: '(unmapped)')->map->count()->sortDesc();
        foreach ($byGroup as $g => $n) $this->line(sprintf('  %-26s %d', $g, $n));
        $missing = collect($out)->filter(fn ($r) => !$r['hsn'])->take(60)->map(fn ($r) => $r['p']->name)->all();
        if ($missing) $this->line('Unmapped sample: ' . implode(' | ', $missing));

        if ($csv = $this->option('csv')) {
            $h = fopen($csv, 'w');
            fputcsv($h, ['Product', 'HSN (4-digit)', 'GST %', 'Rate basis', 'Group', 'Verify?']);
            foreach ($out as $r) fputcsv($h, [$r['p']->name, $r['hsn'], $r['rate'], $r['src'], $r['group'], str_contains($r['src'], 'UNVERIFIED') || !$r['hsn'] || $r['rate'] === null ? 'YES' : (str_starts_with($r['src'], 'own') ? 'no' : 'check')]);
            fclose($h);
            $this->line("Review CSV: $csv");
        }

        if (!$this->option('commit')) { $this->info('DRY RUN: nothing written.'); return self::SUCCESS; }

        DB::transaction(function () use ($out) {
            foreach ($out as $r) {
                if (!$r['hsn'] && $r['rate'] === null) continue;
                $upd = ['tax_source' => 'master_suggested'];
                if ($r['hsn']) { $upd['hsn_code'] = $r['hsn']; $upd['hsn'] = $r['hsn']; if ($r['hsn_id']) { $upd['hsn_id'] = $r['hsn_id']; $upd['hsn_master_id'] = $r['hsn_id']; } }
                if ($r['rate'] !== null) { $upd['gst_rate'] = $r['rate']; $upd['taxability'] = $r['rate'] > 0 ? 'taxable' : 'nil_rated'; }
                Product::where('id', $r['p']->id)->update($upd);
            }
        });
        $this->info('COMMITTED.');
        return self::SUCCESS;
    }

    private function family(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/\([^)]*\)/u', ' ', $n);
        $n = preg_replace('/\d+(\.\d+)?\s*(gm|g|kg|ml|ltr|l|pc|pcs|ply|cc|mm|w|"|\'\')?/u', ' ', $n);
        $n = preg_replace('/\b(refill|refil|new|big|small|s|n|r|p|t|tp|plain|regular)\b/u', ' ', $n);
        return trim(preg_replace('/[^a-z]+/u', ' ', $n));
    }
}
